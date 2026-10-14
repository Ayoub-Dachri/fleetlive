# Architecture

FleetLive is deliberately plain Laravel: controllers, a form request, one queued job, Eloquent models and two small services. There are no repositories, DTOs or event buses. Every piece below can be explained in a sentence.

- [Data flow](#data-flow)
- [Database design](#database-design)
- [Ingestion](#ingestion)
- [Queue processing](#queue-processing)
- [Live updates and the polling cursor](#live-updates-and-the-polling-cursor)
- [Redis cache](#redis-cache)
- [Frontend](#frontend)
- [Design decisions](#design-decisions)
- [Scaling horizontally](#scaling-horizontally)

## Data flow

```
                ┌──────────────────────────── write path ────────────────────────────┐

 tracker /      POST /api/gps/ingest       Redis queue          queue:work
 simulator ───▶ GpsIngestController ─────▶ StoreGpsEventJob ──▶ ① gps_events            (insert, dedup)
                validate → normalize        (one job per          ② vehicles             (metadata, last_seen_at)
                → dispatch → 202            request)              ③ vehicle_current_positions (upsert if newer)
                                                                  ④ Redis vehicle:position:{id}

                ┌──────────────────────────── read path ─────────────────────────────┐

 dashboard      GET /api/gps/vehicles               (once: all vehicles, from MySQL)
 (React)  ───▶  GET /api/gps/vehicles/updates?since (every 2 s: changed ids from MySQL index,
                                                     payloads from Redis, MySQL fallback)
                GET /api/gps/vehicles/{id}/events   (feed: last 20 events of selected vehicle)
```

## Database design

There are three tables, plus Laravel's `failed_jobs` table for jobs that failed every retry.

```
vehicles                     vehicle_current_positions          gps_events
─────────                    ─────────────────────────          ──────────
id (PK)              ◀───┐   vehicle_id (PK, FK) ───────┐       id (PK)
ident (unique)           │   latitude, longitude        │       vehicle_id (FK) ──▶ vehicles
device_id                │   altitude, direction, speed │       ident
device_name              │   hdop, satellites           │       latitude, longitude, speed, direction
device_type_id           │   gnss_valid                 │       ignition/movement status
channel_id               │   ignition/movement status   │       battery / external power voltage
protocol_id              │   battery / ext. power       │       gsm_signal_level, mileage
last_seen_at             │   gsm_signal_level, mileage  │       raw_payload (JSON)
created_at, updated_at   │   device/server timestamps   │       device/server timestamps
                         │   message_timestamp          │       message_timestamp
                         │   updated_at (µs, indexed) ◀─┘       received_at (indexed)
                         └──────────────────────────────        UNIQUE (vehicle_id, message_timestamp)
```

| Table | Rows | Purpose |
| --- | --- | --- |
| `vehicles` | one per tracker | Identity and device metadata. `ident` (the IMEI) is the natural key from the payload. |
| `vehicle_current_positions` | **exactly one per vehicle** | The latest known state. This is what the live map reads. |
| `gps_events` | one per message, append-only | Full history, including the original payload in `raw_payload`. |

Index choices:

- **`vehicle_current_positions.updated_at`** (microsecond precision) is the polling cursor: `WHERE updated_at > ?`. In InnoDB a secondary index also stores the primary key (`vehicle_id`), so the query that finds changed vehicles reads only the index.
- **`gps_events (vehicle_id, message_timestamp)` UNIQUE** serves "latest 20 events for a vehicle, newest first" and also prevents duplicates (see below).
- **`gps_events.received_at`** supports time-based queries and cleanup, for example archiving events older than N days.

Every foreign key uses `ON DELETE CASCADE`, so deleting a vehicle removes its position and history.

## Ingestion

`POST /api/gps/ingest` → [`GpsIngestController`](../app/Http/Controllers/GpsIngestController.php). The controller does four things and **never touches MySQL**:

1. **Validate.** [`GpsIngestRequest`](../app/Http/Requests/GpsIngestRequest.php) accepts a single object or `{"messages": [...]}`. Tracker keys contain literal dots (`position.latitude`), so `validationData()` expands them with `Arr::undot()`. That lets the rules use Laravel's normal dot notation (`messages.*.position.latitude`).
2. **Normalize.** [`GpsPayloadNormalizer`](../app/Services/GpsPayloadNormalizer.php) turns each flat payload into a typed, structured array grouped by where each value is stored:
   ```php
   [
       'ident'     => '111115555599999',
       'vehicle'   => ['device_id' => 182083, 'device_name' => 'Vehicle', ...],  // → vehicles
       'position'  => ['latitude' => 43.95, 'longitude' => 37.66, ...],         // → only applied with a valid fix
       'telemetry' => ['gnss_valid' => true, 'battery_voltage' => 3.938, ...],  // → always applied
       'device_timestamp' => 1678346071,
       'server_timestamp' => 1678346072.79,
       'received_at' => '2026-10-14 22:58:21',
       'raw' => [ /* original payload, stored as JSON */ ],
   ]
   ```
3. **Dispatch** one `StoreGpsEventJob` holding all messages of the request.
4. **Respond** `202 Accepted` with `{"accepted": N, "message": "GPS data queued successfully"}`.

## Queue processing

[`StoreGpsEventJob`](../app/Jobs/StoreGpsEventJob.php) groups the messages by `ident`, sorts each group oldest first, and for each vehicle:

1. **Find or create the vehicle.** `Vehicle::firstOrCreate(['ident' => …])` is race-safe: if two workers insert the same new ident at once, the loser catches the unique violation and selects the row.
2. **Open a transaction and lock the vehicle row** (`SELECT … FOR UPDATE`). Two workers processing the same vehicle now run one after the other, so an older batch can never overwrite a newer position.
3. **Bulk-insert the events** with `INSERT IGNORE`. A message already stored (same vehicle and same device timestamp) is skipped by the unique index. No dedup table is needed.
4. **Update the vehicle's metadata** (only non-null values) and set `last_seen_at = now()`.
5. **Apply the messages to the current position, oldest first**, skipping any that aren't newer than the stored `device_timestamp`:
   - Valid fix: update position *and* telemetry.
   - Invalid fix (`position.valid: false`): update telemetry and `gnss_valid = false`, but **keep the last coordinates**. The event is still stored, and the vehicle doesn't jump on the map.
   - A vehicle that has never had a valid fix gets no position row yet, because there is nothing to put on the map.
6. **Commit.** Then write the live payload to Redis. Writing *after* the commit means Redis never holds a position that MySQL rolled back.

Failure handling: the job is tried 3 times with backoff of 1 s, 5 s and 15 s. The transaction itself retries deadlocks 3 times. A job that fails every attempt is stored in `failed_jobs` (`php artisan queue:failed`, `php artisan queue:retry all`).

## Live updates and the polling cursor

[`useVehiclePolling`](../resources/js/hooks/useVehiclePolling.js) holds all the polling logic in one hook:

```
mount ─▶ GET /api/gps/vehicles ─▶ cursor = server_time
          │
          └▶ every 2 s (10 s when the tab is hidden):
               GET /api/gps/vehicles/updates?since=cursor ─▶ merge changes, cursor = server_time
```

- **The cursor is the server's clock** (`server_time`, read *before* the query), never the browser's. A wrong clock in the browser can't make it skip or repeat updates.
- **Overlap window.** The server looks 2 seconds behind the cursor (`CURSOR_OVERLAP_SECONDS`). A position written by a transaction that commits just after a poll ran would otherwise sit behind the cursor and never be sent. The client drops entries whose `updated_at` it already has, so the overlap causes no extra re-renders or animations.
- **AbortController.** Each request cancels the previous in-flight one and times out after 8 s.
- **Visibility.** When the tab becomes visible again, it polls immediately instead of waiting out the 10 s interval.
- **Failures.** One failure shows **Reconnecting…**. Five in a row stop polling and show **Disconnected** with a Reconnect button, which reloads the full list and resumes.
- **Clock offset.** The hook also returns `server_time − Date.now()`. The UI uses it to compute "12s ago" and the 60-second Offline threshold on the server's clock.

## Redis cache

[`VehiclePositionCache`](../app/Services/VehiclePositionCache.php) stores the exact JSON the map needs under `vehicle:position:{id}` (with Laravel's prefixes, the actual Redis key is `fleetlive-database-fleetlive-cache-vehicle:position:1` in Redis database 1). The TTL is 24 h.

Reads on `/updates`:

1. MySQL: `SELECT vehicle_id, updated_at FROM vehicle_current_positions WHERE updated_at > ?` (index only).
2. Redis: one `MGET` for those ids.
3. For any entry that is **missing, or older than the MySQL `updated_at`**, load it from MySQL and write it back to Redis.

MySQL stays the source of truth: Redis can be flushed or restarted at any time without data loss.

## Frontend

The app has one Inertia page, [`Dashboard.jsx`](../resources/js/Pages/Dashboard.jsx). It loads its data from the JSON API so it can show loading skeletons and keep polling on its own.

| File | Responsibility |
| --- | --- |
| `hooks/useVehiclePolling.js` | Initial load, polling loop, merge, connection state |
| `hooks/useVehicleEvents.js` | Fetches the selected vehicle's last 20 events whenever its `updated_at` changes |
| `hooks/useNow.js` | A ticking clock (1 s) for relative times and staleness |
| `hooks/useAnimatedNumber.js` | Counters in the header that roll instead of jumping |
| `lib/vehicleStatus.js` | `offline` (> 60 s) → `invalid` (no fix) → `moving` → `idle` |
| `lib/animateMarker.js` | `glideMarkerTo()`: a requestAnimationFrame tween over 1.5 s with ease-in-out. `rotationTowards()`: turns the arrow the short way round (350° → 10° turns 20°, not 340°). |
| `components/LiveMap.jsx` | Drives Leaflet imperatively: creates, glides, restyles and removes markers; popups; legend; empty and loading overlays |
| `components/VehicleList.jsx` | Vehicle cards with status, metrics and the Focus button |
| `components/ActivityFeed.jsx` | Latest messages. New rows slide in (React keys by event id, so only new rows mount and animate). |
| `components/Header.jsx` | Brand, stats, Live / Reconnecting / Disconnected indicator |

Markers are Leaflet `divIcon`s styled in `resources/css/app.css`. The colour comes from a `data-status` attribute, and the arrow is rotated with a CSS transition. New markers pop in with a keyframe animation, and moving vehicles have a pulsing halo. Users with `prefers-reduced-motion` get no animations. The OpenStreetMap tiles are darkened with a CSS filter so they match the dark UI.

## Design decisions

**Why ingestion is queued.** Trackers send on a fixed schedule and resend when a request is slow or fails. Pushing to Redis keeps the response time independent of MySQL. A database slowdown or a traffic spike then becomes a backlog in the queue instead of failed requests, and failed jobs are retried instead of lost.

**Why current positions and events are separate tables.** History grows without limit, while the live map needs only one row per vehicle. Searching millions of events for "the latest per vehicle" on every poll would be slow. Two tables keep the hot table tiny, and history can be partitioned or archived separately.

**Why polling uses a `since` cursor.** Each poll returns only what changed, so an idle fleet costs an empty response. Using the server's clock plus a small overlap window keeps it correct even with clock skew and late-committing transactions.

**Why Redis holds the latest positions.** It replaces a join per vehicle on every poll with one `MGET`. Because entries are validated against MySQL `updated_at`, the cache can't serve stale data and can be dropped at any time.

**Why HTTP polling, not WebSockets.** Polling works through any proxy or load balancer, needs no extra server process, and is easy to reason about. At a 2 s interval it is effectively real-time for vehicle tracking.

**Why dedup uses a unique index.** A tracker cannot send two different messages for the same vehicle in the same second, so `(vehicle_id, message_timestamp)` identifies a message. `INSERT IGNORE` drops retransmissions without a separate dedup table or a hash column.

## Scaling horizontally

| Layer | How it scales |
| --- | --- |
| Web servers | No state: no auth, cookie sessions, cache in Redis. Add servers behind any load balancer. |
| Queue workers | Run more `queue:work` processes on any number of machines. Per-vehicle row locks keep them consistent. |
| Ingest volume | Gateways should batch (`messages[]`, up to 500). One job then makes one bulk insert per vehicle. |
| Reads | Changed ids come from a covering index and payloads from Redis, so MySQL work per poll stays tiny. |

Next steps, if traffic grows:

- send read endpoints to a MySQL read replica;
- partition `gps_events` by month and archive old partitions;
- cache the `/updates` response for about 1 s, so that many dashboards share one query;
- give the queue its own Redis instance.

# Prompts used to build FleetLive

FleetLive was built with an AI coding assistant (Claude Code). This page records the prompts that defined the project: the original specification word for word, then the documentation request.

- [1. Original specification](#1-original-specification)
- [2. Documentation](#2-documentation)

---

## 1. Original specification

The full brief the project was generated from:

````text
Build a complete vehicle GPS live-tracking web application called "FleetLive".

IMPORTANT PROJECT PHILOSOPHY:
- Build a clean, simple, readable, production-quality Laravel application.
- Do NOT over-engineer. Avoid unnecessary design patterns, interfaces, repositories, DTOs, event sourcing, CQRS, microservices, or excessive abstraction.
- Keep the architecture easy for a developer to explain in a technical interview.
- Use clear folder structure, meaningful class names, concise methods, and helpful comments only where needed.
- Do not generate placeholder modules, fake enterprise features, or unused files.
- Every feature must be functional and connected to the UI.
- Prioritize: clean database design, clean API design, readable code, good UI/UX, smooth animations, and scalability-conscious decisions.

TECH STACK:
- Laravel 11 or latest stable Laravel.
- Inertia.js with React.
- Tailwind CSS.
- MySQL.
- Redis for cache and queue backend.
- Leaflet + OpenStreetMap for the live map. Do not use Google Maps.
- Use Laravel queues for GPS ingestion processing.
- Use HTTP polling for real-time updates. Do NOT use WebSockets, Pusher, Laravel Echo, Soketi, or SSE.

CORE MISSION:
Create a public fleet-tracking dashboard that receives GPS telemetry from vehicles and displays their real-time position, movement, status, and latest messages on a live map.

There is no authentication. The app is a public demonstration dashboard.

======================================================================
1. GPS INGEST API
======================================================================

Create this endpoint:

POST /api/gps/ingest

It must accept either:

A) A single GPS telemetry object:

{
  "battery.voltage": 3.938,
  "channel.id": 429,
  "device.id": 182083,
  "device.name": "Vehicle",
  "device.type.id": 744,
  "engine.ignition.status": false,
  "event.priority.enum": 0,
  "external.powersource.voltage": 12.64,
  "gnss.state.enum": 1,
  "gnss.status": true,
  "gsm.cellid": 11511,
  "gsm.lac": 108,
  "gsm.mcc": 257,
  "gsm.mnc": 1,
  "gsm.operator.code": "25701",
  "gsm.signal.level": 80,
  "ident": "111115555599999",
  "movement.status": false,
  "peer": "185.213.2.10:59924",
  "position.altitude": 244,
  "position.direction": 281,
  "position.hdop": 0.3,
  "position.latitude": 43.955218,
  "position.longitude": 37.661918,
  "position.satellites": 15,
  "position.speed": 0,
  "position.valid": true,
  "protocol.id": 14,
  "server.timestamp": 1678346072.792816,
  "timestamp": 1678346071,
  "vehicle.mileage": 36713.703
}

OR:

B) An array of telemetry objects:

{
  "messages": [
    { ...gps payload... },
    { ...gps payload... }
  ]
}

Requirements:
- Normalize dotted keys into a structured array.
- Required fields: ident, position.latitude, position.longitude, timestamp.
- If position.valid is false, still store the event but do not update the live map position.
- Return HTTP 202 Accepted immediately.
- Dispatch processing to a queued job. Do not write to MySQL synchronously inside the controller.
- Return a simple JSON response:

{
  "accepted": 1,
  "message": "GPS data queued successfully"
}

For an array:

{
  "accepted": 5,
  "message": "GPS data queued successfully"
}

- Add basic validation and return clear 422 validation errors.
- Add simple rate limiting suitable for a public ingest endpoint.
- Keep the controller thin: validate, normalize, dispatch, respond.

======================================================================
2. DATABASE DESIGN
======================================================================

Use exactly three main tables. Keep the schema simple and scalable.

1) vehicles

Fields:
- id
- ident, unique
- device_id, nullable
- device_name, nullable
- device_type_id, nullable
- channel_id, nullable
- protocol_id, nullable
- last_seen_at, nullable datetime
- created_at
- updated_at

2) vehicle_current_positions

This table stores only the latest known state of each vehicle.

Fields:
- vehicle_id, primary key, foreign key to vehicles
- latitude decimal(10,7)
- longitude decimal(10,7)
- altitude nullable decimal(8,2)
- direction nullable smallint
- speed nullable decimal(8,2)
- hdop nullable decimal(5,2)
- satellites nullable smallint
- gnss_valid boolean
- ignition_status nullable boolean
- movement_status nullable boolean
- battery_voltage nullable decimal(6,3)
- external_power_voltage nullable decimal(6,3)
- gsm_signal_level nullable smallint
- mileage nullable decimal(12,3)
- device_timestamp nullable bigint
- server_timestamp nullable decimal(16,6)
- message_timestamp datetime
- updated_at timestamp

Add an index on updated_at because the frontend polls for vehicles updated after a timestamp.

3) gps_events

This table stores every received GPS message as immutable history.

Fields:
- id
- vehicle_id foreign key
- ident
- latitude nullable decimal(10,7)
- longitude nullable decimal(10,7)
- speed nullable decimal(8,2)
- direction nullable smallint
- ignition_status nullable boolean
- movement_status nullable boolean
- battery_voltage nullable decimal(6,3)
- external_power_voltage nullable decimal(6,3)
- gsm_signal_level nullable smallint
- mileage nullable decimal(12,3)
- raw_payload JSON
- device_timestamp nullable bigint
- server_timestamp nullable decimal(16,6)
- message_timestamp datetime
- received_at datetime

Indexes:
- (vehicle_id, message_timestamp)
- received_at

Use foreign keys and cascade deletes.

======================================================================
3. QUEUE PROCESSING
======================================================================

Create one queued job:

StoreGpsEventJob

It must:
1. Find or create the vehicle using ident.
2. Insert a row into gps_events with the normalized values and raw payload.
3. Upsert vehicle_current_positions with the latest telemetry.
4. Update the vehicle last_seen_at.
5. Cache the latest vehicle position in Redis using a key such as:

vehicle:position:{vehicle_id}

The Redis cache should contain the data needed by the live map endpoint.

Use database transactions where appropriate.
Use batch-friendly, efficient queries.
Prevent obvious duplicate events using ident + device timestamp or a deterministic payload hash.
Do not create a separate deduplication table unless truly necessary.

======================================================================
4. LIVE UPDATES API
======================================================================

Create:

GET /api/gps/vehicles/updates?since={unix_timestamp}

Behavior:
- Return only vehicles whose position was updated after the provided since timestamp.
- Read latest positions from Redis first.
- Fall back to vehicle_current_positions in MySQL if Redis does not have the vehicle.
- Return the new server time so the frontend can use it as the next since value.

Response format:

{
  "server_time": 1760479200.123,
  "vehicles": [
    {
      "id": 182083,
      "ident": "111115555599999",
      "name": "Vehicle",
      "latitude": 43.955218,
      "longitude": 37.661918,
      "speed": 42.5,
      "direction": 281,
      "altitude": 244,
      "satellites": 15,
      "gnss_valid": true,
      "ignition": false,
      "moving": false,
      "battery": 3.938,
      "external_power": 12.64,
      "gsm_signal": 80,
      "mileage": 36713.703,
      "last_message_at": "2026-10-14T22:58:20.000Z",
      "updated_at": 1760479185.123
    }
  ]
}

Also create:

GET /api/gps/vehicles

Returns all vehicles with their latest position and status. This is used for the initial page load.

GET /api/gps/vehicles/{vehicle}/events

Returns the latest 20 GPS events for one vehicle, newest first. Used for the message/activity feed.

======================================================================
5. FRONTEND: INERTIA + REACT + TAILWIND
======================================================================

Build one polished dashboard page:

/

Do not create unnecessary pages. One main dashboard is enough.

Layout:

┌────────────────────────────────────────────────────────────┐
│ FleetLive                    Live status + vehicle count   │
├────────────────────────────────────────────┬───────────────┤
│                                            │ Vehicle list  │
│                 Live map                   │               │
│                                            │ - Vehicle ID  │
│         Leaflet map with markers           │ - Status      │
│                                            │ - Speed       │
│   Smoothly animated vehicle markers        │ - Battery     │
│                                            │ - Last update │
│                                            │ - Focus button│
├────────────────────────────────────────────┴───────────────┤
│ Latest GPS messages / activity feed                        │
└────────────────────────────────────────────────────────────┘

Required UI features:
- Beautiful, modern, friendly dashboard design.
- Use Tailwind CSS.
- Use a dark, premium fleet-control-center aesthetic OR a clean light dashboard. Choose one and make it consistent.
- Use rounded cards, subtle borders, soft shadows, good spacing, and readable typography.
- Add a subtle animated live indicator, such as a pulsing green dot labeled "Live".
- Show total vehicles, moving vehicles, idle vehicles, and last update time.
- Show a clear empty state when no vehicles exist yet.
- Show loading skeletons or a subtle loading indicator during first load.
- Show a "reconnecting" state if polling fails.
- Show stale vehicles differently after no update for more than 60 seconds.

Vehicle list card:
- Vehicle name or ID.
- ident.
- Status badge: Moving, Idle, Offline, or GPS Invalid.
- Speed.
- Direction.
- Battery voltage.
- External power voltage.
- Mileage.
- Last message timestamp, displayed in a human-friendly relative format such as "12s ago".
- A "Focus" button that centers and zooms the map on that vehicle.

Map:
- Leaflet with OpenStreetMap tiles.
- Custom vehicle marker with a directional arrow rotated using position.direction.
- Marker color:
  - Green: moving
  - Gray: idle
  - Red: GPS invalid or stale
- Smoothly animate marker movement from previous coordinates to new coordinates over approximately 1.5 seconds.
- Use requestAnimationFrame or a small custom animation utility. Do not add a heavy animation library just for this.
- Marker popup showing vehicle ID, speed, battery, satellites, and last update.
- When a new vehicle appears, animate its marker into view subtly.
- Add a small map legend.

Activity feed:
- Show the latest GPS messages.
- Each item should show:
  - Vehicle ID or ident.
  - Message timestamp.
  - Speed.
  - Battery.
  - Coordinates.
  - Ignition status.
- New messages should enter with a subtle fade/slide animation.
- Do not overwhelm the UI: show the latest 20 events.

Polling:
- Poll GET /api/gps/vehicles/updates?since=... every 2 seconds when the tab is visible.
- Poll every 10 seconds when the tab is hidden.
- Stop polling after several consecutive failures and show a reconnect state.
- Use the returned server_time as the next since parameter.
- Use AbortController to cancel outdated requests.
- Keep polling logic in one clear React hook, for example useVehiclePolling.
- Keep map marker animation in one clear reusable component or utility.

======================================================================
6. DEMO DATA SIMULATOR
======================================================================

Create an Artisan command:

php artisan gps:simulate

Behavior:
- If no vehicles exist, create 5 demo vehicles with realistic IDs and names.
- Every 2 seconds, generate a realistic GPS payload for each vehicle.
- Send it to POST /api/gps/ingest using Laravel HTTP client.
- Vehicles should move gradually around a realistic area, for example Casablanca, Morocco.
- Randomize speed, direction, battery voltage, external power voltage, satellites, GSM signal, ignition, and movement status.
- Occasionally make a vehicle idle or GPS-invalid to demonstrate different states.
- Make the command easy to stop with Ctrl+C.

Also create:

php artisan db:seed --class=FleetSeeder

This seeds 5 demo vehicles and a few initial GPS events so the dashboard is not empty on first load.

======================================================================
7. CODE QUALITY RULES
======================================================================

- Use Laravel form requests for validation.
- Use API Resources only if they genuinely improve readability.
- Use service classes only where they make the code clearer; do not create a service layer for every tiny task.
- Keep controllers focused and small.
- Use enums only if useful, for example VehicleStatus.
- Use clear naming: GpsPayloadNormalizer, StoreGpsEventJob, VehiclePositionController, GpsIngestController.
- Add concise README documentation.
- Include setup instructions, .env example values, migration commands, queue worker command, simulator command, and API examples.
- Include a short "Architecture" section in the README explaining:
  - Why ingestion is queued.
  - Why current positions and historical events are separated.
  - Why polling uses a since cursor.
  - Why Redis is used for latest-position reads.
  - How the app can scale horizontally.
- Do not include AI-generated filler text in the README.
- Ensure the project runs with:

composer install
npm install
npm run dev
php artisan migrate --seed
php artisan queue:work redis
php artisan gps:simulate

======================================================================
8. UI/UX QUALITY BAR
======================================================================

Make the UI feel intentionally designed, not generic.

Use:
- Smooth page and card entrance animations.
- Subtle hover effects on vehicle cards and buttons.
- A pulsing live-status indicator.
- Animated counters or subtle transitions for vehicle statistics.
- Smooth sidebar/card transitions when vehicle status changes.
- A polished custom Leaflet marker instead of a default blue pin.
- Consistent color system:
  - Green for moving/healthy.
  - Amber for idle/warning.
  - Red for invalid/offline.
  - Neutral grays for secondary information.
- Good mobile responsiveness.
- Accessible contrast and readable font sizes.
- No unnecessary dashboards, settings pages, user management, roles, or fake admin panels.

======================================================================
9. FINAL DELIVERABLE
======================================================================

Deliver:
1. Complete Laravel + Inertia React project.
2. Migrations and seeders.
3. Models, controllers, form requests, jobs, routes.
4. React pages, components, hooks, and Tailwind styling.
5. Leaflet map integration.
6. GPS simulator command.
7. README with setup, API documentation, architecture explanation, and scalability notes.
8. A short final summary listing:
   - Files created.
   - Database tables.
   - API endpoints.
   - How polling works.
   - How the queue processing works.
   - How to demo the app.

Do not create complexity that cannot be justified in a short technical explanation.
The final result should look like a focused, senior-developer-built MVP: simple architecture, clean code, polished UI, smooth animations, and scalable ingestion design.
````

**Result:** the full application. The assistant made a few decisions the brief left open:

- **Vehicle `id` in the API.** The API returns the internal vehicle id (used in the events URL), plus a separate `device_id`.
- **Idle colour.** The idle marker is gray, as the map section asks, while the Idle badge is amber, as the colour-system section asks.
- **InnoDB.** It is forced in `config/database.php`, because the local MySQL defaulted to MyISAM.
- **`failed_jobs`.** This table is kept next to the three main tables, so failed jobs can be inspected and retried.

---

## 2. Documentation

> Make good detailed documentation for this project: how it works, how to run it, and what alternatives can be used instead of Redis to avoid complexity for instant testing.

**Result:** the README plus [setup.md](setup.md), [running-without-redis.md](running-without-redis.md), [architecture.md](architecture.md) and [api.md](api.md). The "instant mode" (SQLite, file cache, sync queue: no MySQL or Redis) was tested end to end before it was documented.

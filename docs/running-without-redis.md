# Running without Redis (and without MySQL)

Redis and MySQL are what the project is designed for. For a quick local test, a demo on a laptop or a CI run, you can replace both with lighter drivers **without changing any code**. Every Redis use goes through Laravel's `Cache` and `Queue` interfaces, so only `.env` changes.

## What Redis does in FleetLive

| Role | Where it's used | Why Redis |
| --- | --- | --- |
| **Queue** | `POST /api/gps/ingest` dispatches `StoreGpsEventJob`; `queue:work` processes it | The API answers in milliseconds without touching MySQL. Spikes wait in the queue, and failed jobs are retried. |
| **Latest-position cache** | `vehicle:position:{id}`, read by `GET /api/gps/vehicles/updates` | Each poll fetches all changed vehicles with one in-memory `MGET` instead of querying MySQL. |
| **Rate-limit counters** | `throttle:gps-ingest`, `throttle:gps-read` | Counters are shared across all web servers. |

## Alternatives

### Queue: `QUEUE_CONNECTION`

| Value | Needs | Behaviour |
| --- | --- | --- |
| `redis` (default) | Redis + `queue:work redis` | Asynchronous and fast. Recommended. |
| `sync` | nothing | The job runs **inside the HTTP request**. No worker needed; good for instant testing. |
| `database` | a `jobs` table + `queue:work database` | Asynchronous, using MySQL/SQLite as the queue. Slower than Redis under heavy load, but no extra service. |

To use the `database` queue, create the jobs table once:

```bash
php artisan make:queue-table
php artisan migrate
```

### Cache: `CACHE_STORE`

| Value | Needs | Behaviour |
| --- | --- | --- |
| `redis` (default) | Redis | Shared, in memory, fastest |
| `file` | nothing | Stored under `storage/framework/cache`. Each server has its own copy. Fine for one machine. |
| `database` | a `cache` table (`php artisan make:cache-table && php artisan migrate`) | Shared between servers through the database |
| `array` | nothing | Lasts for **one request only**. Rate limits never trigger, and every poll falls back to MySQL. Use only for tests. |

The position cache is optional for correctness. If an entry is missing or older than the database row, `VehiclePositionCache` reads it from MySQL and writes it back. Any cache driver, even an empty one, therefore gives the right data.

### Database: `DB_CONNECTION`

| Value | Needs | Behaviour |
| --- | --- | --- |
| `mysql` (default) | MySQL 8+ (InnoDB) | Transactions, row locks (`SELECT … FOR UPDATE`), foreign keys |
| `sqlite` | the `pdo_sqlite` PHP extension (usually built in) | One file, zero setup. Row locks are ignored (SQLite locks the whole database on write), which is fine on one machine. |

## Ready-made profiles

### Instant mode: no services at all

```dotenv
DB_CONNECTION=sqlite
# comment out DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
CACHE_STORE=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=cookie
```

```bash
touch database/database.sqlite          # PowerShell: New-Item database/database.sqlite
php artisan config:clear
php artisan migrate --seed
php artisan serve                       # terminal 1
php artisan gps:simulate                # terminal 2
npm run dev                             # terminal 3 (or run `npm run build` once)
```

No `queue:work` is needed. This profile was tested end to end: seeding, the simulator, ingest, the updates endpoint and the events feed all work.

### MySQL without Redis

Use this when you have MySQL (WAMP, Laragon, …) but no Redis:

```dotenv
DB_CONNECTION=mysql
CACHE_STORE=file
QUEUE_CONNECTION=sync           # or "database", plus `php artisan make:queue-table && php artisan migrate`
```

With `QUEUE_CONNECTION=database`, also run `php artisan queue:work database`.

### Switching back to the full stack

```dotenv
DB_CONNECTION=mysql
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

Then run `php artisan config:clear`, restart `serve`, and start `php artisan queue:work redis`.

## What changes when you drop Redis

| Area | With Redis | Without Redis (`file` + `sync`) |
| --- | --- | --- |
| Ingest response time | A few ms, no MySQL in the request | ~20–50 ms; MySQL writes happen during the request |
| MySQL slow or down | Requests still get `202`, messages wait in the queue | Trackers get errors or timeouts and must resend |
| Traffic spikes | Absorbed by the queue | Hit MySQL directly |
| Failed processing | Retried 3 times, then kept in `failed_jobs` | The request fails with a 500 |
| Polling reads | One Redis `MGET` | File reads, with MySQL fallback |
| Rate limits with several servers | One shared counter | One counter per server |
| What you see on the dashboard | Same | Same |

**In short:** at demo scale (a handful of vehicles, one machine) you won't notice any difference. Redis matters when there are many trackers, many open dashboards and several servers.

## Common mistakes

- **Rate limiting still needs a working cache store.** If `CACHE_STORE=redis` and Redis is down, *every* API request fails, including reads. Switch the cache store too, not just the queue.
- **`sync` + `queue:work`:** with `QUEUE_CONNECTION=sync` the worker has nothing to do. Don't run it.
- **Restart long-running processes** (`serve`, `queue:work`) and run `php artisan config:clear` after editing `.env`.

# Setup and running

This guide covers everything needed to run FleetLive locally. If you only want to see it working, use **instant mode**, which needs neither MySQL nor Redis. Its setup is described in [running-without-redis.md](running-without-redis.md).

- [Requirements](#requirements)
- [Installing MySQL and Redis](#installing-mysql-and-redis)
- [Installing the project](#installing-the-project)
- [Environment variables](#environment-variables)
- [Running the app](#running-the-app)
- [Demo data: seeder and simulator](#demo-data-seeder-and-simulator)
- [Running the tests](#running-the-tests)
- [Troubleshooting](#troubleshooting)

## Requirements

| Tool | Version | Used for |
| --- | --- | --- |
| PHP | 8.3+ with `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `openssl`, `curl` | Laravel |
| Composer | 2.x | PHP dependencies |
| Node.js | 20+ (22 recommended) | Vite, React build |
| MySQL | 8.0+ (MariaDB 10.6+ works too) | Main database |
| Redis | 6+ (or a compatible server such as Memurai or Valkey) | Queue and latest-position cache |

The PHP Redis extension (`phpredis`) is **not** required. The project uses `predis`, a pure-PHP client installed by Composer.

## Installing MySQL and Redis

### Windows

**MySQL:** use any of [WAMP](https://www.wampserver.com/), [Laragon](https://laragon.org/), XAMPP or the official MySQL installer.

> WAMP's MySQL defaults to the MyISAM storage engine. FleetLive forces InnoDB in `config/database.php` because it needs transactions, row locks and foreign keys, so nothing has to be changed. This is just why you'll see `'engine' => 'InnoDB'` there.

**Redis:** Redis has no official Windows build. The simplest option is **Memurai**, a Redis-compatible server that runs as a Windows service on port 6379. In an **administrator** PowerShell:

```powershell
winget install --id Memurai.MemuraiDeveloper -e
Get-Service Memurai                                     # should be Running
& "C:\Program Files\Memurai\memurai-cli.exe" ping       # should print PONG
```

The free Developer edition stops itself after about 10 days of continuous running. Restart it with `Restart-Service Memurai`.

Alternatives: Docker (`docker run -d -p 6379:6379 --name redis redis:7`), or a WSL Ubuntu distribution with `sudo apt install redis-server`.

### macOS

```bash
brew install mysql redis
brew services start mysql
brew services start redis
redis-cli ping            # PONG
```

### Linux (Debian/Ubuntu)

```bash
sudo apt install mysql-server redis-server
sudo systemctl enable --now mysql redis-server
redis-cli ping            # PONG
```

### Docker (any OS)

```bash
docker run -d --name fleetlive-mysql -p 3306:3306 -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=fleetlive mysql:8
docker run -d --name fleetlive-redis -p 6379:6379 redis:7
```

## Installing the project

```bash
git clone https://github.com/Ayoub-Dachri/fleetlive.git
cd fleetlive

composer install
npm install

cp .env.example .env          # PowerShell: Copy-Item .env.example .env
php artisan key:generate
```

Create the database (skip this if you used the Docker command above):

```sql
CREATE DATABASE fleetlive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Create the tables and load the demo fleet:

```bash
php artisan migrate --seed
```

## Environment variables

`.env.example` is already set up for MySQL and Redis on localhost. The values that matter:

```dotenv
APP_NAME=FleetLive
APP_URL=http://127.0.0.1:8000     # the simulator posts to APP_URL/api/gps/ingest

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fleetlive
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=cookie             # no server-side session storage needed (no auth)
QUEUE_CONNECTION=redis            # ingestion is processed by queue:work
CACHE_STORE=redis                 # latest positions + rate-limit counters

REDIS_CLIENT=predis               # pure-PHP client; use phpredis if the extension is installed
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

`APP_URL` uses `127.0.0.1` rather than `localhost` on purpose. On Windows, `localhost` can resolve to IPv6 `::1` first, while `php artisan serve` listens only on IPv4.

After changing `.env`, run `php artisan config:clear` if you have ever run `config:cache`.

## Running the app

Open four terminals in the project folder:

| Terminal | Command | What it does |
| --- | --- | --- |
| 1 | `npm run dev` | Vite dev server (hot reload for React/CSS) |
| 2 | `php artisan serve` | Web server at http://127.0.0.1:8000 |
| 3 | `php artisan queue:work redis` | Processes ingested GPS messages |
| 4 | `php artisan gps:simulate` | Sends fake GPS data every 2 seconds |

Open **http://127.0.0.1:8000**. The header should show a green **Live** indicator and the vehicles should start moving.

Instead of `npm run dev` you can build the assets once with `npm run build`. The page then loads the compiled files and terminal 1 isn't needed.

### What you should see

| On screen | Meaning |
| --- | --- |
| Green marker, **Moving** badge | Recent message, valid GPS, vehicle moving |
| Gray marker, amber **Idle** badge | Recent message, valid GPS, vehicle stopped |
| Red marker, **GPS Invalid** badge | Tracker reporting, but without a valid fix. The marker stays at the last good position. |
| Faded red marker, **Offline** badge | No message for more than 60 seconds |
| **Reconnecting…** in the header | The last poll(s) failed; the dashboard keeps retrying |
| **Disconnected** + Reconnect button | 5 polls in a row failed and polling has stopped |

## Demo data: seeder and simulator

### `php artisan db:seed --class=FleetSeeder`

Creates 5 demo vehicles with realistic IMEI-style idents, each with 10 historical messages spaced 10 seconds apart and ending now. The messages go through the same normalizer and job as real traffic, so MySQL and the Redis cache are both populated. `php artisan migrate --seed` runs this seeder automatically.

Seeded vehicles show as **Offline** after 60 seconds unless the simulator is running. That's expected.

### `php artisan gps:simulate`

A fake tracker fleet that sends data **over HTTP to the real ingest API**, exactly like physical devices would.

- If no vehicles exist, it runs `FleetSeeder` first.
- Every tick (2 s by default), each vehicle:
  - moves at 12–70 km/h, with small heading changes and occasional turns of up to 90° at "intersections";
  - stays inside an inland area of Casablanca and steers back toward the centre if it drifts out;
  - has a 4% chance of stopping for 4–20 ticks (engine on or off), which shows as **Idle**;
  - has a 2% chance of losing its GPS fix for 3–6 ticks, which shows as **GPS Invalid**;
  - has randomised battery, external power, satellites and GSM signal.
- All vehicles are sent together in one `{"messages": [...]}` request per tick.

Options:

```bash
php artisan gps:simulate --interval=5                               # every 5 seconds
php artisan gps:simulate --url=http://192.168.1.10:8000/api/gps/ingest
```

Stop it with **Ctrl+C**. It needs `php artisan serve` (to receive the requests) and, in full-stack mode, `queue:work` (to store them).

## Running the tests

```bash
php artisan test
```

The tests use in-memory SQLite, an array cache and a sync queue (set in `phpunit.xml`), so they run without MySQL or Redis.

## Troubleshooting

**Dashboard stuck on "Connecting…" or "Reconnecting…"**
The API is returning errors. Open http://127.0.0.1:8000/api/gps/vehicles in the browser to see the error message.
- `No connection could be made … [tcp://127.0.0.1:6379]` means Redis isn't running. Start it (`Get-Service Memurai`, `brew services start redis`, …) or switch to [instant mode](running-without-redis.md). Every request fails without Redis because the rate limiter keeps its counters in the cache store.
- `SQLSTATE[HY000] [2002]` means MySQL isn't running or the `DB_*` values are wrong.

**Vehicles don't move, but the simulator prints "5 queued"**
The queue worker isn't running. Start `php artisan queue:work redis`. Messages wait in Redis and are processed as soon as the worker starts.

**Simulator prints "Cannot reach http://…/api/gps/ingest"**
`php artisan serve` isn't running, or `APP_URL` doesn't match the address it listens on. Use `--url=` to point the simulator at the right address.

**Simulator prints `HTTP 429`**
The rate limit was hit (600 requests per minute per IP). This only happens with a very small `--interval` or several simulators at once.

**Migration error `Specified key was too long; max key length is 1000 bytes`**
The tables were created as MyISAM. `config/database.php` already forces InnoDB, so you will only see this on an old checkout or if the config was overridden. Drop the half-created tables and migrate again.

**Changes to `.env` have no effect**
Run `php artisan config:clear`, then restart `php artisan serve` and `queue:work`. Long-running processes keep the old configuration.

**Job code changed but the behaviour didn't**
Restart `queue:work`. Workers load the application once at startup.

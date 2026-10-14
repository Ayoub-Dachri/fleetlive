# API reference

Base URL: `http://127.0.0.1:8000/api/gps`. All endpoints are public and return JSON. Send `Accept: application/json` so validation errors come back as JSON.

| Method | Path | Purpose | Rate limit |
| --- | --- | --- | --- |
| `POST` | `/ingest` | Receive GPS telemetry | 600/min per IP |
| `GET` | `/vehicles` | All vehicles with their latest state | 300/min per IP |
| `GET` | `/vehicles/updates?since=` | Only vehicles changed after `since` | 300/min per IP |
| `GET` | `/vehicles/{id}/events` | Last 20 messages of one vehicle | 300/min per IP |

Going over a limit returns `429 Too Many Requests` with a `Retry-After` header.

---

## POST /api/gps/ingest

Accepts one telemetry object, or a batch of up to **500** under `messages`. The data is validated and queued, then the endpoint returns **`202 Accepted`** immediately. The data is written to the database by the queue worker a moment later.

### Single message

```json
{
  "battery.voltage": 3.938,
  "channel.id": 429,
  "device.id": 182083,
  "device.name": "Vehicle",
  "device.type.id": 744,
  "engine.ignition.status": false,
  "external.powersource.voltage": 12.64,
  "gsm.signal.level": 80,
  "ident": "111115555599999",
  "movement.status": false,
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
```

Response:

```json
HTTP/1.1 202 Accepted

{ "accepted": 1, "message": "GPS data queued successfully" }
```

### Batch

```json
{
  "messages": [
    { "ident": "111115555599999", "timestamp": 1678346071, "position.latitude": 43.955218, "position.longitude": 37.661918 },
    { "ident": "111115555599999", "timestamp": 1678346081, "position.latitude": 43.955301, "position.longitude": 37.662044 }
  ]
}
```

```json
HTTP/1.1 202 Accepted

{ "accepted": 2, "message": "GPS data queued successfully" }
```

### Field mapping

Keys not listed here (`gsm.cellid`, `peer`, `event.priority.enum`, …) are accepted and kept in `gps_events.raw_payload`.

| Payload key | Required | Rule | Stored as |
| --- | --- | --- | --- |
| `ident` | ✅ | string, ≤ 64 chars | `vehicles.ident` (vehicle lookup key) |
| `timestamp` | ✅ | number ≥ 0, at most 5 min in the future (unix seconds, device time) | `device_timestamp`, `message_timestamp` |
| `position.latitude` | ✅ | −90 … 90 | `latitude` |
| `position.longitude` | ✅ | −180 … 180 | `longitude` |
| `position.valid` | | boolean, default `true` | `gnss_valid` |
| `position.altitude` | | number | `altitude` |
| `position.direction` | | 0 … 360 | `direction` |
| `position.speed` | | number ≥ 0 (km/h) | `speed` |
| `position.hdop` | | 0 … 999 | `hdop` |
| `position.satellites` | | integer 0 … 255 | `satellites` |
| `engine.ignition.status` | | boolean | `ignition_status` |
| `movement.status` | | boolean | `movement_status` |
| `battery.voltage` | | 0 … 999 | `battery_voltage` |
| `external.powersource.voltage` | | 0 … 999 | `external_power_voltage` |
| `gsm.signal.level` | | integer | `gsm_signal_level` |
| `vehicle.mileage` | | number ≥ 0 (km) | `mileage` |
| `server.timestamp` | | number ≥ 0 (unix seconds, fractional) | `server_timestamp` |
| `device.id` | | integer ≥ 0 | `vehicles.device_id` |
| `device.name` | | string ≤ 255 | `vehicles.device_name` |
| `device.type.id`, `channel.id`, `protocol.id` | | integer ≥ 0 | `vehicles.device_type_id`, `channel_id`, `protocol_id` |

Nested objects are accepted too: `{"position": {"latitude": 43.9}}` is flattened to `position.latitude`.

### Processing rules

- **`position.valid: false`.** The event is stored and the telemetry (battery, ignition, …) is updated, but the vehicle **keeps its last known coordinates** on the map, and its status becomes *GPS Invalid*.
- **Duplicates.** A message with the same `ident` and `timestamp` as a stored one is ignored.
- **Out of order.** A message older than the vehicle's current position is stored in history but doesn't move the vehicle.
- **Future timestamps.** Rejected with `422` when more than 5 minutes ahead. A device with a wrong clock would otherwise block all its later, real messages from moving the vehicle.
- **Failures.** If storing one vehicle fails, the other vehicles in the batch are still stored. The job then fails and is retried, which is safe because already-stored messages are skipped as duplicates.
- **New ident.** The vehicle is created automatically on its first message.

### Validation errors: `422`

Errors are keyed by message index (`messages.0…`), for single messages too:

```json
{
  "message": "The position.latitude field is required. (and 1 more error)",
  "errors": {
    "messages.0.position.latitude": ["The position.latitude field is required."],
    "messages.0.ident": ["The ident field is required."]
  }
}
```

### Examples

curl (macOS/Linux/Git Bash):

```bash
curl -X POST http://127.0.0.1:8000/api/gps/ingest \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"ident":"111115555599999","timestamp":1678346071,"position.latitude":43.955218,"position.longitude":37.661918,"position.valid":true}'
```

PowerShell:

```powershell
$body = @{ 'ident' = '111115555599999'; 'timestamp' = 1678346071; 'position.latitude' = 43.955218; 'position.longitude' = 37.661918 } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8000/api/gps/ingest -ContentType 'application/json' -Headers @{ Accept = 'application/json' } -Body $body
```

---

## GET /api/gps/vehicles

Every vehicle with its latest state, ordered by id. The dashboard calls this once on load.

```json
{
  "server_time": 1760479200.123,
  "vehicles": [ { "id": 1, "ident": "352093089612345", "name": "Delivery Van 12", "...": "see vehicle object below" } ]
}
```

---

## GET /api/gps/vehicles/updates?since={unix_timestamp}

Returns only the vehicles whose state changed after `since`. Call it with the `server_time` from the previous response.

| Parameter | Required | Rule |
| --- | --- | --- |
| `since` | ✅ | number ≥ 0, unix seconds (fractions allowed) |

```json
{
  "server_time": 1760479200.123,
  "vehicles": [
    {
      "id": 1,
      "device_id": 182083,
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
```

Notes:

- The server returns changes from **2 seconds before** `since` to cover transactions that committed late. Treat a vehicle whose `updated_at` you already have as unchanged.
- An empty `vehicles` array means nothing changed. Keep polling with the new `server_time`.
- Missing `since` returns `422`.

### Vehicle object

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Internal vehicle id (used in `/vehicles/{id}/events`) |
| `device_id` | int \| null | Tracker's `device.id` |
| `ident` | string | Tracker IMEI / identifier |
| `name` | string | `device.name`, or `ident` when there is no name |
| `latitude`, `longitude` | float \| null | Last **valid** position; `null` if the vehicle has never had a valid fix |
| `speed` | float \| null | km/h |
| `direction` | int \| null | Heading in degrees, 0 = north |
| `altitude` | float \| null | Metres |
| `satellites` | int \| null | Satellites in use |
| `gnss_valid` | bool | Whether the **latest** message had a valid fix |
| `ignition`, `moving` | bool \| null | Engine ignition / movement sensor |
| `battery`, `external_power` | float \| null | Volts |
| `gsm_signal` | int \| null | GSM signal level |
| `mileage` | float \| null | km |
| `last_message_at` | ISO 8601 \| null | Device time of the latest message (UTC) |
| `updated_at` | float \| null | Server time (unix seconds, µs) when this state was saved; used for staleness and dedup |

---

## GET /api/gps/vehicles/{id}/events

The 20 most recent messages of one vehicle, newest first by device time. Unknown id → `404`.

```json
{
  "events": [
    {
      "id": 51,
      "vehicle_id": 1,
      "ident": "111115555599999",
      "latitude": 43.955218,
      "longitude": 37.661918,
      "speed": 0,
      "direction": 281,
      "gnss_valid": true,
      "ignition": false,
      "moving": false,
      "battery": 3.938,
      "external_power": 12.64,
      "message_at": "2023-03-09T07:14:31.000Z",
      "received_at": "2026-10-14T22:46:17.000Z"
    }
  ]
}
```

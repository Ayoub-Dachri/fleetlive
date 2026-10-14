<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class GpsEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'speed' => 'float',
            'direction' => 'integer',
            'ignition_status' => 'boolean',
            'movement_status' => 'boolean',
            'battery_voltage' => 'float',
            'external_power_voltage' => 'float',
            'gsm_signal_level' => 'integer',
            'mileage' => 'float',
            'raw_payload' => 'array',
            'device_timestamp' => 'integer',
            'server_timestamp' => 'float',
            'message_timestamp' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Build a row for a bulk insert. This bypasses Eloquent, so values are encoded here.
     */
    public static function rowFromMessage(int $vehicleId, array $message): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'ident' => $message['ident'],
            ...Arr::only($message['position'], ['latitude', 'longitude', 'speed', 'direction']),
            ...Arr::except($message['telemetry'], ['gnss_valid', 'satellites']),
            'raw_payload' => json_encode($message['raw']),
            'device_timestamp' => $message['device_timestamp'],
            'server_timestamp' => $message['server_timestamp'],
            'message_timestamp' => Carbon::createFromTimestamp($message['device_timestamp'])->toDateTimeString(),
            'received_at' => $message['received_at'],
        ];
    }

    public function toFeedArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'ident' => $this->ident,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed' => $this->speed,
            'direction' => $this->direction,
            'gnss_valid' => (bool) ($this->raw_payload['position.valid'] ?? true),
            'ignition' => $this->ignition_status,
            'moving' => $this->movement_status,
            'battery' => $this->battery_voltage,
            'external_power' => $this->external_power_voltage,
            'message_at' => $this->message_timestamp->toIso8601ZuluString('millisecond'),
            'received_at' => $this->received_at->toIso8601ZuluString('millisecond'),
        ];
    }
}

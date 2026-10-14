<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class VehicleCurrentPosition extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'vehicle_id';

    public $incrementing = false;

    protected $guarded = [];

    // Keep microseconds on updated_at; it is the polling cursor.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'altitude' => 'float',
            'direction' => 'integer',
            'speed' => 'float',
            'hdop' => 'float',
            'satellites' => 'integer',
            'gnss_valid' => 'boolean',
            'ignition_status' => 'boolean',
            'movement_status' => 'boolean',
            'battery_voltage' => 'float',
            'external_power_voltage' => 'float',
            'gsm_signal_level' => 'integer',
            'mileage' => 'float',
            'device_timestamp' => 'integer',
            'server_timestamp' => 'float',
            'message_timestamp' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Apply a message from GpsPayloadNormalizer. A message without a valid GPS fix
     * still refreshes the telemetry, but keeps the last known coordinates so the
     * vehicle does not jump around the map.
     */
    public function applyMessage(array $message): void
    {
        if ($message['telemetry']['gnss_valid']) {
            $this->fill($message['position']);
        }

        $this->fill($message['telemetry']);
        $this->fill([
            'device_timestamp' => $message['device_timestamp'],
            'server_timestamp' => $message['server_timestamp'],
            'message_timestamp' => Carbon::createFromTimestamp($message['device_timestamp']),
        ]);
    }

    public function updatedAtUnix(): float
    {
        return (float) $this->updated_at->format('U.u');
    }
}

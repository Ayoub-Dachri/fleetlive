<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    protected $fillable = [
        'ident',
        'device_id',
        'device_name',
        'device_type_id',
        'channel_id',
        'protocol_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function currentPosition(): HasOne
    {
        return $this->hasOne(VehicleCurrentPosition::class);
    }

    public function gpsEvents(): HasMany
    {
        return $this->hasMany(GpsEvent::class);
    }

    /**
     * The shape the live map works with. This exact array is what gets cached in Redis.
     */
    public function toLiveArray(): array
    {
        $position = $this->currentPosition;

        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'ident' => $this->ident,
            'name' => $this->device_name ?? $this->ident,
            'latitude' => $position?->latitude,
            'longitude' => $position?->longitude,
            'speed' => $position?->speed,
            'direction' => $position?->direction,
            'altitude' => $position?->altitude,
            'satellites' => $position?->satellites,
            'gnss_valid' => $position?->gnss_valid ?? false,
            'ignition' => $position?->ignition_status,
            'moving' => $position?->movement_status,
            'battery' => $position?->battery_voltage,
            'external_power' => $position?->external_power_voltage,
            'gsm_signal' => $position?->gsm_signal_level,
            'mileage' => $position?->mileage,
            'last_message_at' => $position?->message_timestamp->toIso8601ZuluString('millisecond'),
            'updated_at' => $position?->updatedAtUnix(),
        ];
    }
}

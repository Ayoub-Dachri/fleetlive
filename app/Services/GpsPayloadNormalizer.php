<?php

namespace App\Services;

/**
 * Turns one flat, dot-keyed tracker payload ("position.latitude": 43.9) into a
 * typed, structured array grouped by where each value is stored.
 */
class GpsPayloadNormalizer
{
    public function normalize(array $payload): array
    {
        return [
            'ident' => (string) $payload['ident'],
            'vehicle' => [
                'device_id' => $this->int($payload, 'device.id'),
                'device_name' => isset($payload['device.name']) ? (string) $payload['device.name'] : null,
                'device_type_id' => $this->int($payload, 'device.type.id'),
                'channel_id' => $this->int($payload, 'channel.id'),
                'protocol_id' => $this->int($payload, 'protocol.id'),
            ],
            'position' => [
                'latitude' => (float) $payload['position.latitude'],
                'longitude' => (float) $payload['position.longitude'],
                'altitude' => $this->float($payload, 'position.altitude'),
                'direction' => $this->int($payload, 'position.direction'),
                'speed' => $this->float($payload, 'position.speed'),
                'hdop' => $this->float($payload, 'position.hdop'),
            ],
            'telemetry' => [
                'gnss_valid' => $this->bool($payload, 'position.valid') ?? true,
                'satellites' => $this->int($payload, 'position.satellites'),
                'ignition_status' => $this->bool($payload, 'engine.ignition.status'),
                'movement_status' => $this->bool($payload, 'movement.status'),
                'battery_voltage' => $this->float($payload, 'battery.voltage'),
                'external_power_voltage' => $this->float($payload, 'external.powersource.voltage'),
                'gsm_signal_level' => $this->int($payload, 'gsm.signal.level'),
                'mileage' => $this->float($payload, 'vehicle.mileage'),
            ],
            'device_timestamp' => (int) $payload['timestamp'],
            'server_timestamp' => $this->float($payload, 'server.timestamp'),
            'received_at' => now()->toDateTimeString(),
            'raw' => $payload,
        ];
    }

    private function float(array $payload, string $key): ?float
    {
        return is_numeric($payload[$key] ?? null) ? (float) $payload[$key] : null;
    }

    private function int(array $payload, string $key): ?int
    {
        return is_numeric($payload[$key] ?? null) ? (int) round($payload[$key]) : null;
    }

    private function bool(array $payload, string $key): ?bool
    {
        return isset($payload[$key])
            ? filter_var($payload[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;
    }
}

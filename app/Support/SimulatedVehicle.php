<?php

namespace App\Support;

use App\Models\Vehicle;

/**
 * A fake tracker driving around Casablanca. Used by `gps:simulate` and FleetSeeder.
 */
class SimulatedVehicle
{
    public const DEMO_FLEET = [
        ['ident' => '352093089612345', 'device_id' => 182083, 'device_name' => 'Delivery Van 12'],
        ['ident' => '352093089612352', 'device_id' => 182084, 'device_name' => 'Reefer Truck 07'],
        ['ident' => '352093089612360', 'device_id' => 182085, 'device_name' => 'Service Car 03'],
        ['ident' => '352093089612378', 'device_id' => 182086, 'device_name' => 'Courier Scooter 21'],
        ['ident' => '352093089612386', 'device_id' => 182087, 'device_name' => 'City Shuttle 04'],
    ];

    // Inland Casablanca: the Atlantic coast is just north of this box.
    private const SOUTH = 33.515;

    private const NORTH = 33.590;

    private const WEST = -7.670;

    private const EAST = -7.520;

    private float $speed = 0; // km/h

    private bool $ignition = true;

    private int $idleTicks = 0;

    private int $noFixTicks = 0;

    private float $battery;

    public function __construct(
        public readonly string $ident,
        public readonly ?int $deviceId,
        public readonly string $name,
        private float $latitude,
        private float $longitude,
        private float $heading,
        private float $mileage,
    ) {
        $this->battery = self::random(3.95, 4.15);
    }

    /**
     * Continue from the vehicle's last known position, or start somewhere in the city.
     */
    public static function fromVehicle(Vehicle $vehicle): self
    {
        $position = $vehicle->currentPosition;
        $known = $position && self::inBounds($position->latitude, $position->longitude);

        return new self(
            ident: $vehicle->ident,
            deviceId: $vehicle->device_id,
            name: $vehicle->device_name ?? $vehicle->ident,
            latitude: $known ? $position->latitude : self::random(self::SOUTH, self::NORTH),
            longitude: $known ? $position->longitude : self::random(self::WEST, self::EAST),
            heading: $position?->direction ?? self::random(0, 359),
            mileage: $position?->mileage ?? self::random(8000, 90000),
        );
    }

    public function advance(int $seconds): void
    {
        $this->updateDrivingState();
        $this->steer();

        $km = $this->speed * $seconds / 3600;
        $this->latitude += $km / 111.32 * cos(deg2rad($this->heading));
        $this->longitude += $km / (111.32 * cos(deg2rad($this->latitude))) * sin(deg2rad($this->heading));
        $this->mileage += $km;

        // Lose the GPS fix now and then (tunnels, underground car parks).
        if ($this->noFixTicks > 0) {
            $this->noFixTicks--;
        } elseif (self::chance(2)) {
            $this->noFixTicks = random_int(3, 6);
        }

        // The battery charges while the engine runs and drains slowly otherwise.
        $drift = ($this->ignition ? 0.004 : -0.003) + self::random(-0.002, 0.002);
        $this->battery = min(4.2, max(3.6, $this->battery + $drift));
    }

    public function status(): string
    {
        return match (true) {
            $this->noFixTicks > 0 => 'no fix',
            $this->speed > 0 => 'moving',
            default => 'idle',
        };
    }

    /**
     * A payload in the tracker's flat, dot-keyed format.
     */
    public function toPayload(int $timestamp): array
    {
        $hasFix = $this->noFixTicks === 0;

        return [
            'battery.voltage' => round($this->battery, 3),
            'channel.id' => 429,
            'device.id' => $this->deviceId,
            'device.name' => $this->name,
            'device.type.id' => 744,
            'engine.ignition.status' => $this->ignition,
            'event.priority.enum' => 0,
            'external.powersource.voltage' => round($this->ignition ? self::random(13.6, 14.3) : self::random(12.3, 12.8), 2),
            'gnss.state.enum' => $hasFix ? 1 : 0,
            'gnss.status' => $hasFix,
            'gsm.cellid' => random_int(11000, 29000),
            'gsm.lac' => 108,
            'gsm.mcc' => 604,
            'gsm.mnc' => 1,
            'gsm.operator.code' => '60401',
            'gsm.signal.level' => random_int(45, 100),
            'ident' => $this->ident,
            'movement.status' => $this->speed > 0,
            'peer' => sprintf('105.66.%d.%d:%d', random_int(0, 255), random_int(1, 254), random_int(40000, 65000)),
            'position.altitude' => random_int(15, 90),
            'position.direction' => (int) round($this->heading) % 360,
            'position.hdop' => $hasFix ? round(self::random(0.5, 1.4), 1) : 99.9,
            'position.latitude' => round($this->latitude, 6),
            'position.longitude' => round($this->longitude, 6),
            'position.satellites' => $hasFix ? random_int(8, 17) : random_int(0, 3),
            'position.speed' => $hasFix ? round($this->speed) : 0,
            'position.valid' => $hasFix,
            'protocol.id' => 14,
            'server.timestamp' => round($timestamp + self::random(0.2, 0.9), 6),
            'timestamp' => $timestamp,
            'vehicle.mileage' => round($this->mileage, 3),
        ];
    }

    private function updateDrivingState(): void
    {
        if ($this->idleTicks > 0) {
            $this->idleTicks--;
            $this->speed = 0;

            return;
        }

        // Stop for a delivery, a red light or traffic; sometimes with the engine off.
        if (self::chance(4)) {
            $this->idleTicks = random_int(4, 20);
            $this->ignition = self::chance(50);
            $this->speed = 0;

            return;
        }

        $this->ignition = true;
        $this->speed = min(70, max(12, ($this->speed ?: 15) + self::random(-8, 10)));
    }

    private function steer(): void
    {
        if (! self::inBounds($this->latitude, $this->longitude)) {
            // Head back towards the middle of the box.
            $dLat = (self::SOUTH + self::NORTH) / 2 - $this->latitude;
            $dLng = ((self::WEST + self::EAST) / 2 - $this->longitude) * cos(deg2rad($this->latitude));
            $this->heading = rad2deg(atan2($dLng, $dLat)) + self::random(-15, 15);
        } elseif (self::chance(8)) {
            $this->heading += self::random(-90, 90); // turn at an intersection
        } else {
            $this->heading += self::random(-10, 10);
        }

        $this->heading = fmod($this->heading + 360, 360);
    }

    private static function inBounds(float $latitude, float $longitude): bool
    {
        return $latitude >= self::SOUTH && $latitude <= self::NORTH
            && $longitude >= self::WEST && $longitude <= self::EAST;
    }

    private static function chance(int $percent): bool
    {
        return random_int(1, 100) <= $percent;
    }

    private static function random(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
}

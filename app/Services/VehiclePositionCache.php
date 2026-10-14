<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;

/**
 * Latest live-map payload per vehicle, stored in Redis under "vehicle:position:{id}".
 * MySQL stays the source of truth; Redis spares the polling endpoint from rebuilding
 * every payload with a join.
 */
class VehiclePositionCache
{
    private const TTL_SECONDS = 86400;

    public function put(Vehicle $vehicle): void
    {
        Cache::put($this->key($vehicle->id), $vehicle->toLiveArray(), self::TTL_SECONDS);
    }

    /**
     * Payloads for the given vehicles. Entries missing from Redis, or older than the
     * database row, are loaded from MySQL and written back to the cache.
     *
     * @param  array<int, float>  $updatedAt  vehicle_id => updated_at (unix seconds)
     * @return list<array>
     */
    public function many(array $updatedAt): array
    {
        if ($updatedAt === []) {
            return [];
        }

        $cached = Cache::many(array_map($this->key(...), array_keys($updatedAt)));
        $payloads = [];
        $missing = [];

        foreach ($updatedAt as $vehicleId => $timestamp) {
            $entry = $cached[$this->key($vehicleId)] ?? null;

            if ($entry !== null && $entry['updated_at'] >= $timestamp) {
                $payloads[] = $entry;
            } else {
                $missing[] = $vehicleId;
            }
        }

        foreach (Vehicle::with('currentPosition')->findMany($missing) as $vehicle) {
            $this->put($vehicle);
            $payloads[] = $vehicle->toLiveArray();
        }

        return $payloads;
    }

    private function key(int $vehicleId): string
    {
        return "vehicle:position:{$vehicleId}";
    }
}

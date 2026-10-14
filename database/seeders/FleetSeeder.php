<?php

namespace Database\Seeders;

use App\Jobs\StoreGpsEventJob;
use App\Models\Vehicle;
use App\Services\GpsPayloadNormalizer;
use App\Support\SimulatedVehicle;
use Illuminate\Database\Seeder;

class FleetSeeder extends Seeder
{
    private const HISTORY_MESSAGES = 10;

    private const SECONDS_BETWEEN_MESSAGES = 10;

    /**
     * Five demo vehicles, each with a short trail of messages ending now. The messages
     * go through the same normalizer and job as real traffic, so Redis is warmed too.
     */
    public function run(GpsPayloadNormalizer $normalizer): void
    {
        foreach (SimulatedVehicle::DEMO_FLEET as $attributes) {
            $vehicle = Vehicle::firstOrCreate(
                ['ident' => $attributes['ident']],
                $attributes + ['device_type_id' => 744, 'channel_id' => 429, 'protocol_id' => 14],
            );

            $simulated = SimulatedVehicle::fromVehicle($vehicle);
            $messages = [];

            for ($i = self::HISTORY_MESSAGES - 1; $i >= 0; $i--) {
                $simulated->advance(self::SECONDS_BETWEEN_MESSAGES);
                $timestamp = now()->subSeconds($i * self::SECONDS_BETWEEN_MESSAGES)->timestamp;
                $messages[] = $normalizer->normalize($simulated->toPayload($timestamp));
            }

            StoreGpsEventJob::dispatchSync($messages);
        }
    }
}

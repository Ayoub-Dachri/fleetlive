<?php

namespace App\Jobs;

use App\Models\GpsEvent;
use App\Models\Vehicle;
use App\Models\VehicleCurrentPosition;
use App\Services\VehiclePositionCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persists one ingest request: one or many messages, possibly for several vehicles.
 */
class StoreGpsEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 15];

    /**
     * @param  list<array>  $messages  Messages produced by GpsPayloadNormalizer.
     */
    public function __construct(public array $messages) {}

    public function handle(VehiclePositionCache $cache): void
    {
        $byVehicle = collect($this->messages)->groupBy('ident');
        $failure = null;

        foreach ($byVehicle as $ident => $messages) {
            try {
                $vehicle = $this->storeVehicleMessages((string) $ident, $messages->sortBy('device_timestamp')->values());
            } catch (Throwable $exception) {
                // One failing vehicle must not drop the other vehicles in the batch.
                $failure ??= $exception;

                continue;
            }

            // Written after the commit, so Redis never holds a position MySQL rolled back.
            if ($vehicle) {
                $cache->put($vehicle);
            }
        }

        // Fail the job so the queue retries it. Replaying is safe: stored messages are
        // skipped as duplicates and older messages never overwrite a newer position.
        if ($failure) {
            throw $failure;
        }
    }

    /**
     * Returns the vehicle when its live position changed, null otherwise.
     *
     * @param  Collection<int, array>  $messages  Oldest first.
     */
    private function storeVehicleMessages(string $ident, Collection $messages): ?Vehicle
    {
        // Race-safe: falls back to a select if another worker inserts the same ident first.
        $vehicleId = Vehicle::firstOrCreate(['ident' => $ident])->id;

        return DB::transaction(function () use ($vehicleId, $messages) {
            // Locking the vehicle row serialises workers handling the same vehicle,
            // so an older batch can never overwrite a newer position.
            $vehicle = Vehicle::with('currentPosition')->lockForUpdate()->findOrFail($vehicleId);

            // Duplicates (same vehicle and device timestamp) are skipped by the unique index.
            GpsEvent::insertOrIgnore(
                $messages->map(fn (array $message) => GpsEvent::rowFromMessage($vehicle->id, $message))->all()
            );

            $vehicle->fill(array_filter($messages->last()['vehicle'], fn ($value) => $value !== null));
            $vehicle->last_seen_at = now();
            $vehicle->save();

            $position = $vehicle->currentPosition ?? new VehicleCurrentPosition(['vehicle_id' => $vehicle->id]);

            foreach ($messages as $message) {
                // Late or replayed messages must not move the vehicle back in time.
                if ($message['device_timestamp'] > ($position->device_timestamp ?? 0)) {
                    $position->applyMessage($message);
                }
            }

            // Nothing newer arrived, or the vehicle has never had a valid fix to show.
            if (! $position->isDirty() || $position->latitude === null) {
                return null;
            }

            $position->save();

            return $vehicle->setRelation('currentPosition', $position);
        }, attempts: 3);
    }
}

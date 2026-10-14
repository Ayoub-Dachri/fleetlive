<?php

namespace Tests\Feature;

use App\Jobs\StoreGpsEventJob;
use App\Models\GpsEvent;
use App\Models\Vehicle;
use App\Services\GpsPayloadNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GpsIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_payload_is_queued(): void
    {
        Queue::fake();

        $this->postJson('/api/gps/ingest', $this->payload())
            ->assertStatus(202)
            ->assertExactJson(['accepted' => 1, 'message' => 'GPS data queued successfully']);

        Queue::assertPushed(StoreGpsEventJob::class, fn ($job) => $job->messages[0]['ident'] === '111115555599999'
            && $job->messages[0]['position']['latitude'] === 43.955218);
        $this->assertDatabaseCount('gps_events', 0);
    }

    public function test_batch_payload_is_queued(): void
    {
        Queue::fake();

        $messages = array_map(fn ($i) => $this->payload(['timestamp' => 1678346071 + $i]), range(1, 5));

        $this->postJson('/api/gps/ingest', ['messages' => $messages])
            ->assertStatus(202)
            ->assertJson(['accepted' => 5]);

        Queue::assertPushed(StoreGpsEventJob::class, fn ($job) => count($job->messages) === 5);
    }

    public function test_missing_required_fields_return_422(): void
    {
        $payload = $this->payload();
        unset($payload['position.latitude'], $payload['ident']);

        $this->postJson('/api/gps/ingest', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['messages.0.position.latitude', 'messages.0.ident']);

        $this->postJson('/api/gps/ingest', ['messages' => [$this->payload(['position.longitude' => 500])]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['messages.0.position.longitude']);
    }

    public function test_nested_payload_is_flattened_and_queued(): void
    {
        Queue::fake();

        $this->postJson('/api/gps/ingest', [
            'ident' => '111115555599999',
            'timestamp' => 1678346071,
            'position' => ['latitude' => 43.955218, 'longitude' => 37.661918, 'valid' => true],
        ])->assertStatus(202);

        Queue::assertPushed(StoreGpsEventJob::class, fn ($job) => $job->messages[0]['position']['latitude'] === 43.955218
            && $job->messages[0]['raw']['position.longitude'] === 37.661918);
    }

    public function test_timestamp_too_far_in_the_future_returns_422(): void
    {
        $this->postJson('/api/gps/ingest', $this->payload(['timestamp' => now()->addHour()->timestamp]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['messages.0.timestamp' => 'cannot be more than 5 minutes in the future']);
    }

    public function test_out_of_range_device_fields_return_422(): void
    {
        $this->postJson('/api/gps/ingest', $this->payload(['channel.id' => -5, 'protocol.id' => 99999999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['messages.0.channel.id', 'messages.0.protocol.id']);
    }

    public function test_job_stores_event_position_and_cache(): void
    {
        $this->runJob([$this->payload()]);

        $vehicle = Vehicle::with('currentPosition')->sole();
        $this->assertSame('111115555599999', $vehicle->ident);
        $this->assertSame(182083, $vehicle->device_id);
        $this->assertNotNull($vehicle->last_seen_at);
        $this->assertSame(43.955218, $vehicle->currentPosition->latitude);
        $this->assertSame(12.64, $vehicle->currentPosition->external_power_voltage);

        $event = GpsEvent::sole();
        $this->assertSame(281, $event->direction);
        $this->assertSame(36713.703, $event->raw_payload['vehicle.mileage']);

        $cached = Cache::get("vehicle:position:{$vehicle->id}");
        $this->assertSame(37.661918, $cached['longitude']);
        $this->assertSame('2023-03-09T07:14:31.000Z', $cached['last_message_at']);
    }

    public function test_duplicate_messages_are_stored_once(): void
    {
        $this->runJob([$this->payload(), $this->payload()]);
        $this->runJob([$this->payload()]);

        $this->assertDatabaseCount('gps_events', 1);
    }

    public function test_invalid_fix_is_stored_but_keeps_last_position(): void
    {
        $this->runJob([$this->payload()]);
        $this->runJob([$this->payload([
            'timestamp' => 1678346081,
            'position.valid' => false,
            'position.latitude' => 0,
            'position.longitude' => 0,
            'battery.voltage' => 3.5,
        ])]);

        $position = Vehicle::sole()->currentPosition;
        $this->assertDatabaseCount('gps_events', 2);
        $this->assertSame(43.955218, $position->latitude);
        $this->assertFalse($position->gnss_valid);
        $this->assertSame(3.5, $position->battery_voltage);
    }

    public function test_older_messages_do_not_overwrite_newer_position(): void
    {
        $this->runJob([$this->payload(['timestamp' => 1678346100, 'position.latitude' => 44.0])]);
        $this->runJob([$this->payload(['timestamp' => 1678346050, 'position.latitude' => 43.0])]);

        $this->assertSame(44.0, Vehicle::sole()->currentPosition->latitude);
        $this->assertDatabaseCount('gps_events', 2);
    }

    public function test_one_failing_vehicle_does_not_block_the_rest_of_the_batch(): void
    {
        $normalizer = new GpsPayloadNormalizer;
        $broken = $normalizer->normalize($this->payload(['ident' => 'BROKEN']));
        $broken['telemetry']['unknown_column'] = 1; // makes this vehicle's insert fail

        try {
            StoreGpsEventJob::dispatchSync([$broken, $normalizer->normalize($this->payload())]);
            $this->fail('The job should fail so the queue retries it.');
        } catch (QueryException) {
            // expected
        }

        $this->assertDatabaseCount('gps_events', 1);
        $this->assertNotNull(Vehicle::firstWhere('ident', '111115555599999')->currentPosition);
    }

    private function runJob(array $payloads): void
    {
        $normalizer = new GpsPayloadNormalizer;

        StoreGpsEventJob::dispatchSync(array_map($normalizer->normalize(...), $payloads));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'battery.voltage' => 3.938,
            'channel.id' => 429,
            'device.id' => 182083,
            'device.name' => 'Vehicle',
            'device.type.id' => 744,
            'engine.ignition.status' => false,
            'event.priority.enum' => 0,
            'external.powersource.voltage' => 12.64,
            'gnss.state.enum' => 1,
            'gnss.status' => true,
            'gsm.cellid' => 11511,
            'gsm.lac' => 108,
            'gsm.mcc' => 257,
            'gsm.mnc' => 1,
            'gsm.operator.code' => '25701',
            'gsm.signal.level' => 80,
            'ident' => '111115555599999',
            'movement.status' => false,
            'peer' => '185.213.2.10:59924',
            'position.altitude' => 244,
            'position.direction' => 281,
            'position.hdop' => 0.3,
            'position.latitude' => 43.955218,
            'position.longitude' => 37.661918,
            'position.satellites' => 15,
            'position.speed' => 0,
            'position.valid' => true,
            'protocol.id' => 14,
            'server.timestamp' => 1678346072.792816,
            'timestamp' => 1678346071,
            'vehicle.mileage' => 36713.703,
        ], $overrides);
    }
}

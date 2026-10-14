<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\VehiclePositionCache;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VehicleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FleetSeeder::class);
    }

    public function test_vehicles_endpoint_lists_the_fleet(): void
    {
        $this->getJson('/api/gps/vehicles')
            ->assertOk()
            ->assertJsonCount(5, 'vehicles')
            ->assertJsonStructure(['server_time', 'vehicles' => [[
                'id', 'ident', 'name', 'latitude', 'longitude', 'speed', 'direction', 'altitude',
                'satellites', 'gnss_valid', 'ignition', 'moving', 'battery', 'external_power',
                'gsm_signal', 'mileage', 'last_message_at', 'updated_at',
            ]]]);
    }

    public function test_updates_only_returns_vehicles_changed_after_since(): void
    {
        $this->getJson('/api/gps/vehicles/updates?since=0')->assertJsonCount(5, 'vehicles');

        $future = microtime(true) + 60;
        $this->getJson("/api/gps/vehicles/updates?since={$future}")
            ->assertOk()
            ->assertJsonCount(0, 'vehicles')
            ->assertJsonStructure(['server_time']);

        $this->getJson('/api/gps/vehicles/updates')->assertStatus(422);
    }

    public function test_updates_fall_back_to_mysql_when_redis_is_empty(): void
    {
        Cache::flush();

        $this->getJson('/api/gps/vehicles/updates?since=0')->assertJsonCount(5, 'vehicles');

        $vehicle = Vehicle::first();
        $this->assertNotNull(Cache::get("vehicle:position:{$vehicle->id}"), 'fallback re-warms the cache');
    }

    public function test_updates_prefer_redis_payloads(): void
    {
        $vehicle = Vehicle::with('currentPosition')->first();
        app(VehiclePositionCache::class)->put($vehicle);

        $key = "vehicle:position:{$vehicle->id}";
        Cache::put($key, ['name' => 'From Redis'] + Cache::get($key));

        $this->getJson('/api/gps/vehicles/updates?since=0')
            ->assertJsonFragment(['name' => 'From Redis']);
    }

    public function test_events_endpoint_returns_latest_events_newest_first(): void
    {
        $vehicle = Vehicle::first();

        $events = $this->getJson("/api/gps/vehicles/{$vehicle->id}/events")
            ->assertOk()
            ->assertJsonCount(10, 'events')
            ->json('events');

        $this->assertGreaterThan($events[1]['message_at'], $events[0]['message_at']);
        $this->getJson('/api/gps/vehicles/999/events')->assertNotFound();
    }
}

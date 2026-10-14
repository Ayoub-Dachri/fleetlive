<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Support\SimulatedVehicle;
use Database\Seeders\FleetSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SimulateGpsCommand extends Command
{
    protected $signature = 'gps:simulate
        {--interval=2 : Seconds between messages}
        {--url= : Ingest endpoint, defaults to APP_URL/api/gps/ingest}';

    protected $description = 'Drive the demo fleet around Casablanca by posting telemetry to the ingest API';

    private bool $running = true;

    public function handle(): int
    {
        $url = $this->option('url') ?: url('/api/gps/ingest');
        $interval = max(1, (int) $this->option('interval'));
        $fleet = $this->loadFleet();

        // With pcntl, Ctrl+C finishes the current tick; without it (e.g. Windows),
        // Ctrl+C simply ends the process, which is just as safe.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], fn () => $this->running = false);
        }

        $this->components->info(sprintf(
            'Simulating %d vehicles, posting to %s every %ds. Press Ctrl+C to stop.',
            count($fleet), $url, $interval,
        ));

        while ($this->running) {
            $tickStartedAt = microtime(true);
            $this->sendTick($fleet, $url, $interval);

            $remaining = $interval - (microtime(true) - $tickStartedAt);
            if ($remaining > 0 && $this->running) {
                usleep((int) ($remaining * 1_000_000));
            }
        }

        $this->components->info('Simulator stopped.');

        return self::SUCCESS;
    }

    /**
     * @return list<SimulatedVehicle>
     */
    private function loadFleet(): array
    {
        if (! Vehicle::exists()) {
            $this->components->info('No vehicles yet, creating the demo fleet.');
            $this->callSilently('db:seed', ['--class' => FleetSeeder::class, '--force' => true]);
        }

        return Vehicle::with('currentPosition')->orderBy('id')->get()
            ->map(fn (Vehicle $vehicle) => SimulatedVehicle::fromVehicle($vehicle))
            ->all();
    }

    /**
     * @param  list<SimulatedVehicle>  $fleet
     */
    private function sendTick(array $fleet, string $url, int $interval): void
    {
        $timestamp = time();

        foreach ($fleet as $vehicle) {
            $vehicle->advance($interval);
        }

        $payloads = array_map(fn (SimulatedVehicle $vehicle) => $vehicle->toPayload($timestamp), $fleet);
        $summary = collect($fleet)->countBy(fn (SimulatedVehicle $vehicle) => $vehicle->status())
            ->map(fn (int $count, string $status) => "{$count} {$status}")
            ->implode(', ');

        try {
            $response = Http::acceptJson()->timeout(5)->post($url, ['messages' => $payloads]);
        } catch (ConnectionException) {
            $this->components->warn("Cannot reach {$url}. Is `php artisan serve` running?");

            return;
        }

        $time = now()->format('H:i:s');

        if ($response->accepted()) {
            $this->line("<fg=gray>{$time}</>  <info>{$response->json('accepted')} queued</info>  <fg=gray>({$summary})</>");
        } else {
            $this->line("<fg=gray>{$time}</>  <error> HTTP {$response->status()} </error> {$response->body()}");
        }
    }
}

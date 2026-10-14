<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Trackers usually send every few seconds; a gateway forwarding many
        // devices should batch them into {"messages": [...]}.
        RateLimiter::for('gps-ingest', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // A dashboard polls every 2s (30/min) plus feed refreshes; this leaves room for a few tabs.
        RateLimiter::for('gps-read', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class GpsEventController extends Controller
{
    private const FEED_SIZE = 20;

    /**
     * Latest messages for one vehicle, newest first.
     */
    public function index(Vehicle $vehicle): JsonResponse
    {
        $events = $vehicle->gpsEvents()
            ->latest('message_timestamp')
            ->limit(self::FEED_SIZE)
            ->get();

        return response()->json([
            'events' => $events->map->toFeedArray(),
        ]);
    }
}

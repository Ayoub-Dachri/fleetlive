<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleCurrentPosition;
use App\Services\VehiclePositionCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VehiclePositionController extends Controller
{
    /**
     * Each poll looks a little further back than the cursor. A position saved by a
     * transaction that commits just after a poll ran would otherwise fall behind the
     * cursor and never be returned. The client ignores entries it already has.
     */
    private const CURSOR_OVERLAP_SECONDS = 2;

    /**
     * Every vehicle with its latest state, for the initial page load.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'server_time' => microtime(true),
            'vehicles' => Vehicle::with('currentPosition')->orderBy('id')->get()->map->toLiveArray(),
        ]);
    }

    /**
     * Vehicles whose position changed after ?since=. The changed ids come from the
     * updated_at index; the payloads come from Redis, with MySQL as fallback.
     */
    public function updates(Request $request, VehiclePositionCache $cache): JsonResponse
    {
        $since = (float) $request->validate(['since' => ['required', 'numeric', 'min:0']])['since'];
        $serverTime = microtime(true);

        $cursor = Carbon::createFromTimestamp(max(0, $since - self::CURSOR_OVERLAP_SECONDS));

        $changed = VehicleCurrentPosition::query()
            ->where('updated_at', '>', $cursor->format('Y-m-d H:i:s.u'))
            ->pluck('updated_at', 'vehicle_id')
            ->map(fn (Carbon $updatedAt) => (float) $updatedAt->format('U.u'));

        return response()->json([
            'server_time' => $serverTime,
            'vehicles' => $cache->many($changed->all()),
        ]);
    }
}

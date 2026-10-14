<?php

namespace App\Http\Controllers;

use App\Http\Requests\GpsIngestRequest;
use App\Jobs\StoreGpsEventJob;
use App\Services\GpsPayloadNormalizer;
use Illuminate\Http\JsonResponse;

class GpsIngestController extends Controller
{
    /**
     * Validate, normalize, queue, respond. Nothing touches MySQL here.
     */
    public function __invoke(GpsIngestRequest $request, GpsPayloadNormalizer $normalizer): JsonResponse
    {
        $messages = array_map($normalizer->normalize(...), $request->payloads());

        StoreGpsEventJob::dispatch($messages);

        return response()->json([
            'accepted' => count($messages),
            'message' => 'GPS data queued successfully',
        ], 202);
    }
}

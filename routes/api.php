<?php

use App\Http\Controllers\GpsEventController;
use App\Http\Controllers\GpsIngestController;
use App\Http\Controllers\VehiclePositionController;
use Illuminate\Support\Facades\Route;

Route::prefix('gps')->group(function () {
    Route::post('ingest', GpsIngestController::class)->middleware('throttle:gps-ingest');

    Route::middleware('throttle:gps-read')->group(function () {
        Route::get('vehicles', [VehiclePositionController::class, 'index']);
        Route::get('vehicles/updates', [VehiclePositionController::class, 'updates']);
        Route::get('vehicles/{vehicle}/events', [GpsEventController::class, 'index'])->whereNumber('vehicle');
    });
});

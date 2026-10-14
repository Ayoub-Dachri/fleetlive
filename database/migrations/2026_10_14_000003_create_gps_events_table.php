<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only history of every message received.
     */
    public function up(): void
    {
        Schema::create('gps_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('ident', 64);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->unsignedSmallInteger('direction')->nullable();
            $table->boolean('ignition_status')->nullable();
            $table->boolean('movement_status')->nullable();
            $table->decimal('battery_voltage', 6, 3)->nullable();
            $table->decimal('external_power_voltage', 6, 3)->nullable();
            $table->unsignedSmallInteger('gsm_signal_level')->nullable();
            $table->decimal('mileage', 12, 3)->nullable();
            $table->json('raw_payload');
            $table->unsignedBigInteger('device_timestamp')->nullable();
            $table->decimal('server_timestamp', 16, 6)->nullable();
            $table->dateTime('message_timestamp');
            $table->dateTime('received_at')->index();

            // Serves "latest events for a vehicle" and doubles as deduplication:
            // a device cannot send two different messages for the same second,
            // so a retransmitted message is silently ignored on insert.
            $table->unique(['vehicle_id', 'message_timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_events');
    }
};

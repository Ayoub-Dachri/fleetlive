<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per vehicle holding only its latest known state, so the live map
     * never has to scan the event history.
     */
    public function up(): void
    {
        Schema::create('vehicle_current_positions', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('altitude', 8, 2)->nullable();
            $table->unsignedSmallInteger('direction')->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->decimal('hdop', 5, 2)->nullable();
            $table->unsignedSmallInteger('satellites')->nullable();
            $table->boolean('gnss_valid');
            $table->boolean('ignition_status')->nullable();
            $table->boolean('movement_status')->nullable();
            $table->decimal('battery_voltage', 6, 3)->nullable();
            $table->decimal('external_power_voltage', 6, 3)->nullable();
            $table->unsignedSmallInteger('gsm_signal_level')->nullable();
            $table->decimal('mileage', 12, 3)->nullable();
            $table->unsignedBigInteger('device_timestamp')->nullable();
            $table->decimal('server_timestamp', 16, 6)->nullable();
            $table->dateTime('message_timestamp');

            // Microsecond precision: this column is the polling cursor.
            $table->timestamp('updated_at', 6)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_current_positions');
    }
};

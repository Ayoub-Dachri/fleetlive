<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('ident', 64)->unique();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->unsignedInteger('device_type_id')->nullable();
            $table->unsignedInteger('channel_id')->nullable();
            $table->unsignedInteger('protocol_id')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

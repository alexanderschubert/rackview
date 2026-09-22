<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rack_id')
                ->constrained('racks')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('device_type')->default('other');

            $table->unsignedInteger('height_units')->default(1);
            $table->unsignedInteger('start_unit')->nullable();

            $table->string('serial_number')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('mac_address')->nullable();

            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

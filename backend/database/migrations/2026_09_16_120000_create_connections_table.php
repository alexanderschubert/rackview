<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('source_port', 100);
            $table->foreignId('target_device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('target_port', 100);
            $table->string('cable_type', 50)->default('cat6');
            $table->string('cable_color', 30)->nullable();
            $table->unsignedInteger('cable_length')->nullable();
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source_device_id', 'source_port']);
            $table->index(['target_device_id', 'target_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};

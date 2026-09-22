<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port_connections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_device_id')
                ->constrained('devices')
                ->cascadeOnDelete();

            $table->foreignId('source_port_id')
                ->constrained('device_ports')
                ->cascadeOnDelete();

            $table->foreignId('target_device_id')
                ->constrained('devices')
                ->cascadeOnDelete();

            $table->foreignId('target_port_id')
                ->constrained('device_ports')
                ->cascadeOnDelete();

            $table->string('connection_type')->default('direct');
            $table->string('cable_type')->nullable();
            $table->string('cable_length')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index([
                'source_device_id',
                'source_port_id',
            ]);

            $table->index([
                'target_device_id',
                'target_port_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_connections');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Diese Migration wurde nachtraeglich vor
        // create_port_connections_table einsortiert, weil dort
        // Fremdschluessel auf device_ports zeigen. In bestehenden
        // Datenbanken existiert die Tabelle bereits.
        if (Schema::hasTable('device_ports')) {
            return;
        }

        Schema::create('device_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('port_type', 30)->default('ethernet');
            $table->string('speed', 20)->nullable();
            $table->string('status', 20)->default('free');
            $table->string('vlan', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_ports');
    }
};

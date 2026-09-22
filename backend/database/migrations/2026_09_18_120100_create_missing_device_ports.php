<?php

use App\Models\Device;
use App\Services\DevicePortSync;
use Illuminate\Database\Migrations\Migration;

/**
 * Einmaliger Nachtrag: Fuer Geraete, bei denen eine Portanzahl
 * hinterlegt ist, fehlen bisher die einzelnen Port-Datensaetze.
 * Legt sie an - nur ergaenzend, vorhandene Ports bleiben unveraendert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Device::query()
            ->where('network_ports', '>', 0)
            ->each(fn (Device $device) => DevicePortSync::sync($device));
    }

    public function down(): void
    {
        // Bewusst leer: Angelegte Ports koennen inzwischen Verbindungen
        // haben und werden nicht automatisch entfernt.
    }
};

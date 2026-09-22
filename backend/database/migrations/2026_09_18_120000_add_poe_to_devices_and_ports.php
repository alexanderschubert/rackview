<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Angabe wie im Datenblatt: "davon 16 Ports PoE+"
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'poe_ports')) {
                $table->unsignedSmallInteger('poe_ports')->nullable()->after('network_ports');
            }

            if (! Schema::hasColumn('devices', 'poe_type')) {
                $table->string('poe_type', 20)->nullable()->after('poe_ports');
            }
        });

        // Je Port: welcher PoE-Standard anliegt, null = kein PoE
        Schema::table('device_ports', function (Blueprint $table) {
            if (! Schema::hasColumn('device_ports', 'poe')) {
                $table->string('poe', 20)->nullable()->after('speed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_ports', function (Blueprint $table) {
            if (Schema::hasColumn('device_ports', 'poe')) {
                $table->dropColumn('poe');
            }
        });

        Schema::table('devices', function (Blueprint $table) {
            foreach (['poe_type', 'poe_ports'] as $spalte) {
                if (Schema::hasColumn('devices', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};

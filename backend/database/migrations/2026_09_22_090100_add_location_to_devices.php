<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Geraet steht entweder in einem Rack oder an einem Standort.
 *
 * Dafuer wird rack_id freigegeben und location_id kommt dazu. Genau
 * eines von beiden muss gesetzt sein - darum kuemmert sich der
 * DeviceController, nicht die Datenbank: Die Regel soll eine
 * verstaendliche Fehlermeldung ergeben und keinen 500er.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('rack_id')
                ->constrained('locations')
                ->nullOnDelete();
        });

        // Bewusst als reines SQL: change() wuerde den Fremdschluessel
        // mit anfassen, hier soll sich nur die NOT-NULL-Regel aendern.
        DB::statement('ALTER TABLE devices ALTER COLUMN rack_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        // Schlaegt fehl, solange es Geraete ohne Rack gibt - die muessten
        // vorher in ein Rack oder geloescht werden. Bewusst kein stilles
        // Loeschen an dieser Stelle.
        DB::statement('ALTER TABLE devices ALTER COLUMN rack_id SET NOT NULL');
    }
};

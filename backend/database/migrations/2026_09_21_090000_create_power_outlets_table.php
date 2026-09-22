<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Steckplaetze einer Steckdosenleiste oder USV.
 *
 * Je Platz steht entweder ein Geraet aus dem eigenen Bereich
 * (connected_device_id) oder ein freier Text fuer alles, was nicht im
 * Rack steht (external_label) - oder er ist frei.
 *
 * Ein Geraet haengt an genau einem Steckplatz: dafuer sorgt der
 * eindeutige Index auf connected_device_id. In PostgreSQL sind
 * mehrere NULL-Werte darin erlaubt, freie Plaetze stoeren sich also
 * nicht gegenseitig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('power_outlets', function (Blueprint $table) {
            $table->id();

            // Die Leiste selbst
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('label')->nullable();

            // Das angeschlossene Geraet. Wird es geloescht, wird der
            // Platz frei - der Platz selbst bleibt bestehen.
            $table->foreignId('connected_device_id')->nullable()
                ->constrained('devices')->nullOnDelete();

            $table->string('external_label')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['device_id', 'position']);
            $table->unique('connected_device_id');
        });

        Schema::table('devices', function (Blueprint $table) {
            // Anzahl der Steckplaetze; die Plaetze selbst legt
            // PowerOutletSync danach an.
            $table->unsignedSmallInteger('outlet_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('outlet_count');
        });

        Schema::dropIfExists('power_outlets');
    }
};

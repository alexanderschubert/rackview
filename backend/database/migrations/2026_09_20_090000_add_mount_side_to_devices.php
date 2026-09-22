<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einbauseite fuer die Rueckansicht:
 *   full  = volle Tiefe, belegt die HE vorne und hinten (Standard)
 *   front = nur vorne, hinten bleibt die HE frei
 *   rear  = nur hinten, z.B. Steckdosenleiste oder Patchpanel hinten
 *
 * Zwei halbtiefe Geraete duerfen sich damit eine HE teilen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('mount_side', 10)->default('full');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('mount_side');
        });
    }
};

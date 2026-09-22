<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anmeldung ueber einen OIDC-Anbieter (z.B. Authentik).
 *
 * oidc_sub ist die unveraenderliche Kennung des Kontos beim Anbieter.
 * Sie ist eindeutig: zwei RackView-Konten koennen nicht zu demselben
 * Konto beim Anbieter gehoeren. Konten ohne diesen Wert melden sich
 * weiterhin nur mit Passwort an.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_sub')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('oidc_sub');
        });
    }
};

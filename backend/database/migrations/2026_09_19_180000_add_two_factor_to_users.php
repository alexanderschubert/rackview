<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei-Faktor-Anmeldung (TOTP). Der Schluessel liegt verschluesselt mit
 * APP_KEY in der Datenbank, von den Wiederherstellungscodes nur Hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            // Erst gesetzt, wenn ein Code aus der App bestaetigt wurde
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Zuletzt verwendeter Zeitschritt: Jeder Code gilt nur einmal
            $table->bigInteger('two_factor_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ]);
        });
    }
};

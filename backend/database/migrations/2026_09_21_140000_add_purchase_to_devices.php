<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kaufdatum und Garantie je Geraet.
 *
 * Gespeichert wird das Datum, bis zu dem die Garantie laeuft, nicht
 * ihre Dauer: So passen auch verlaengerte oder gebraucht uebernommene
 * Garantien hinein, und "laeuft demnaechst ab" ist ohne Rechnerei zu
 * beantworten. Die Dauer bietet das Formular als Schnellwahl an.
 *
 * Beide Felder sind freiwillig - bestehende Geraete bleiben leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->date('purchase_date')->nullable();
            $table->date('warranty_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['purchase_date', 'warranty_until']);
        });
    }
};

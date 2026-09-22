<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standorte ausserhalb der Racks.
 *
 * Nicht alles haengt im 19-Zoll-Schrank: Access Points an der Decke,
 * das Modem im Flur, die Kamera am Carport. Ein Standort ist das
 * Gegenstueck zum Rack - er hat nur keine Hoeheneinheiten.
 *
 * Wie beim Rack gehoert er zu genau einem Konto; die Trennung der
 * Arbeitsbereiche laeuft ueber user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Getrennte Workspaces: Jedes Rack gehoert genau einem Konto, Geraete,
 * Ports und Verbindungen haengen am Rack.
 *
 * Bestehende Racks bekommt das aelteste Konto; es wird zugleich Admin.
 * Gibt es noch kein Konto, uebernimmt das erste neu angelegte die Racks
 * (siehe App\Services\Workspace::adoptOrphans).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        Schema::table('racks', function (Blueprint $table) {
            // Kaskade als Sicherheitsnetz; die Oberflaeche loescht Konten
            // ueber AccountDeletion, damit auch Bilddateien verschwinden.
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $erstesKonto = DB::table('users')->orderBy('id')->value('id');

        if ($erstesKonto) {
            DB::table('users')->where('id', $erstesKonto)->update(['is_admin' => true]);
            DB::table('racks')->whereNull('user_id')->update(['user_id' => $erstesKonto]);
        }
    }

    public function down(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            if (!Schema::hasColumn('racks', 'name')) {
                $table->string('name')->default('Unbenanntes Rack');
            }

            if (!Schema::hasColumn('racks', 'location')) {
                $table->string('location')->nullable();
            }

            if (!Schema::hasColumn('racks', 'height_units')) {
                $table->unsignedInteger('height_units')->default(42);
            }

            if (!Schema::hasColumn('racks', 'description')) {
                $table->text('description')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('racks', 'name')) {
                $columns[] = 'name';
            }

            if (Schema::hasColumn('racks', 'location')) {
                $columns[] = 'location';
            }

            if (Schema::hasColumn('racks', 'height_units')) {
                $columns[] = 'height_units';
            }

            if (Schema::hasColumn('racks', 'description')) {
                $columns[] = 'description';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};

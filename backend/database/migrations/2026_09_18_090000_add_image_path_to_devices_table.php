<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'image_path')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            // Relativer Pfad innerhalb der oeffentlichen Platte,
            // z.B. "device-images/12/udm-pro.webp"
            $table->string('image_path')->nullable()->after('device_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('devices', 'image_path')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Diese Migration wurde nachtraeglich hinter
        // create_devices_table einsortiert – vorher scheiterte sie
        // auf einer frischen Datenbank, weil devices noch fehlte.
        if (! Schema::hasTable('devices')) {
            return;
        }

        $columns = [];

        if (!Schema::hasColumn('devices', 'vlan')) {
            $columns[] = 'vlan';
        }

        if (!Schema::hasColumn('devices', 'switch_port')) {
            $columns[] = 'switch_port';
        }

        if (!Schema::hasColumn('devices', 'uplink_port')) {
            $columns[] = 'uplink_port';
        }

        if (!Schema::hasColumn('devices', 'network_ports')) {
            $columns[] = 'network_ports';
        }

        if (!Schema::hasColumn('devices', 'notes')) {
            $columns[] = 'notes';
        }

        if (!empty($columns)) {
            Schema::table('devices', function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    switch ($column) {
                        case 'vlan':
                            $table->string('vlan')->nullable();
                            break;

                        case 'switch_port':
                            $table->string('switch_port')->nullable();
                            break;

                        case 'uplink_port':
                            $table->string('uplink_port')->nullable();
                            break;

                        case 'network_ports':
                            $table->unsignedInteger('network_ports')->nullable();
                            break;

                        case 'notes':
                            $table->text('notes')->nullable();
                            break;
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('devices')) {
            return;
        }

        $columns = [];

        foreach ([
            'vlan',
            'switch_port',
            'uplink_port',
            'network_ports',
            'notes',
        ] as $column) {
            if (Schema::hasColumn('devices', $column)) {
                $columns[] = $column;
            }
        }

        if (!empty($columns)) {
            Schema::table('devices', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};

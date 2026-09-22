<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DevicePort;
use Illuminate\Support\Facades\DB;

/**
 * Haelt die Port-Datensaetze eines Geraets passend zu seinen Angaben.
 *
 * - Fehlende Ports werden bis zur Portanzahl (network_ports) als
 *   "Port 1", "Port 2", ... angelegt.
 * - Geloescht wird nie etwas: Ports koennen Verbindungen haben, und eine
 *   kleinere Portanzahl ist eher ein Tippfehler als eine Absicht.
 * - Die PoE-Angabe des Geraets (poe_ports / poe_type) gilt fuer die
 *   automatisch benannten Ports "Port N" - die ersten poe_ports davon.
 *   Anders benannte Ports ("Uplink", "SFP 1") bleiben unberuehrt.
 */
class DevicePortSync
{
    /** Entspricht der Obergrenze in der Validierung von network_ports */
    private const MAX_PORTS = 1000;

    public static function sync(Device $device, bool $applyPoe = false): void
    {
        DB::transaction(function () use ($device, $applyPoe) {
            self::createMissingPorts($device);

            if ($applyPoe) {
                self::applyPoe($device);
            }
        });
    }

    private static function createMissingPorts(Device $device): void
    {
        $soll = min((int) $device->network_ports, self::MAX_PORTS);

        if ($soll <= 0) {
            return;
        }

        $namen = $device->ports()->pluck('name')->all();
        $vorhanden = array_flip($namen);
        $anzahl = count($namen);

        for ($nummer = 1; $anzahl < $soll && $nummer <= self::MAX_PORTS; $nummer++) {
            $name = "Port {$nummer}";

            if (isset($vorhanden[$name])) {
                continue;
            }

            $device->ports()->create([
                'name' => $name,
                'port_type' => 'ethernet',
                'status' => 'free',
                'poe' => self::poeFor($device, $nummer),
            ]);

            $anzahl++;
        }
    }

    private static function applyPoe(Device $device): void
    {
        foreach ($device->ports()->get() as $port) {
            $nummer = self::autoNumber($port);

            if ($nummer === null) {
                continue;
            }

            $poe = self::poeFor($device, $nummer);

            if ($port->poe !== $poe) {
                $port->update(['poe' => $poe]);
            }
        }
    }

    private static function poeFor(Device $device, int $nummer): ?string
    {
        $anzahl = (int) $device->poe_ports;

        if ($anzahl <= 0 || $nummer > $anzahl) {
            return null;
        }

        return $device->poe_type ?: 'poe';
    }

    /** "Port 12" -> 12, alles andere -> null */
    private static function autoNumber(DevicePort $port): ?int
    {
        return preg_match('/^Port (\d+)$/', (string) $port->name, $treffer)
            ? (int) $treffer[1]
            : null;
    }
}

<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Haelt die Steckplaetze eines Geraets passend zu outlet_count.
 *
 * Angelegt wird von unten aufgefuellt (Platz 1, 2, 3 ...). Wird die
 * Anzahl verkleinert, verschwinden nur **freie** Plaetze am Ende -
 * ein belegter Platz bleibt stehen, sonst waere die Zuordnung eines
 * Geraets stillschweigend weg. Er faellt erst, wenn er freigemacht
 * und die Anzahl erneut gespeichert wird.
 */
class PowerOutletSync
{
    /** Genug fuer jede Leiste; verhindert Ausrutscher bei der Eingabe */
    public const MAX_OUTLETS = 100;

    /**
     * Nur diese Geraetetypen haben Steckplaetze. Wird ein Geraet auf
     * einen anderen Typ umgestellt, fallen seine freien Plaetze hier
     * von selbst weg - belegte bleiben sichtbar, bis sie jemand
     * ausgesteckt hat.
     */
    public const CAPABLE_TYPES = ['pdu', 'ups'];

    public static function sync(Device $device): void
    {
        DB::transaction(function () use ($device) {
            $soll = in_array($device->device_type, self::CAPABLE_TYPES, true)
                ? min((int) $device->outlet_count, self::MAX_OUTLETS)
                : 0;

            $vorhanden = $device->outlets()->orderBy('position')->get()->keyBy('position');

            for ($platz = 1; $platz <= $soll; $platz++) {
                if (! $vorhanden->has($platz)) {
                    $device->outlets()->create(['position' => $platz]);
                }
            }

            foreach ($vorhanden as $platz => $steckplatz) {
                if ($platz > $soll && self::istFrei($steckplatz)) {
                    $steckplatz->delete();
                }
            }
        });
    }

    private static function istFrei($steckplatz): bool
    {
        return ! $steckplatz->connected_device_id
            && ! $steckplatz->external_label
            && ! $steckplatz->notes
            && ! $steckplatz->label;
    }
}

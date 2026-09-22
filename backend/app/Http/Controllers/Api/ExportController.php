<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\PortConnection;
use App\Models\Rack;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Vollstaendiger Datenstand in einer Antwort: Racks mit Geraeten und
 * deren Ports, dazu alle Port-Verbindungen. Grundlage fuer die
 * JSON-Sicherung im Export - die Oberflaeche laedt Ports sonst nur
 * einzeln je Geraet.
 *
 * Hochgeladene Geraetebilder sind nur als Pfad enthalten; die Dateien
 * selbst sichert der Backup-Container.
 *
 * Fassung 2 (2026-09-21): je Geraet kommen die Steckplaetze dazu;
 * seither ausserdem Kaufdatum und Garantie.
 *
 * Fassung 3 (2026-09-22): Standorte ausserhalb der Racks, jeder mit
 * seinen Geraeten. Diesmal muss die Fassung hoch: Eine aeltere
 * RackView-Fassung kennt die Standorte nicht und wuerde ihre Geraete
 * stillschweigend verlieren - besser, sie lehnt die Datei ab.
 */
class ExportController extends Controller
{
    /** Erhoehen, wenn sich der Aufbau der Datei aendert */
    private const FORMAT_VERSION = 3;

    public function __invoke(): JsonResponse
    {
        // Nur der eigene Workspace
        $racks = Rack::query()
            ->ownedBy(Auth::id())
            ->with([
                'devices' => fn ($query) => $query->orderByDesc('start_unit'),
                'devices.ports',
                // Steckplaetze der Leisten und USVs; sie verweisen ueber
                // connected_device_id auf andere Geraete der Datei.
                'devices.outlets',
            ])
            ->orderBy('name')
            ->get();

        // Standorte: dieselben Geraete, nur ohne Hoeheneinheiten
        $locations = Location::query()
            ->ownedBy(Auth::id())
            ->with([
                'devices' => fn ($query) => $query->orderBy('name'),
                'devices.ports',
                'devices.outlets',
            ])
            ->orderBy('name')
            ->get();

        // Ports natuerlich sortieren: "Port 2" vor "Port 10"
        foreach ([$racks, $locations] as $behaelter) {
            foreach ($behaelter as $eintrag) {
                foreach ($eintrag->devices as $device) {
                    $device->setRelation(
                        'ports',
                        $device->ports->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
                    );
                }
            }
        }

        return response()->json([
            'format' => 'rackview-export',
            'version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'racks' => $racks,
            'locations' => $locations,
            'connections' => PortConnection::query()->ownedBy(Auth::id())->orderBy('id')->get(),
        ]);
    }
}

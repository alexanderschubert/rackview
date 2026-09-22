<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PowerOutlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Steckplaetze einer Steckdosenleiste oder USV.
 *
 * Wie viele es gibt, steht am Geraet (outlet_count) und wird beim
 * Speichern des Geraets abgeglichen. Hier wird nur belegt und
 * freigemacht.
 *
 * Ein Geraet haengt an genau einem Platz. Steckt es schon woanders,
 * antwortet update() mit 409 und nennt den Ort - erst mit
 * "force": true wird umgesteckt. So verschwindet eine Zuordnung nie
 * unbemerkt.
 */
class PowerOutletController extends Controller
{
    public function index(Device $device): JsonResponse
    {
        return response()->json($this->liste($device));
    }

    public function update(Request $request, Device $device, PowerOutlet $outlet): JsonResponse
    {
        $this->ensureOutletBelongsToDevice($device, $outlet);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'connected_device_id' => ['nullable', 'integer'],
            'external_label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'force' => ['nullable', 'boolean'],
        ]);

        $zielId = $data['connected_device_id'] ?? null;
        $ziel = null;

        if ($zielId) {
            $ziel = Device::query()->ownedBy(Auth::id())->find($zielId);

            if (! $ziel) {
                abort(422, 'Dieses Gerät gibt es nicht.');
            }

            if ((int) $ziel->id === (int) $device->id) {
                abort(422, 'Eine Leiste kann nicht in sich selbst stecken.');
            }

            // Steckt das Geraet schon woanders?
            $bisher = PowerOutlet::query()
                ->with('device:id,name')
                ->where('connected_device_id', $ziel->id)
                ->where('id', '!=', $outlet->id)
                ->first();

            if ($bisher && ! ($data['force'] ?? false)) {
                return response()->json([
                    // sprintf statt Einbettung: die typografischen
                    // Anfuehrungszeichen sollen keine Zeichenkette beenden.
                    'message' => sprintf(
                        '„%s“ steckt schon in „%s“ auf Platz %d.',
                        $ziel->name,
                        $bisher->device->name,
                        $bisher->position
                    ),
                    'belegt' => [
                        'geraet' => $ziel->name,
                        'leiste' => $bisher->device->name,
                        'position' => $bisher->position,
                    ],
                ], 409);
            }
        }

        DB::transaction(function () use ($outlet, $ziel, $data) {
            // Umstecken: den alten Platz zuerst freimachen, sonst
            // verhindert der eindeutige Index das Speichern.
            if ($ziel) {
                PowerOutlet::query()
                    ->where('connected_device_id', $ziel->id)
                    ->where('id', '!=', $outlet->id)
                    ->update(['connected_device_id' => null]);
            }

            $outlet->fill([
                'label' => $data['label'] ?? null,
                'notes' => $data['notes'] ?? null,
                'connected_device_id' => $ziel?->id,
                // Entweder ein Geraet aus dem Bereich oder freier Text -
                // beides zusammen waere nicht eindeutig.
                'external_label' => $ziel ? null : ($data['external_label'] ?? null),
            ]);

            $outlet->save();
        });

        return response()->json($this->liste($outlet->device));
    }

    /**
     * Liste aller Plaetze einer Leiste, aufsteigend nach Platznummer.
     * Das angeschlossene Geraet kommt mit Namen mit, damit die
     * Oberflaeche nicht jedes einzeln nachladen muss.
     */
    private function liste(Device $device): array
    {
        return $device->outlets()
            ->with('connectedDevice:id,name,rack_id,device_type')
            ->orderBy('position')
            ->get()
            ->map(fn (PowerOutlet $outlet) => [
                'id' => $outlet->id,
                'position' => $outlet->position,
                'label' => $outlet->label,
                'notes' => $outlet->notes,
                'external_label' => $outlet->external_label,
                'connected_device_id' => $outlet->connected_device_id,
                'connected_device' => $outlet->connectedDevice?->only(['id', 'name', 'rack_id', 'device_type']),
            ])
            ->all();
    }

    private function ensureOutletBelongsToDevice(Device $device, PowerOutlet $outlet): void
    {
        abort_unless((int) $outlet->device_id === (int) $device->id, 404);
    }
}

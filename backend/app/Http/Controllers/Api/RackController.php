<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Rack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Racks des angemeldeten Kontos. Fremde Racks in der URL loest bereits
 * die Route-Bindung als 404 auf (AppServiceProvider).
 */
class RackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Rack::query()->ownedBy($request->user()->id)->orderBy('created_at', 'desc')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'height_units' => ['required', 'integer', 'min:1', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

        // Besitzer immer aus der Anmeldung, nie aus der Anfrage
        $rack = $request->user()->racks()->create($validated);

        return response()->json($rack, 201);
    }

    public function show(Rack $rack): JsonResponse
    {
        return response()->json($rack);
    }

    public function update(Request $request, Rack $rack): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'height_units' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

        $rack->update($validated);

        return response()->json($rack);
    }

    public function destroy(Rack $rack): JsonResponse
    {
        DB::transaction(function () use ($rack) {
            // Geraete einzeln loeschen statt der Kaskade in der Datenbank zu
            // ueberlassen: Nur so laeuft ihr deleting-Ereignis, das die
            // hochgeladenen Bilddateien mit entfernt. Ports und Verbindungen
            // fallen weiterhin per Kaskade.
            $rack->devices()->get()->each(fn (Device $device) => $device->delete());

            $rack->delete();
        });

        return response()->json([
            'message' => 'Rack erfolgreich gelöscht.',
        ]);
    }
}

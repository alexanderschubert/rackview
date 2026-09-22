<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Standorte ausserhalb der Racks. Fremde Standorte in der URL loest
 * bereits die Route-Bindung als 404 auf (AppServiceProvider).
 */
class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Location::query()
                ->ownedBy($request->user()->id)
                ->withCount('devices')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        // Besitzer immer aus der Anmeldung, nie aus der Anfrage
        $location = $request->user()->locations()->create($validated);

        return response()->json($location->loadCount('devices'), 201);
    }

    public function show(Location $location): JsonResponse
    {
        return response()->json($location->loadCount('devices'));
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $location->update($validated);

        return response()->json($location->loadCount('devices'));
    }

    public function destroy(Location $location): JsonResponse
    {
        DB::transaction(function () use ($location) {
            // Wie beim Rack: Geraete einzeln loeschen, damit ihr
            // deleting-Ereignis laeuft und die hochgeladenen Bilder
            // mit verschwinden. Ports und Verbindungen fallen per Kaskade.
            $location->devices()->get()->each(fn (Device $device) => $device->delete());

            $location->delete();
        });

        return response()->json([
            'message' => 'Standort erfolgreich gelöscht.',
        ]);
    }
}

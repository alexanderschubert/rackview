<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeviceImageController extends Controller
{
    /**
     * Ein Frontbild fuer ein Geraet hochladen. Ein bereits vorhandenes
     * Bild wird dabei ersetzt und die alte Datei geloescht.
     */
    public function store(Request $request, Device $device)
    {
        $request->validate([
            'image' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,webp,avif',
                'max:4096',
            ],
        ], [
            'image.required' => 'Es wurde keine Datei übermittelt.',
            'image.image' => 'Die Datei ist kein Bild.',
            'image.mimes' => 'Erlaubt sind JPEG, PNG, WebP und AVIF.',
            'image.max' => 'Das Bild darf höchstens 4 MB groß sein.',
        ]);

        $alt = $device->image_path;

        $datei = $request->file('image');
        $name = Str::uuid().'.'.$datei->extension();

        $pfad = $datei->storeAs("device-images/{$device->id}", $name, 'public');

        $device->update(['image_path' => $pfad]);

        if ($alt && $alt !== $pfad) {
            Storage::disk('public')->delete($alt);
        }

        return response()->json($device->fresh());
    }

    /**
     * Das hinterlegte Bild entfernen. Ohne Bild bleibt die
     * gezeichnete Frontblende als Darstellung uebrig.
     */
    public function destroy(Device $device)
    {
        if (! $device->image_path) {
            return response()->json([
                'message' => 'Für dieses Gerät ist kein Bild hinterlegt.',
            ], 404);
        }

        Storage::disk('public')->delete($device->image_path);

        $device->update(['image_path' => null]);

        $this->removeEmptyDirectory($device);

        return response()->json($device->fresh());
    }

    /**
     * Nach dem Entfernen bleibt sonst ein leeres Verzeichnis
     * device-images/{id} zurueck.
     */
    private function removeEmptyDirectory(Device $device): void
    {
        $verzeichnis = "device-images/{$device->id}";
        $platte = Storage::disk('public');

        if ($platte->exists($verzeichnis) && empty($platte->allFiles($verzeichnis))) {
            $platte->deleteDirectory($verzeichnis);
        }
    }
}

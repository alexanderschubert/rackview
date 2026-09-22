<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PortConnection;
use App\Models\Rack;
use App\Services\DevicePortSync;
use App\Services\PowerOutletSync;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    /**
     * Alle Geraete des Kontos - im Rack wie am Standort. Die
     * Geraete-, IP- und VLAN-Seiten lesen diese Liste; frueher haben
     * sie je Rack einzeln gefragt.
     */
    public function index(Request $request)
    {
        $devices = Device::query()
            ->ownedBy($request->user()->id)
            ->with([
                'ports:id,device_id,name,port_type,poe,status',
                'outlets:id,device_id,position,connected_device_id,external_label',
            ])
            ->orderBy('name')
            ->get();

        $this->markiereBelegtePorts($devices);

        return response()->json($devices);
    }

    /** Geraete eines Racks, von oben nach unten */
    public function forRack(Rack $rack)
    {
        $devices = $rack->devices()
            // Die Blende im Rack zeichnet jede Buchse einzeln: eine
            // RJ45-Buchse anders als einen SFP-Kaefig, eine belegte
            // anders als eine freie. Dafuer reichen diese Felder.
            ->with([
                'ports:id,device_id,name,port_type,poe,status',
                'outlets:id,device_id,position,connected_device_id,external_label',
            ])
            ->orderBy('start_unit')
            ->orderBy('name')
            ->get();

        $this->markiereBelegtePorts($devices);

        return response()->json($devices);
    }

    /**
     * Setzt an jedem Port, ob eine Verbindung an ihm haengt.
     *
     * Bewusst eine einzige Abfrage fuer das ganze Rack: eine je Geraet
     * waere bei zwanzig Geraeten zwanzigmal derselbe Weg zur Datenbank.
     */
    private function markiereBelegtePorts(Collection $devices): void
    {
        $ids = $devices
            ->flatMap(fn (Device $device) => $device->ports->pluck('id')->all())
            ->all();

        if ($ids === []) {
            return;
        }

        $verbunden = [];

        PortConnection::query()
            ->where(fn ($frage) => $frage
                ->whereIn('source_port_id', $ids)
                ->orWhereIn('target_port_id', $ids))
            ->get(['source_port_id', 'target_port_id'])
            ->each(function (PortConnection $verbindung) use (&$verbunden): void {
                $verbunden[(int) $verbindung->source_port_id] = true;
                $verbunden[(int) $verbindung->target_port_id] = true;
            });

        foreach ($devices as $device) {
            foreach ($device->ports as $port) {
                $port->setAttribute('belegt', isset($verbunden[(int) $port->id]));
            }
        }
    }

    public function store(Request $request)
    {
        [$data, $zielRack] = $this->validatedData($request);

        // Am Standort gibt es keine Hoeheneinheiten, also auch nichts
        // zu pruefen - im Rack schon.
        if ($zielRack) {
            $this->validateRackPosition($data, $zielRack);
            $this->validateNoOverlap($data, $zielRack);
        }

        $device = Device::query()->create($data);

        DevicePortSync::sync($device, applyPoe: true);
        PowerOutletSync::sync($device);

        return response()->json($device, 201);
    }

    public function show(Device $device)
    {
        return response()->json($device);
    }

    public function update(Request $request, Device $device)
    {
        [$data, $zielRack] = $this->validatedData($request, $device);

        if ($zielRack) {
            $this->validateRackPosition($data, $zielRack);
            $this->validateNoOverlap($data, $zielRack, $device);
        }

        $device->fill($data);

        // PoE nur neu verteilen, wenn sich die Angabe geaendert hat -
        // sonst wuerden Einzelaenderungen an Ports ueberschrieben.
        $poeGeaendert = $device->isDirty(['poe_ports', 'poe_type']);

        $device->save();

        DevicePortSync::sync($device, applyPoe: $poeGeaendert);
        PowerOutletSync::sync($device);

        return response()->json($device->fresh());
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return response()->json([
            'message' => 'Gerät erfolgreich gelöscht.',
        ]);
    }

    /**
     * Nur die Position aendern - fuer Drag & Drop im Rack.
     *
     * Bewusst getrennt von update(): Das verlangt alle Felder, und ein
     * Verschieben wuerde sonst einen veralteten Stand von Name, IP usw.
     * zurueckschreiben, falls jemand das Geraet inzwischen bearbeitet hat.
     */
    public function move(Request $request, Device $device)
    {
        $data = $request->validate([
            'rack_id' => ['sometimes', 'nullable', 'integer', Rule::exists('racks', 'id')->where('user_id', $request->user()->id)],
            'start_unit' => ['required', 'integer', 'min:1'],
        ]);

        // Ohne Angabe bleibt es im bisherigen Rack. Ein Geraet am
        // Standort hat keine Position - dorthin wird nicht gezogen.
        $zielRack = ! empty($data['rack_id'])
            ? Rack::query()->ownedBy($request->user()->id)->findOrFail($data['rack_id'])
            : $device->rack;

        abort_if(
            $zielRack === null,
            422,
            'Dieses Gerät steht an einem Standort und hat keine Position im Rack.'
        );

        $position = [
            'start_unit' => (int) $data['start_unit'],
            'height_units' => (int) $device->height_units,
            // Die Einbauseite aendert sich beim Verschieben nicht
            'mount_side' => $device->mount_side ?? 'full',
        ];

        $this->validateRackPosition($position, $zielRack);
        $this->validateNoOverlap($position, $zielRack, $device);

        $device->update([
            'rack_id' => $zielRack->id,
            'location_id' => null,
            'start_unit' => $position['start_unit'],
        ]);

        return response()->json($device->fresh());
    }

    /**
     * Geprueftes Geraet plus Ziel-Rack.
     *
     * Ein Geraet steht entweder in einem Rack - dann mit Position und
     * Hoehe - oder an einem Standort. Beides zugleich waere
     * widerspruechlich, keins von beidem heimatlos.
     *
     * @return array{0: array<string, mixed>, 1: Rack|null}
     */
    private function validatedData(Request $request, ?Device $device = null): array
    {
        $data = $request->validate([
            'rack_id' => ['nullable', 'integer', Rule::exists('racks', 'id')->where('user_id', $request->user()->id)],

            // Der Standort ausserhalb der Racks
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('user_id', $request->user()->id)],

            'name' => ['required', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],

            'device_type' => [
                'required',
                Rule::in([
                    'server',
                    'switch',
                    'router',
                    'firewall',
                    'nas',
                    'patchpanel',
                    'pdu',
                    'ups',
                    'ap',
                    'other',
                ]),
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'active',
                    'planned',
                    'maintenance',
                    'retired',
                ]),
            ],

            // Im Rack Pflicht, am Standort ohne Bedeutung - deshalb
            // hier nur die Form, die Pflicht weiter unten.
            'height_units' => ['nullable', 'integer', 'min:1', 'max:50'],
            'start_unit' => ['nullable', 'integer', 'min:1'],

            // Einbauseite: volle Tiefe, nur vorne oder nur hinten
            'mount_side' => ['nullable', Rule::in(['full', 'front', 'rear'])],

            'serial_number' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:255'],

            'vlan' => ['nullable', 'string', 'max:255'],
            'switch_port' => ['nullable', 'string', 'max:255'],
            'uplink_port' => ['nullable', 'string', 'max:255'],
            'network_ports' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'poe_ports' => ['nullable', 'integer', 'min:0', 'lte:network_ports'],
            'poe_type' => ['nullable', Rule::in(['passive', 'poe', 'poe+', 'poe++'])],

            // Steckplaetze einer Steckdosenleiste oder USV
            'outlet_count' => ['nullable', 'integer', 'min:0', 'max:'.PowerOutletSync::MAX_OUTLETS],

            // Kauf und Garantie. Ein Kaufdatum in der Zukunft ist
            // erlaubt - bestellte Geraete werden oft vorab angelegt.
            'purchase_date' => ['nullable', 'date'],

            // Die Garantie darf nicht vor dem Kauf enden. Die Regel
            // gilt nur, wenn ein Kaufdatum dasteht: Wer nur weiss, bis
            // wann die Garantie laeuft, soll das eintragen duerfen.
            'warranty_until' => $request->filled('purchase_date')
                ? ['nullable', 'date', 'after_or_equal:purchase_date']
                : ['nullable', 'date'],

            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $imRack = ! empty($data['rack_id']);
        $amStandort = ! empty($data['location_id']);

        abort_if(
            $imRack === $amStandort,
            422,
            'Ein Gerät steht entweder in einem Rack oder an einem Standort – nicht in beidem und nicht nirgends.'
        );

        if ($amStandort) {
            // Ohne Rack gibt es keine Hoeheneinheiten und keine Seite
            $data['rack_id'] = null;
            $data['start_unit'] = null;
            $data['height_units'] = 1;
            $data['mount_side'] = 'full';

            return [$data, null];
        }

        // Im Rack ist die Position Pflicht
        $request->validate([
            'start_unit' => ['required', 'integer', 'min:1'],
            'height_units' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $data['location_id'] = null;

        // Ohne Angabe bleibt die bisherige Einbauseite stehen
        $data['mount_side'] = $data['mount_side'] ?? $device?->mount_side ?? 'full';

        $zielRack = Rack::query()
            ->ownedBy($request->user()->id)
            ->findOrFail($data['rack_id']);

        return [$data, $zielRack];
    }

    private function validateRackPosition(array $data, Rack $rack): void
    {
        $startUnit = (int) $data['start_unit'];
        $heightUnits = (int) $data['height_units'];
        $endUnit = $startUnit + $heightUnits - 1;

        if ($startUnit < 1 || $endUnit > $rack->height_units) {
            abort(422, sprintf(
                'Das Gerät liegt außerhalb des Racks. Erlaubt: HE 1 bis %d.',
                $rack->height_units
            ));
        }
    }

    private function validateNoOverlap(
        array $data,
        Rack $rack,
        ?Device $ignoreDevice = null
    ): void {
        $startUnit = (int) $data['start_unit'];
        $endUnit = $startUnit + (int) $data['height_units'] - 1;
        $seite = $data['mount_side'] ?? 'full';

        $devices = $rack->devices()
            ->when($ignoreDevice, function ($query) use ($ignoreDevice) {
                $query->where('id', '!=', $ignoreDevice->id);
            })
            ->get();

        foreach ($devices as $existingDevice) {
            $existingStart = (int) $existingDevice->start_unit;
            $existingEnd = $existingStart + (int) $existingDevice->height_units - 1;

            $overlaps = $startUnit <= $existingEnd
                && $endUnit >= $existingStart;

            if ($overlaps && self::seitenKollidieren($seite, $existingDevice->mount_side ?? 'full')) {
                abort(422, sprintf(
                    'HE-Konflikt mit Gerät "%s" bei HE %d–%d (%s).',
                    $existingDevice->name,
                    $existingStart,
                    $existingEnd,
                    self::seitenText($existingDevice->mount_side ?? 'full')
                ));
            }
        }
    }

    /**
     * Volle Tiefe steht jedem im Weg; zwei halbtiefe Geraete nur dann,
     * wenn sie auf derselben Seite sitzen.
     */
    private static function seitenKollidieren(string $eine, string $andere): bool
    {
        return $eine === 'full' || $andere === 'full' || $eine === $andere;
    }

    private static function seitenText(string $seite): string
    {
        return match ($seite) {
            'front' => 'nur vorne',
            'rear' => 'nur hinten',
            default => 'volle Tiefe',
        };
    }

}

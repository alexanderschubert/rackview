<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Location;
use App\Models\PortConnection;
use App\Models\Rack;
use App\Services\DevicePortSync;
use App\Services\PowerOutletSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Gegenstueck zum Export: eine gesicherte JSON-Datei wieder einlesen.
 *
 * Zwei Betriebsarten:
 *   ergaenzen  Racks und Standorte der Datei kommen zusaetzlich
 *              hinein. Ist ein Name schon vergeben, wird angehaengt:
 *              "Keller (2)".
 *   ersetzen   Der eigene Bereich wird zuerst vollstaendig geleert.
 *
 * Beide Wege schreiben nur in den Bereich des angemeldeten Kontos.
 *
 * Aufbau in zwei Schritten: planen() liest die Datei und baut einen
 * vollstaendigen Plan, ohne irgendetwas zu schreiben. Die Vorschau
 * zeigt genau diesen Plan - was sie ankuendigt, passiert danach auch.
 *
 * Einzelne unbrauchbare Eintraege lassen den ganzen Import nicht
 * scheitern: sie werden uebersprungen und in "hinweise" benannt.
 * Ist dagegen der Rahmen der Datei falsch (Format, Version, Aufbau),
 * wird gar nichts angefasst.
 */
class ImportController extends Controller
{
    /** Hoechste Fassung, die gelesen werden kann (siehe ExportController) */
    private const FORMAT_VERSION = 3;

    private const MAX_RACKS = 500;
    private const MAX_ORTE = 500;
    private const MAX_GERAETE = 5000;
    private const MAX_PORTS = 1000;
    private const MAX_VERBINDUNGEN = 20000;

    /**
     * Vorschau. Schreibt nichts, beantwortet nur: was waere danach da,
     * was ginge verloren, was wird uebersprungen.
     */
    public function preview(Request $request): JsonResponse
    {
        $plan = $this->planen($request);

        return response()->json($this->zusammenfassung($plan));
    }

    /**
     * Durchfuehren. Alles oder nichts - eine Transaktion.
     */
    public function store(Request $request): JsonResponse
    {
        $plan = $this->planen($request);

        $ergebnis = DB::transaction(function () use ($plan) {
            if ($plan['modus'] === 'ersetzen') {
                $this->bereichLeeren();
            }

            return $this->schreiben($plan);
        });

        return response()->json([
            'message' => 'Import abgeschlossen.',
            'modus' => $plan['modus'],
            'angelegt' => $ergebnis,
            'hinweise' => $plan['hinweise'],
        ]);
    }

    // -------------------------------------------------------------
    // Planen
    // -------------------------------------------------------------

    /**
     * Liest die Anfrage und baut daraus den vollstaendigen Plan.
     * Wirft bei kaputtem Rahmen eine Validierungsausnahme (422).
     */
    private function planen(Request $request): array
    {
        $request->validate([
            'modus' => ['required', Rule::in(['ergaenzen', 'ersetzen'])],
            'daten' => ['required', 'array'],
            'daten.format' => ['required', 'string', Rule::in(['rackview-export'])],
            'daten.version' => ['required', 'integer', 'min:1', 'max:'.self::FORMAT_VERSION],
            'daten.racks' => ['present', 'array', 'max:'.self::MAX_RACKS],
            // Aeltere Sicherungen (Fassung 1 und 2) kennen keine Standorte
            'daten.locations' => ['nullable', 'array', 'max:'.self::MAX_ORTE],
            'daten.connections' => ['nullable', 'array', 'max:'.self::MAX_VERBINDUNGEN],
        ], [
            'daten.format.in' => 'Das ist keine RackView-Sicherung.',
            'daten.version.max' => 'Die Datei stammt aus einer neueren Fassung von RackView.',
            'daten.racks.max' => 'Die Datei enthält zu viele Racks.',
            'daten.locations.max' => 'Die Datei enthält zu viele Standorte.',
        ]);

        $modus = $request->input('modus');
        $daten = $request->input('daten');

        $hinweise = [];
        $racks = [];
        $orte = [];

        // Belegte Namen: beim Ersetzen ist der Bereich gleich leer,
        // dann muss nichts umbenannt werden. Racks und Standorte zaehlen
        // getrennt - ein Rack "Keller" und ein Standort "Keller" stoeren
        // sich nicht.
        $belegt = $modus === 'ersetzen'
            ? []
            : Rack::query()->ownedBy(Auth::id())->pluck('name')->all();
        $belegt = array_flip(array_map('mb_strtolower', $belegt));

        $belegteOrte = $modus === 'ersetzen'
            ? []
            : Location::query()->ownedBy(Auth::id())->pluck('name')->all();
        $belegteOrte = array_flip(array_map('mb_strtolower', $belegteOrte));

        // Zuordnung alte ID -> Stelle im Plan, fuer die Verbindungen
        $geraetStelle = [];
        $portStelle = [];
        $anzahlGeraete = 0;

        foreach ($daten['racks'] as $nr => $rohRack) {
            $rack = $this->rackPruefen($rohRack, $nr, $hinweise);

            if ($rack === null) {
                continue;
            }

            $rack['name'] = $this->freierName($rack['name'], $belegt, 'Rack', $hinweise);
            $belegt[mb_strtolower($rack['name'])] = true;

            $rack['devices'] = $this->geraeteEinlesen(
                $rohRack['devices'] ?? [],
                $rack,
                $rack['name'],
                'r'.count($racks),
                $geraetStelle,
                $portStelle,
                $anzahlGeraete,
                $hinweise
            );

            $racks[] = $rack;

            // Erst das angefangene Rack in den Plan, dann aufhoeren -
            // sonst zeigten die Ports seiner Geraete beim Schreiben
            // auf ein Rack, das es im Plan gar nicht gibt.
            if ($anzahlGeraete >= self::MAX_GERAETE) {
                break;
            }
        }

        // Standorte: gleicher Ablauf, nur ohne Hoeheneinheiten. Fehlen
        // sie in der Datei (Fassung 1 und 2), bleibt die Liste leer.
        foreach ($daten['locations'] ?? [] as $nr => $rohOrt) {
            if ($anzahlGeraete >= self::MAX_GERAETE) {
                break;
            }

            $ort = $this->ortPruefen($rohOrt, $nr, $hinweise);

            if ($ort === null) {
                continue;
            }

            $ort['name'] = $this->freierName($ort['name'], $belegteOrte, 'Standort', $hinweise);
            $belegteOrte[mb_strtolower($ort['name'])] = true;

            $ort['devices'] = $this->geraeteEinlesen(
                $rohOrt['devices'] ?? [],
                null,
                $ort['name'],
                'o'.count($orte),
                $geraetStelle,
                $portStelle,
                $anzahlGeraete,
                $hinweise
            );

            $orte[] = $ort;
        }

        $this->steckplaetzeVerknuepfen($racks, $orte, $geraetStelle, $hinweise);

        return [
            'modus' => $modus,
            'racks' => $racks,
            'standorte' => $orte,
            'verbindungen' => $this->verbindungenPruefen(
                $daten['connections'] ?? [],
                $geraetStelle,
                $portStelle,
                $hinweise
            ),
            'hinweise' => $hinweise,
        ];
    }

    /**
     * Geraete eines Behaelters einlesen - eines Racks oder eines
     * Standorts. $rack ist beim Standort null; $stelle ist die Kennung
     * des Behaelters im Plan ("r0", "o2"), damit Steckplaetze und
     * Verbindungen spaeter wieder hinfinden.
     *
     * @return list<array<string, mixed>>
     */
    private function geraeteEinlesen(
        array $rohListe,
        ?array $rack,
        string $wo,
        string $stelle,
        array &$geraetStelle,
        array &$portStelle,
        int &$anzahlGeraete,
        array &$hinweise
    ): array {
        $geraete = [];

        foreach ($rohListe as $nr => $rohGeraet) {
            if ($anzahlGeraete >= self::MAX_GERAETE) {
                $hinweise[] = 'Mehr als '.self::MAX_GERAETE.' Geräte: der Rest der Datei wurde übergangen.';
                break;
            }

            $geraet = $this->geraetPruefen($rohGeraet, $rack, $wo, $nr, $hinweise);

            if ($geraet === null) {
                continue;
            }

            $geraet['ports'] = $this->portsPruefen($rohGeraet, $geraet['name'], $hinweise);
            $geraet['outlets'] = $this->steckplaetzePruefen($rohGeraet, $geraet['name'], $hinweise);

            $platz = [$stelle, count($geraete)];
            $geraete[] = $geraet;
            $anzahlGeraete++;

            if (isset($rohGeraet['id'])) {
                $geraetStelle[(string) $rohGeraet['id']] = $platz;
            }

            foreach ($geraet['ports'] as $portNr => $port) {
                if (isset($port['__id'])) {
                    $portStelle[(string) $port['__id']] = [...$platz, $portNr];
                }
            }
        }

        return $geraete;
    }

    private function ortPruefen(mixed $roh, int $nr, array &$hinweise): ?array
    {
        if (! is_array($roh)) {
            $hinweise[] = 'Standort '.($nr + 1).' übersprungen: kein gültiger Eintrag.';

            return null;
        }

        $pruefung = Validator::make($roh, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        if ($pruefung->fails()) {
            $hinweise[] = 'Standort "'.$this->kurz($roh['name'] ?? ('Nummer '.($nr + 1))).'" übersprungen: '
                .$pruefung->errors()->first();

            return null;
        }

        return $pruefung->validated();
    }

    private function rackPruefen(mixed $roh, int $nr, array &$hinweise): ?array
    {
        if (! is_array($roh)) {
            $hinweise[] = 'Rack '.($nr + 1).' übersprungen: kein gültiger Eintrag.';

            return null;
        }

        $pruefung = Validator::make($roh, [
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'height_units' => ['required', 'integer', 'min:1', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

        if ($pruefung->fails()) {
            $hinweise[] = 'Rack "'.$this->kurz($roh['name'] ?? ('Nummer '.($nr + 1))).'" übersprungen: '
                .$pruefung->errors()->first();

            return null;
        }

        return $pruefung->validated();
    }

    /**
     * Regeln wie im DeviceController - dort steht die Quelle fuer das,
     * was ein Geraet sein darf. Was nicht passt, wird uebersprungen und
     * nicht stillschweigend zurechtgebogen.
     */
    private function geraetPruefen(mixed $roh, ?array $rack, string $wo, int $nr, array &$hinweise): ?array
    {
        if (! is_array($roh)) {
            $hinweise[] = 'Gerät '.($nr + 1).' in "'.$wo.'" übersprungen: kein gültiger Eintrag.';

            return null;
        }

        // Am Standort gibt es keine Hoeheneinheiten - dort sind
        // Position und Hoehe ohne Bedeutung und duerfen fehlen.
        $position = $rack === null
            ? [
                'height_units' => ['nullable', 'integer', 'min:1', 'max:50'],
                'start_unit' => ['nullable', 'integer', 'min:1'],
            ]
            : [
                'height_units' => ['required', 'integer', 'min:1', 'max:50'],
                'start_unit' => ['required', 'integer', 'min:1'],
            ];

        $pruefung = Validator::make($roh, [
            'name' => ['required', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'device_type' => ['required', Rule::in([
                'server', 'switch', 'router', 'firewall', 'nas',
                'patchpanel', 'pdu', 'ups', 'ap', 'other',
            ])],
            'status' => ['nullable', Rule::in(['active', 'planned', 'maintenance', 'retired'])],
            ...$position,
            'mount_side' => ['nullable', Rule::in(['full', 'front', 'rear'])],
            'image_path' => ['nullable', 'string', 'max:2048'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:255'],
            'vlan' => ['nullable', 'string', 'max:255'],
            'switch_port' => ['nullable', 'string', 'max:255'],
            'uplink_port' => ['nullable', 'string', 'max:255'],
            'network_ports' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'poe_ports' => ['nullable', 'integer', 'min:0', 'lte:network_ports'],
            'poe_type' => ['nullable', Rule::in(['passive', 'poe', 'poe+', 'poe++'])],
            'outlet_count' => ['nullable', 'integer', 'min:0', 'max:'.PowerOutletSync::MAX_OUTLETS],
            // Aeltere Sicherungen kennen diese beiden Felder nicht -
            // dann bleiben sie leer, das Geraet kommt trotzdem an.
            'purchase_date' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($pruefung->fails()) {
            $hinweise[] = 'Gerät "'.$this->kurz($roh['name'] ?? ('Nummer '.($nr + 1))).'" übersprungen: '
                .$pruefung->errors()->first();

            return null;
        }

        $geraet = $pruefung->validated();

        if ($rack === null) {
            // Ohne Rack gibt es keine Position und keine Einbauseite
            $geraet['start_unit'] = null;
            $geraet['height_units'] = 1;
            $geraet['mount_side'] = 'full';
        } else {
            // Passt das Geraet ueberhaupt in das Rack?
            $ende = $geraet['start_unit'] + $geraet['height_units'] - 1;

            if ($ende > $rack['height_units']) {
                $hinweise[] = 'Gerät "'.$this->kurz($geraet['name']).'" übersprungen: '
                    ."liegt mit HE {$geraet['start_unit']}–{$ende} außerhalb des Racks "
                    .'"'.$rack['name'].'" ('.$rack['height_units'].' HE).';

                return null;
            }
        }

        // Bilder stecken nicht in der Sicherung. Der Pfad bleibt nur
        // stehen, wenn die Datei hier tatsaechlich liegt - sonst zeigte
        // das Geraet auf ein Bild, das es nicht gibt.
        if (! empty($geraet['image_path']) && ! Storage::disk('public')->exists($geraet['image_path'])) {
            $geraet['image_path'] = null;
        }

        return $geraet;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function portsPruefen(array $rohGeraet, string $geraet, array &$hinweise): array
    {
        $ports = [];
        $namen = [];

        foreach ($rohGeraet['ports'] ?? [] as $nr => $roh) {
            if (count($ports) >= self::MAX_PORTS) {
                $hinweise[] = 'Gerät "'.$this->kurz($geraet).'": mehr als '.self::MAX_PORTS.' Ports, der Rest wurde übergangen.';
                break;
            }

            if (! is_array($roh)) {
                continue;
            }

            $pruefung = Validator::make($roh, [
                'name' => ['required', 'string', 'max:100'],
                'port_type' => ['required', Rule::in([
                    'ethernet', 'sfp', 'sfp+', 'sfp28', 'qsfp',
                    'fiber', 'power', 'console', 'usb', 'other',
                ])],
                'speed' => ['nullable', 'string', 'max:20'],
                'poe' => ['nullable', Rule::in(['passive', 'poe', 'poe+', 'poe++'])],
                'status' => ['required', 'string', 'max:20'],
                'vlan' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string'],
            ]);

            if ($pruefung->fails()) {
                $hinweise[] = 'Port "'.$this->kurz($roh['name'] ?? ('Nummer '.($nr + 1)))
                    .'" an "'.$this->kurz($geraet).'" übersprungen: '.$pruefung->errors()->first();

                continue;
            }

            $port = $pruefung->validated();

            // Ein Geraet darf keine zwei Ports gleichen Namens haben.
            $schluessel = mb_strtolower($port['name']);

            if (isset($namen[$schluessel])) {
                $hinweise[] = 'Port "'.$this->kurz($port['name']).'" an "'.$this->kurz($geraet).'" kommt doppelt vor und wurde einmal übersprungen.';

                continue;
            }

            $namen[$schluessel] = true;

            // Nur fuer die Zuordnung der Verbindungen, wird nicht gespeichert
            $port['__id'] = $roh['id'] ?? null;

            $ports[] = $port;
        }

        return $ports;
    }

    /**
     * Steckplaetze einer Leiste. Das angeschlossene Geraet steht als
     * alte ID drin und wird erst verknuepft, wenn alle Geraete der
     * Datei bekannt sind - es kann in einem spaeteren Rack stehen.
     *
     * @return list<array<string, mixed>>
     */
    private function steckplaetzePruefen(array $rohGeraet, string $geraet, array &$hinweise): array
    {
        $plaetze = [];
        $belegt = [];

        foreach ($rohGeraet['outlets'] ?? [] as $nr => $roh) {
            if (count($plaetze) >= PowerOutletSync::MAX_OUTLETS) {
                $hinweise[] = 'Gerät "'.$this->kurz($geraet).'": mehr als '.PowerOutletSync::MAX_OUTLETS
                    .' Steckplätze, der Rest wurde übergangen.';
                break;
            }

            if (! is_array($roh)) {
                continue;
            }

            $pruefung = Validator::make($roh, [
                'position' => ['required', 'integer', 'min:1', 'max:'.PowerOutletSync::MAX_OUTLETS],
                'label' => ['nullable', 'string', 'max:100'],
                'external_label' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
            ]);

            if ($pruefung->fails()) {
                $hinweise[] = 'Steckplatz '.($nr + 1).' an "'.$this->kurz($geraet).'" übersprungen: '
                    .$pruefung->errors()->first();

                continue;
            }

            $platz = $pruefung->validated();

            if (isset($belegt[$platz['position']])) {
                $hinweise[] = 'Steckplatz '.$platz['position'].' an "'.$this->kurz($geraet)
                    .'" kommt doppelt vor und wurde einmal übersprungen.';

                continue;
            }

            $belegt[$platz['position']] = true;

            // Wird in steckplaetzeVerknuepfen() aufgeloest
            $platz['__ziel'] = $roh['connected_device_id'] ?? null;

            $plaetze[] = $platz;
        }

        return $plaetze;
    }

    /**
     * Die alten Geraete-IDs der Steckplaetze auf Stellen im Plan
     * umbiegen. Ein Geraet haengt an genau einem Platz - taucht es
     * mehrfach auf, bleibt der zweite Platz frei.
     */
    private function steckplaetzeVerknuepfen(array &$racks, array &$orte, array $geraetStelle, array &$hinweise): void
    {
        // Ein Geraet steckt in genau einer Dose - auch ueber Racks und
        // Standorte hinweg. Deshalb zaehlt dieselbe Merkliste fuer beide.
        $schonBelegt = [];

        $this->plaetzeVerknuepfen($racks, $geraetStelle, $schonBelegt, $hinweise);
        $this->plaetzeVerknuepfen($orte, $geraetStelle, $schonBelegt, $hinweise);
    }

    private function plaetzeVerknuepfen(array &$liste, array $geraetStelle, array &$schonBelegt, array &$hinweise): void
    {
        foreach ($liste as $behaelterNr => $behaelter) {
            foreach ($behaelter['devices'] as $geraeteNr => $geraet) {
                foreach ($geraet['outlets'] as $platzNr => $platz) {
                    $alteId = $platz['__ziel'] ?? null;
                    unset($platz['__ziel']);

                    $platz['ziel'] = null;

                    if ($alteId) {
                        $stelle = $geraetStelle[(string) $alteId] ?? null;
                        $schluessel = $stelle === null ? null : implode('-', $stelle);

                        if ($stelle === null) {
                            $hinweise[] = sprintf(
                                'Steckplatz %d an "%s" bleibt frei: das angeschlossene Gerät fehlt in der Datei.',
                                $platz['position'],
                                $this->kurz($geraet['name'])
                            );
                        } elseif (isset($schonBelegt[$schluessel])) {
                            $hinweise[] = sprintf(
                                'Steckplatz %d an "%s" bleibt frei: das Gerät steckt in der Datei schon an anderer Stelle.',
                                $platz['position'],
                                $this->kurz($geraet['name'])
                            );
                        } else {
                            $schonBelegt[$schluessel] = true;
                            $platz['ziel'] = $stelle;
                        }
                    }

                    $liste[$behaelterNr]['devices'][$geraeteNr]['outlets'][$platzNr] = $platz;
                }
            }
        }
    }

    /**
     * Verbindungen zeigen in der Datei auf die alten IDs. Hier werden
     * sie auf die Stellen im Plan umgebogen; was ins Leere zeigt, faellt
     * heraus (etwa wenn das zugehoerige Geraet uebersprungen wurde).
     */
    private function verbindungenPruefen(array $roh, array $geraetStelle, array $portStelle, array &$hinweise): array
    {
        $verbindungen = [];
        $gesehen = [];

        foreach ($roh as $nr => $eintrag) {
            if (! is_array($eintrag)) {
                continue;
            }

            $quellPort = $portStelle[(string) ($eintrag['source_port_id'] ?? '')] ?? null;
            $zielPort = $portStelle[(string) ($eintrag['target_port_id'] ?? '')] ?? null;

            if ($quellPort === null || $zielPort === null) {
                $hinweise[] = 'Verbindung '.($nr + 1).' übersprungen: einer der beiden Ports fehlt in der Datei.';

                continue;
            }

            // Ein Port kann nur einmal verbunden sein.
            $a = implode('-', $quellPort);
            $b = implode('-', $zielPort);

            if ($a === $b) {
                $hinweise[] = 'Verbindung '.($nr + 1).' übersprungen: Quelle und Ziel sind derselbe Port.';

                continue;
            }

            if (isset($gesehen[$a]) || isset($gesehen[$b])) {
                $hinweise[] = 'Verbindung '.($nr + 1).' übersprungen: einer der Ports ist bereits verbunden.';

                continue;
            }

            $gesehen[$a] = true;
            $gesehen[$b] = true;

            $verbindungen[] = [
                'quelle' => $quellPort,
                'ziel' => $zielPort,
                'connection_type' => $this->text($eintrag['connection_type'] ?? null, 50) ?? 'direct',
                'cable_type' => $this->text($eintrag['cable_type'] ?? null, 100),
                'cable_length' => $this->text($eintrag['cable_length'] ?? null, 50),
                'status' => $this->text($eintrag['status'] ?? null, 50) ?? 'active',
                'notes' => is_string($eintrag['notes'] ?? null) ? $eintrag['notes'] : null,
            ];
        }

        return $verbindungen;
    }

    // -------------------------------------------------------------
    // Schreiben
    // -------------------------------------------------------------

    /**
     * Leert den Bereich des Kontos. Geraete einzeln ueber das Modell,
     * damit die hochgeladenen Bilder mit weggeraeumt werden - ein
     * Loeschen der Racks allein raeumt nur die Datenbank auf.
     */
    private function bereichLeeren(): void
    {
        $racks = Rack::query()->ownedBy(Auth::id())->with('devices')->get();

        foreach ($racks as $rack) {
            foreach ($rack->devices as $device) {
                $device->delete();
            }

            $rack->delete();
        }

        // Dasselbe fuer die Standorte - sonst bliebe alles stehen, was
        // ausserhalb der Racks haengt.
        $orte = Location::query()->ownedBy(Auth::id())->with('devices')->get();

        foreach ($orte as $ort) {
            foreach ($ort->devices as $device) {
                $device->delete();
            }

            $ort->delete();
        }
    }

    /**
     * @return array<string, int>
     */
    private function schreiben(array $plan): array
    {
        $gezaehlt = ['racks' => 0, 'standorte' => 0, 'geraete' => 0, 'ports' => 0, 'steckplaetze' => 0, 'verbindungen' => 0];

        // Stelle im Plan -> neue ID in der Datenbank
        $geraetIds = [];
        $portIds = [];

        // Die angelegten Leisten, um ihre Steckplaetze spaeter zu fuellen
        $leisten = [];

        foreach ($plan['racks'] as $rackNr => $rack) {
            $neuesRack = new Rack(collect($rack)->except('devices')->all());
            $neuesRack->user_id = Auth::id();
            $neuesRack->save();
            $gezaehlt['racks']++;

            $this->geraeteSchreiben($neuesRack, $rack['devices'], 'r'.$rackNr, $gezaehlt, $geraetIds, $portIds, $leisten);
        }

        foreach ($plan['standorte'] ?? [] as $ortNr => $ort) {
            $neuerOrt = new Location(collect($ort)->except('devices')->all());
            $neuerOrt->user_id = Auth::id();
            $neuerOrt->save();
            $gezaehlt['standorte']++;

            $this->geraeteSchreiben($neuerOrt, $ort['devices'], 'o'.$ortNr, $gezaehlt, $geraetIds, $portIds, $leisten);
        }

        // Erst wenn alle Geraete stehen: ein Steckplatz kann auf ein
        // Geraet aus einem anderen Rack der Datei zeigen.
        foreach ($leisten as [$leiste, $steckplaetze]) {
            foreach ($steckplaetze as $platz) {
                $zielId = $platz['ziel'] ? ($geraetIds[implode('-', $platz['ziel'])] ?? null) : null;

                $leiste->outlets()->create([
                    'position' => $platz['position'],
                    'label' => $platz['label'] ?? null,
                    'notes' => $platz['notes'] ?? null,
                    'connected_device_id' => $zielId,
                    'external_label' => $zielId ? null : ($platz['external_label'] ?? null),
                ]);

                $gezaehlt['steckplaetze']++;
            }
        }

        foreach ($plan['verbindungen'] as $verbindung) {
            $quelle = implode('-', $verbindung['quelle']);
            $ziel = implode('-', $verbindung['ziel']);

            $quellGeraet = implode('-', array_slice($verbindung['quelle'], 0, 2));
            $zielGeraet = implode('-', array_slice($verbindung['ziel'], 0, 2));

            PortConnection::create([
                'source_device_id' => $geraetIds[$quellGeraet],
                'source_port_id' => $portIds[$quelle],
                'target_device_id' => $geraetIds[$zielGeraet],
                'target_port_id' => $portIds[$ziel],
                'connection_type' => $verbindung['connection_type'],
                'cable_type' => $verbindung['cable_type'],
                'cable_length' => $verbindung['cable_length'],
                'status' => $verbindung['status'],
                'notes' => $verbindung['notes'],
            ]);

            $gezaehlt['verbindungen']++;
        }

        return $gezaehlt;
    }

    /**
     * Die Geraete eines Behaelters schreiben - Rack wie Standort.
     * $stelle ist seine Kennung im Plan ("r0", "o2").
     */
    private function geraeteSchreiben(
        Rack|Location $behaelter,
        array $geraete,
        string $stelle,
        array &$gezaehlt,
        array &$geraetIds,
        array &$portIds,
        array &$leisten
    ): void {
        foreach ($geraete as $geraeteNr => $geraet) {
            $ports = $geraet['ports'];
            $steckplaetze = $geraet['outlets'];
            unset($geraet['ports'], $geraet['outlets']);

            $neuesGeraet = $behaelter->devices()->create($geraet);
            $geraetIds["{$stelle}-{$geraeteNr}"] = $neuesGeraet->id;
            $gezaehlt['geraete']++;

            if ($steckplaetze !== []) {
                $leisten["{$stelle}-{$geraeteNr}"] = [$neuesGeraet, $steckplaetze];
            }

            foreach ($ports as $portNr => $port) {
                unset($port['__id']);

                $neuerPort = $neuesGeraet->ports()->create($port);
                $portIds["{$stelle}-{$geraeteNr}-{$portNr}"] = $neuerPort->id;
                $gezaehlt['ports']++;
            }

            // Nur wenn die Datei gar keine Ports mitbringt, werden sie
            // aus der Portanzahl erzeugt. Bringt sie welche mit, ist
            // sie massgeblich - sonst kaemen "Port 1..n" hinzu, die es
            // im gesicherten Stand nie gab.
            if ($ports === [] && (int) $neuesGeraet->network_ports > 0) {
                DevicePortSync::sync($neuesGeraet, applyPoe: true);
                $gezaehlt['ports'] += $neuesGeraet->ports()->count();
            }

            // Dasselbe fuer Steckplaetze: bringt die Datei welche mit,
            // gelten sie; sonst werden sie aus der Anzahl erzeugt.
            if ($steckplaetze === [] && (int) $neuesGeraet->outlet_count > 0) {
                PowerOutletSync::sync($neuesGeraet);
                $gezaehlt['steckplaetze'] += $neuesGeraet->outlets()->count();
            }
        }
    }

    // -------------------------------------------------------------
    // Vorschau
    // -------------------------------------------------------------

    private function zusammenfassung(array $plan): array
    {
        $geraete = 0;
        $ports = 0;
        $steckplaetze = 0;
        $racks = [];
        $orte = [];

        foreach ($plan['racks'] as $rack) {
            $zahlen = $this->behaelterZahlen($rack['devices']);

            $geraete += $zahlen['geraete'];
            $ports += $zahlen['ports'];
            $steckplaetze += $zahlen['steckplaetze'];

            $racks[] = [
                'name' => $rack['name'],
                'location' => $rack['location'] ?? null,
                'height_units' => $rack['height_units'],
                ...$zahlen,
            ];
        }

        foreach ($plan['standorte'] ?? [] as $ort) {
            $zahlen = $this->behaelterZahlen($ort['devices']);

            $geraete += $zahlen['geraete'];
            $ports += $zahlen['ports'];
            $steckplaetze += $zahlen['steckplaetze'];

            $orte[] = [
                'name' => $ort['name'],
                'description' => $ort['description'] ?? null,
                ...$zahlen,
            ];
        }

        return [
            'modus' => $plan['modus'],
            'neu' => [
                'racks' => count($plan['racks']),
                'standorte' => count($plan['standorte'] ?? []),
                'geraete' => $geraete,
                'ports' => $ports,
                'steckplaetze' => $steckplaetze,
                'verbindungen' => count($plan['verbindungen']),
            ],
            // Beim Ersetzen: was vorher verschwindet
            'entfaellt' => $plan['modus'] === 'ersetzen' ? $this->bestand() : null,
            'racks' => $racks,
            'standorte' => $orte,
            'hinweise' => $plan['hinweise'],
        ];
    }

    /**
     * Was ein Behaelter nach dem Schreiben enthalten wird.
     *
     * Bringt ein Geraet keine Ports mit, werden sie aus der Portanzahl
     * erzeugt. Die Vorschau muss dieselbe Regel anwenden, sonst
     * kuendigt sie weniger an, als hinterher da ist.
     *
     * @return array{geraete: int, ports: int, steckplaetze: int}
     */
    private function behaelterZahlen(array $geraete): array
    {
        return [
            'geraete' => count($geraete),
            'ports' => array_sum(array_map(
                fn ($geraet) => count($geraet['ports'])
                    ?: min((int) ($geraet['network_ports'] ?? 0), self::MAX_PORTS),
                $geraete
            )),
            'steckplaetze' => array_sum(array_map(
                fn ($geraet) => count($geraet['outlets'])
                    ?: min((int) ($geraet['outlet_count'] ?? 0), PowerOutletSync::MAX_OUTLETS),
                $geraete
            )),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function bestand(): array
    {
        return [
            'racks' => Rack::query()->ownedBy(Auth::id())->count(),
            'standorte' => Location::query()->ownedBy(Auth::id())->count(),
            // Geraete im Rack und am Standort zusammen
            'geraete' => Device::query()->ownedBy(Auth::id())->count(),
            'verbindungen' => PortConnection::query()->ownedBy(Auth::id())->count(),
        ];
    }

    // -------------------------------------------------------------
    // Kleinigkeiten
    // -------------------------------------------------------------

    /**
     * "Keller" -> "Keller (2)", wenn der Name schon vergeben ist.
     */
    private function freierName(string $name, array $belegt, string $wort, array &$hinweise): string
    {
        if (! isset($belegt[mb_strtolower($name)])) {
            return $name;
        }

        for ($zahl = 2; $zahl < 1000; $zahl++) {
            $versuch = "{$name} ({$zahl})";

            if (! isset($belegt[mb_strtolower($versuch)])) {
                $hinweise[] = 'Der Name "'.$this->kurz($name).'" ist schon vergeben, '
                    .($wort === 'Rack' ? 'das Rack' : 'der Standort').' heißt jetzt "'.$versuch.'".';

                return $versuch;
            }
        }

        return $name.' ('.uniqid().')';
    }

    private function text(mixed $wert, int $laenge): ?string
    {
        if (! is_string($wert) || $wert === '') {
            return null;
        }

        return mb_substr($wert, 0, $laenge);
    }

    /** Lange Namen in Hinweisen kuerzen, damit die Meldung lesbar bleibt. */
    private function kurz(mixed $wert): string
    {
        $text = is_scalar($wert) ? (string) $wert : 'ohne Namen';

        return mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text;
    }
}

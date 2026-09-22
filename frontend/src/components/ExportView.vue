<script setup>
import { computed, ref } from 'vue'
import { getDeviceTypeLabel, getMountSideLabel, getPoeLabel, getStatusLabel } from '../lib/deviceMeta.js'

const props = defineProps({
  devices: { type: Array, default: () => [] },
  racks: { type: Array, default: () => [] },
  // Standorte ausserhalb der Racks - nur fuer die Zahl auf der Karte
  locations: { type: Array, default: () => [] },
  connections: { type: Array, default: () => [] },
  api: { type: Function, required: true },
})

const emit = defineEmits(['print-rack', 'imported'])

const jsonLaedt = ref(false)
const jsonFehler = ref('')

// --- Hilfen ---------------------------------------------------

function datum() {
  return new Date().toISOString().slice(0, 10)
}

function rackVon(device) {
  return props.racks.find((rack) => Number(rack.id) === Number(device?.rack_id)) || null
}

function ortVon(device) {
  return props.locations.find((ort) => Number(ort.id) === Number(device?.location_id)) || null
}

/** Sortierschluessel: erst die Racks, dann die Standorte */
function wohin(device) {
  return device?.rack_id
    ? `1${rackVon(device)?.name || ''}`
    : `2${ortVon(device)?.name || ''}`
}

function geraetVon(id) {
  return props.devices.find((device) => Number(device.id) === Number(id)) || null
}

// Excel (deutsch) trennt mit Semikolon. Werte mit Trennzeichen,
// Anfuehrungszeichen oder Umbruch werden eingefasst.
function csvZelle(wert) {
  let text = wert === null || wert === undefined ? '' : String(wert)

  // Formel-Schutz: Excel wuerde "=...", "+...", "-...", "@..." ausfuehren
  if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`

  return /[";\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

// ﻿ (BOM): ohne diese Markierung liest Excel die Datei nicht als
// UTF-8 und macht aus "Gerät" ein "GerÃ¤t".
function csvDatei(kopf, zeilen) {
  return '﻿' + [kopf, ...zeilen].map((zeile) => zeile.map(csvZelle).join(';')).join('\r\n')
}

function herunterladen(inhalt, dateiname, typ) {
  const url = URL.createObjectURL(new Blob([inhalt], { type: typ }))
  const link = document.createElement('a')

  link.href = url
  link.download = dateiname
  document.body.appendChild(link)
  link.click()
  link.remove()

  // Erst freigeben, wenn der Browser den Download uebernommen hat
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

// --- Geraete --------------------------------------------------

function geraeteCsv() {
  const kopf = [
    'Rack', 'Standort', 'HE von', 'HE bis', 'Höhe (HE)', 'Einbauseite', 'Name', 'Typ', 'Status',
    'Hersteller', 'Modell', 'Seriennummer', 'IP-Adresse', 'MAC-Adresse', 'VLAN',
    'Switch-Port', 'Uplink-Port', 'Ports', 'PoE-Ports', 'PoE-Standard', 'Beschreibung', 'Notizen',
  ]

  const zeilen = [...props.devices]
    .sort((a, b) =>
      wohin(a).localeCompare(wohin(b), 'de') ||
      Number(b.start_unit) - Number(a.start_unit) ||
      String(a.name || '').localeCompare(String(b.name || ''), 'de')
    )
    .map((device) => {
      const rack = rackVon(device)
      const start = Number(device.start_unit) || ''
      const hoehe = Number(device.height_units) || 1

      // Ohne Rack bleibt die Rackspalte leer; der Standort steht in
      // der Spalte daneben, wo sonst der Standort des Racks steht.
      return [
        rack?.name, rack ? rack.location : ortVon(device)?.name, start, start ? start + hoehe - 1 : '', hoehe,
        getMountSideLabel(device), device.name, getDeviceTypeLabel(device.device_type), getStatusLabel(device.status),
        device.manufacturer, device.model, device.serial_number, device.ip_address,
        device.mac_address, device.vlan, device.switch_port, device.uplink_port,
        device.network_ports, device.poe_ports, device.poe_type ? getPoeLabel(device.poe_type) : '',
        device.description, device.notes,
      ]
    })

  herunterladen(csvDatei(kopf, zeilen), `rackview-geraete-${datum()}.csv`, 'text/csv;charset=utf-8')
}

// --- Verbindungen ---------------------------------------------

const STATUS = { active: 'Aktiv', planned: 'Geplant', faulty: 'Defekt', disconnected: 'Getrennt' }

function verbindungenCsv() {
  const kopf = ['Quellgerät', 'Quellport', 'Zielgerät', 'Zielport', 'Verbindungstyp', 'Status', 'Notizen']

  const zeilen = props.connections.map((verbindung) => {
    const quelle = geraetVon(verbindung.source_device_id) || verbindung.source_device
    const ziel = geraetVon(verbindung.target_device_id) || verbindung.target_device

    return [
      quelle?.name, verbindung.source_port?.name,
      ziel?.name, verbindung.target_port?.name,
      !verbindung.connection_type || verbindung.connection_type === 'direct' ? 'Direkt' : verbindung.connection_type,
      STATUS[verbindung.status || 'active'] || verbindung.status, verbindung.notes,
    ]
  })

  herunterladen(csvDatei(kopf, zeilen), `rackview-verbindungen-${datum()}.csv`, 'text/csv;charset=utf-8')
}

// --- Vollstaendige Sicherung ------------------------------------

async function jsonSicherung() {
  jsonLaedt.value = true
  jsonFehler.value = ''

  try {
    const antwort = await fetch('/api/export', { headers: { Accept: 'application/json' } })

    if (!antwort.ok) throw new Error(`HTTP-Fehler ${antwort.status}`)

    const daten = await antwort.json()

    herunterladen(JSON.stringify(daten, null, 2), `rackview-sicherung-${datum()}.json`, 'application/json')
  } catch (error) {
    jsonFehler.value = `Die Sicherung konnte nicht erstellt werden: ${error.message}`
  } finally {
    jsonLaedt.value = false
  }
}

const anzahlPorts = computed(() => props.devices.reduce((summe, device) => summe + (Number(device.network_ports) || 0), 0))

// --- Sicherung einspielen ---------------------------------------
//
// Zwei Schritte: Die Datei geht zuerst an die Vorschau, die nichts
// schreibt und nur zurueckmeldet, was passieren wuerde. Erst der
// zweite Aufruf fuehrt den Import aus.

const MODI = [
  { wert: 'ergaenzen', label: 'Ergänzen', hinweis: 'Die Racks der Datei kommen zusätzlich hinein.' },
  { wert: 'ersetzen', label: 'Ersetzen', hinweis: 'Der jetzige Inhalt wird vorher gelöscht.' },
]

const dateiFeld = ref(null)
const dateiName = ref('')
const dokument = ref(null)
const modus = ref('ergaenzen')
const vorschau = ref(null)
const importLaeuft = ref(false)
const importFehler = ref('')
const verstanden = ref(false)

const bereit = computed(() =>
  Boolean(vorschau.value) &&
  !importLaeuft.value &&
  (modus.value !== 'ersetzen' || verstanden.value)
)

const hatInhalt = computed(() =>
  Boolean(vorschau.value) &&
  (vorschau.value.neu.racks > 0 || (vorschau.value.neu.standorte || 0) > 0)
)

async function dateiGewaehlt(ereignis) {
  const datei = ereignis.target.files?.[0]

  zuruecksetzen({ feldLeeren: false })

  if (!datei) return

  dateiName.value = datei.name

  try {
    const daten = JSON.parse(await datei.text())

    // Frueh und deutlich: sonst kaeme die Meldung erst vom Server
    if (daten?.format !== 'rackview-export') {
      throw new Error('Das ist keine RackView-Sicherung.')
    }

    dokument.value = daten
    await vorschauHolen()
  } catch (fehler) {
    importFehler.value = `Die Datei konnte nicht gelesen werden: ${fehler.message}`
  }
}

async function vorschauHolen() {
  if (!dokument.value) return

  importLaeuft.value = true
  importFehler.value = ''

  try {
    vorschau.value = await props.api('/import/preview', {
      method: 'POST',
      body: JSON.stringify({ modus: modus.value, daten: dokument.value }),
    })
  } catch (fehler) {
    vorschau.value = null
    importFehler.value = fehler.message
  } finally {
    importLaeuft.value = false
  }
}

async function modusWechseln(wert) {
  if (modus.value === wert) return

  modus.value = wert
  verstanden.value = false

  await vorschauHolen()
}

async function importieren() {
  if (!bereit.value) return

  importLaeuft.value = true
  importFehler.value = ''

  try {
    const antwort = await props.api('/import', {
      method: 'POST',
      body: JSON.stringify({ modus: modus.value, daten: dokument.value }),
    })

    zuruecksetzen()
    emit('imported', antwort.angelegt)
  } catch (fehler) {
    importFehler.value = fehler.message
  } finally {
    importLaeuft.value = false
  }
}

/** "1 Rack" statt "1 Racks" */
function wort(anzahl, einzahl, mehrzahl) {
  return Number(anzahl) === 1 ? einzahl : mehrzahl
}

function zuruecksetzen({ feldLeeren = true } = {}) {
  dokument.value = null
  vorschau.value = null
  dateiName.value = ''
  importFehler.value = ''
  verstanden.value = false

  // Ohne das Leeren meldet das Feld keine Aenderung, wenn dieselbe
  // Datei noch einmal gewaehlt wird.
  if (feldLeeren && dateiFeld.value) dateiFeld.value.value = ''
}
</script>

<template>
  <section class="panel view-panel">
    <div class="export-grid">
      <article class="export-card">
        <div class="export-icon">▤</div>
        <h3>Geräteliste</h3>
        <p>
          Alle Geräte aus allen Racks mit Position, Netzwerkdaten, Ports und
          Notizen. Öffnet sich direkt in Excel oder LibreOffice.
        </p>
        <span class="export-meta">{{ devices.length }} Geräte · CSV</span>
        <button type="button" class="export-button" :disabled="!devices.length" @click="geraeteCsv">
          Als CSV herunterladen
        </button>
      </article>

      <article class="export-card">
        <div class="export-icon">⇄</div>
        <h3>Verbindungen</h3>
        <p>
          Alle Port-Verbindungen mit Quelle, Ziel und Status – welcher Port
          an welchem Gerät womit verbunden ist.
        </p>
        <span class="export-meta">{{ connections.length }} Verbindungen · CSV</span>
        <button type="button" class="export-button" :disabled="!connections.length" @click="verbindungenCsv">
          Als CSV herunterladen
        </button>
      </article>

      <article class="export-card">
        <div class="export-icon">⎘</div>
        <h3>Vollständige Sicherung</h3>
        <p>
          Racks, Standorte, Geräte, alle einzelnen Ports und Verbindungen in
          einer Datei. Gerätebilder sind nur als Pfad enthalten.
        </p>
        <span class="export-meta">
          {{ racks.length }} Racks<template v-if="locations.length"> · {{ locations.length }} Standorte</template>
          · {{ devices.length }} Geräte · {{ anzahlPorts }} Ports · JSON
        </span>
        <button type="button" class="export-button" :disabled="jsonLaedt" @click="jsonSicherung">
          {{ jsonLaedt ? 'Wird erstellt …' : 'Als JSON herunterladen' }}
        </button>
        <p v-if="jsonFehler" class="export-error">{{ jsonFehler }}</p>
      </article>
    </div>

    <div class="export-import">
      <h3>Sicherung einspielen</h3>
      <p>
        Eine heruntergeladene JSON-Sicherung wieder einlesen – für den Umzug auf
        eine andere Installation oder um einen älteren Stand zurückzuholen.
        Gerätebilder stecken nicht in der Datei: Pfade ohne vorhandenes Bild
        werden dabei verworfen.
      </p>

      <div class="import-modi" role="group" aria-label="Art des Imports">
        <button
          v-for="art in MODI"
          :key="art.wert"
          type="button"
          :class="{ aktiv: modus === art.wert }"
          :aria-pressed="modus === art.wert"
          @click="modusWechseln(art.wert)"
        >
          <strong>{{ art.label }}</strong>
          <small>{{ art.hinweis }}</small>
        </button>
      </div>

      <div class="import-datei">
        <button type="button" class="export-button secondary" @click="dateiFeld?.click()">
          JSON-Datei wählen …
        </button>
        <input
          ref="dateiFeld"
          type="file"
          accept="application/json,.json"
          class="import-feld"
          @change="dateiGewaehlt"
        />
        <span v-if="dateiName">{{ dateiName }}</span>
      </div>

      <div v-if="importFehler" class="export-error">{{ importFehler }}</div>
      <p v-else-if="importLaeuft && !vorschau" class="export-empty">Die Datei wird geprüft …</p>

      <div v-if="vorschau" class="import-vorschau">
        <p class="import-zahlen">
          <span><strong>{{ vorschau.neu.racks }}</strong> {{ wort(vorschau.neu.racks, 'Rack', 'Racks') }}</span>
          <span v-if="vorschau.neu.standorte">
            <strong>{{ vorschau.neu.standorte }}</strong>
            {{ wort(vorschau.neu.standorte, 'Standort', 'Standorte') }}
          </span>
          <span><strong>{{ vorschau.neu.geraete }}</strong> {{ wort(vorschau.neu.geraete, 'Gerät', 'Geräte') }}</span>
          <span><strong>{{ vorschau.neu.ports }}</strong> {{ wort(vorschau.neu.ports, 'Port', 'Ports') }}</span>
          <span v-if="vorschau.neu.steckplaetze">
            <strong>{{ vorschau.neu.steckplaetze }}</strong>
            {{ wort(vorschau.neu.steckplaetze, 'Steckplatz', 'Steckplätze') }}
          </span>
          <span><strong>{{ vorschau.neu.verbindungen }}</strong> {{ wort(vorschau.neu.verbindungen, 'Verbindung', 'Verbindungen') }}</span>
        </p>

        <ul v-if="vorschau.racks.length" class="import-racks">
          <li v-for="(rack, nr) in vorschau.racks" :key="nr">
            <span>
              <strong>{{ rack.name }}</strong>
              <small>{{ rack.location || 'Ohne Standort' }} · {{ rack.height_units }} HE</small>
            </span>
            <small>
              {{ rack.geraete }} {{ wort(rack.geraete, 'Gerät', 'Geräte') }} ·
              {{ rack.ports }} {{ wort(rack.ports, 'Port', 'Ports') }}
              <template v-if="rack.steckplaetze">
                · {{ rack.steckplaetze }} {{ wort(rack.steckplaetze, 'Steckplatz', 'Steckplätze') }}
              </template>
            </small>
          </li>
        </ul>

        <!-- Standorte stehen unter den Racks, gleiche Darstellung ohne HE -->
        <ul v-if="vorschau.standorte && vorschau.standorte.length" class="import-racks">
          <li v-for="(ort, nr) in vorschau.standorte" :key="`o${nr}`">
            <span>
              <strong>{{ ort.name }}</strong>
              <small>{{ ort.description || 'Standort' }}</small>
            </span>
            <small>
              {{ ort.geraete }} {{ wort(ort.geraete, 'Gerät', 'Geräte') }} ·
              {{ ort.ports }} {{ wort(ort.ports, 'Port', 'Ports') }}
              <template v-if="ort.steckplaetze">
                · {{ ort.steckplaetze }} {{ wort(ort.steckplaetze, 'Steckplatz', 'Steckplätze') }}
              </template>
            </small>
          </li>
        </ul>

        <p v-if="!hatInhalt" class="export-empty">
          Die Datei enthält keine übernehmbaren Racks oder Standorte.
        </p>

        <div v-if="vorschau.entfaellt" class="import-warnung">
          <strong>Ersetzen löscht zuerst den jetzigen Inhalt.</strong>
          <span>
            Damit verschwinden {{ vorschau.entfaellt.racks }} {{ wort(vorschau.entfaellt.racks, 'Rack', 'Racks') }},
            <template v-if="vorschau.entfaellt.standorte">
              {{ vorschau.entfaellt.standorte }} {{ wort(vorschau.entfaellt.standorte, 'Standort', 'Standorte') }},
            </template>
            {{ vorschau.entfaellt.geraete }} {{ wort(vorschau.entfaellt.geraete, 'Gerät', 'Geräte') }} und
            {{ vorschau.entfaellt.verbindungen }} {{ wort(vorschau.entfaellt.verbindungen, 'Verbindung', 'Verbindungen') }}
            – samt der hochgeladenen Gerätebilder.
          </span>
          <label>
            <input v-model="verstanden" type="checkbox" />
            Ja, den bisherigen Inhalt löschen
          </label>
        </div>

        <details v-if="vorschau.hinweise.length" class="import-hinweise">
          <summary>
            {{ vorschau.hinweise.length }} {{ wort(vorschau.hinweise.length, 'Hinweis', 'Hinweise') }} zur Datei
          </summary>
          <ul>
            <li v-for="(hinweis, nr) in vorschau.hinweise" :key="nr">{{ hinweis }}</li>
          </ul>
        </details>

        <div class="import-aktionen">
          <button
            type="button"
            class="export-button"
            :disabled="!bereit || !hatInhalt"
            @click="importieren"
          >
            {{ importLaeuft ? 'Wird eingelesen …' : (modus === 'ersetzen' ? 'Ersetzen und einlesen' : 'Einlesen') }}
          </button>
          <button
            type="button"
            class="export-button secondary"
            :disabled="importLaeuft"
            @click="zuruecksetzen()"
          >
            Abbrechen
          </button>
        </div>
      </div>
    </div>

    <div class="export-racks">
      <h3>Rack als PDF</h3>
      <p>
        Öffnet die Racks-Seite mit dem gewählten Rack und den Druckdialog.
        Dort „Als PDF sichern" wählen.
      </p>

      <ul v-if="racks.length">
        <li v-for="rack in racks" :key="rack.id">
          <span>
            <strong>{{ rack.name }}</strong>
            <small>{{ rack.location || 'Ohne Standort' }} · {{ (rack.devices || []).length }} Geräte</small>
          </span>
          <button type="button" class="export-button secondary" @click="emit('print-rack', rack)">
            ⎙ Drucken
          </button>
        </li>
      </ul>

      <p v-else class="export-empty">Noch keine Racks angelegt.</p>
    </div>

    <p class="export-note">
      Unabhängig davon sichert der Backup-Container jede Nacht die Datenbank
      und die Gerätebilder.
    </p>
  </section>
</template>

<style scoped>
.view-panel {
  display: flex;
  flex-direction: column;
  gap: 22px;
  padding: 22px 24px 26px;
}

.export-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 14px;
}

.export-card {
  display: flex;
  flex-direction: column;
  gap: 8px;

  padding: 18px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 14px;
  background: var(--f-grau-100);
}

.export-icon {
  display: grid;
  place-items: center;

  width: 38px;
  height: 38px;

  border-radius: 10px;
  background: var(--f-ton-96);

  color: var(--t-blau-53);
  font-size: 17px;
}

.export-card h3,
.export-racks h3 {
  margin: 4px 0 0;

  color: var(--t-ton-11);
  font-size: 15px;
  font-weight: 800;
}

.export-card p,
.export-racks > p {
  flex: 1;
  margin: 0;

  color: var(--t-ton-47-3);
  font-size: 12px;
  line-height: 1.55;
}

.export-meta {
  color: var(--t-ton-61-2);
  font-size: 11px;
  font-weight: 600;
}

.export-button {
  align-self: flex-start;
  margin-top: 4px;
  padding: 10px 14px;

  border: 0;
  border-radius: 10px;

  color: var(--t-grau-100);
  background: linear-gradient(135deg, var(--f-blau-61), var(--f-blau-53));

  font: inherit;
  font-size: 12px;
  font-weight: 750;

  cursor: pointer;
}

.export-button:disabled {
  opacity: 0.5;
  cursor: default;
}

.export-button.secondary {
  margin-top: 0;

  border: 1px solid var(--f-ton-91-2);

  color: var(--t-ton-40);
  background: var(--f-grau-100);
}

.export-button.secondary:hover {
  border-color: var(--f-blau-86-2);
  color: var(--t-blau-53);
}

.export-error {
  color: var(--t-rot-42) !important;
}

.export-racks {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.export-racks ul {
  display: flex;
  flex-direction: column;
  gap: 6px;

  margin: 6px 0 0;
  padding: 0;

  list-style: none;
}

.export-racks li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  padding: 10px 14px;

  border: 1px solid var(--f-ton-95);
  border-radius: 10px;
}

.export-racks li span {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.export-racks li strong {
  color: var(--t-ton-11);
  font-size: 13px;
}

.export-racks li small {
  color: var(--t-ton-61-2);
  font-size: 11px;
}

.export-empty,
.export-note {
  margin: 0;
  color: var(--t-ton-61-2);
  font-size: 12px;
}

/* --- Sicherung einspielen -------------------------------------- */

.export-import {
  display: flex;
  flex-direction: column;
  gap: 12px;

  padding: 18px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 14px;
  background: var(--f-grau-100);
}

.export-import > p {
  margin: 0;
  color: var(--t-ton-61-2);
  font-size: 12px;
}

.import-modi {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.import-modi button {
  display: flex;
  flex-direction: column;
  gap: 2px;

  min-width: 210px;
  padding: 10px 14px;

  border: 1px solid var(--f-ton-91-2);
  border-radius: 12px;
  background: var(--f-grau-100);

  text-align: left;
  cursor: pointer;
}

.import-modi button:hover {
  border-color: var(--f-blau-86-2);
}

.import-modi button.aktiv {
  border-color: var(--f-blau-53);
  background: var(--f-ton-96);
}

.import-modi strong {
  color: var(--t-ton-11);
  font-size: 13px !important;
  font-weight: 750;
}

.import-modi small {
  color: var(--t-ton-61-2);
  font-size: 11.5px !important;
}

.import-datei {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.import-datei .export-button {
  margin-top: 0;
}

.import-datei span {
  color: var(--t-ton-40);
  font-size: 12px;
}

/* Das native Feld bleibt verborgen - geklickt wird der Knopf daneben. */
.import-feld {
  display: none;
}

.import-vorschau {
  display: flex;
  flex-direction: column;
  gap: 12px;

  padding-top: 12px;
  border-top: 1px solid var(--f-ton-95);
}

.import-zahlen {
  display: flex;
  flex-wrap: wrap;
  gap: 18px;

  margin: 0;
}

.import-zahlen span {
  color: var(--t-ton-61-2);
  font-size: 12px;
}

.import-zahlen strong {
  margin-right: 4px;

  color: var(--t-ton-11);
  font-size: 15px;
  font-weight: 800;
}

.import-racks {
  display: flex;
  flex-direction: column;
  gap: 6px;

  margin: 0;
  padding: 0;

  list-style: none;
}

.import-racks li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  padding: 8px 12px;

  border: 1px solid var(--f-ton-95);
  border-radius: 10px;
}

.import-racks li span {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.import-racks li strong {
  color: var(--t-ton-11);
  font-size: 13px;
}

.import-racks li small {
  color: var(--t-ton-61-2);
  font-size: 11px;
}

.import-warnung {
  display: flex;
  flex-direction: column;
  gap: 8px;

  padding: 12px 14px;

  border: 1px solid var(--f-rot-89);
  border-radius: 12px;
  background: var(--f-rot-51-3);
}

.import-warnung strong {
  color: var(--t-rot-35);
  font-size: 13px;
  font-weight: 800;
}

.import-warnung span {
  color: var(--t-rot-40);
  font-size: 12px;
}

.import-warnung label {
  display: flex;
  align-items: center;
  gap: 8px;

  color: var(--t-rot-35);
  font-size: 12px;
  font-weight: 700;

  cursor: pointer;
}

.import-hinweise summary {
  color: var(--t-ton-40);
  font-size: 12px;
  font-weight: 700;

  cursor: pointer;
}

.import-hinweise ul {
  display: flex;
  flex-direction: column;
  gap: 4px;

  margin: 8px 0 0;
  padding-left: 18px;
}

.import-hinweise li {
  color: var(--t-ton-61-2);
  font-size: 12px;
}

.import-aktionen {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.import-aktionen .export-button {
  margin-top: 0;
}
</style>

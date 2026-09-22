<script setup>
import { computed, ref, watch } from 'vue'
import { getDeviceTypeLabel } from '../lib/deviceMeta.js'
import { qrSvg } from '../lib/qr.js'

const props = defineProps({
  devices: { type: Array, default: () => [] },
  racks: { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
  // Vorauswahl, z. B. aus den Gerätedetails heraus
  preselect: { type: Array, default: () => [] },
})

// Alle Masse in Millimetern - so stehen sie auch auf der Packung
const FORMATE = [
  {
    id: 'bogen',
    name: 'A4-Bogen',
    beschreibung: '3 × 8 Etiketten, 70 × 37 mm (z. B. Avery Zweckform 3474)',
    breite: 70,
    hoehe: 37,
    spalten: 3,
    zeilen: 8,
    seite: 'A4',
  },
  {
    id: 'rolle',
    name: 'Etikettendrucker',
    beschreibung: 'Ein Etikett je Seite, 62 × 29 mm (z. B. Brother QL)',
    breite: 62,
    hoehe: 29,
    spalten: 1,
    zeilen: 1,
    seite: '62mm 29mm',
  },
]

const formatId = ref('bogen')
const format = computed(() => FORMATE.find((eintrag) => eintrag.id === formatId.value) || FORMATE[0])
const proSeite = computed(() => format.value.spalten * format.value.zeilen)
const qrKante = computed(() => format.value.hoehe - 5)

const auswahl = ref(new Set(props.preselect.map(Number)))
const suche = ref('')
const rackFilter = ref('all')
const mitRack = ref(true)
const mitIp = ref(true)
const mitModell = ref(false)
const ueberspringen = ref(0)
const fehler = ref('')

// Die Adresse im QR-Code ist die, über die RackView gerade läuft
const basis = window.location.origin

watch(() => props.preselect, (neu) => {
  auswahl.value = new Set((neu || []).map(Number))
})

function rackVon(device) {
  return props.racks.find((rack) => Number(rack.id) === Number(device?.rack_id)) || null
}

function ortVon(device) {
  return props.locations.find((ort) => Number(ort.id) === Number(device?.location_id)) || null
}

function hePosition(device) {
  const start = Number(device.start_unit) || 0
  const hoehe = Number(device.height_units) || 1

  return hoehe > 1 ? `HE ${start}–${start + hoehe - 1}` : `HE ${start}`
}

// "Homelab · HE 7–8" im Rack, "Dachboden" am Standort
function woSteht(device) {
  const rack = rackVon(device)

  if (rack) return `${rack.name} · ${hePosition(device)}`

  return ortVon(device)?.name || ''
}

// Filterwerte "rack:3" oder "ort:2" - eine Zahl allein waere mehrdeutig
function passtZumFilter(device) {
  if (rackFilter.value === 'all') return true

  const [art, id] = rackFilter.value.split(':')

  return art === 'rack'
    ? Number(device.rack_id) === Number(id)
    : !device.rack_id && Number(device.location_id) === Number(id)
}

const gefiltert = computed(() => {
  const text = suche.value.trim().toLowerCase()

  return props.devices
    .filter(passtZumFilter)
    .filter((device) => {
      if (!text) return true

      return [device.name, device.ip_address, device.manufacturer, device.model, device.serial_number]
        .some((wert) => String(wert || '').toLowerCase().includes(text))
    })
    // Erst die Racks, darin von oben nach unten; danach die Standorte
    .sort((a, b) =>
      Number(!a.rack_id) - Number(!b.rack_id) ||
      String(rackVon(a)?.name || ortVon(a)?.name || '').localeCompare(String(rackVon(b)?.name || ortVon(b)?.name || ''), 'de') ||
      Number(b.start_unit) - Number(a.start_unit) ||
      String(a.name || '').localeCompare(String(b.name || ''), 'de')
    )
})

function umschalten(id) {
  const schluessel = Number(id)

  if (auswahl.value.has(schluessel)) auswahl.value.delete(schluessel)
  else auswahl.value.add(schluessel)
}

function alleGefiltertenSetzen(an) {
  for (const device of gefiltert.value) {
    if (an) auswahl.value.add(Number(device.id))
    else auswahl.value.delete(Number(device.id))
  }
}

const ausgewaehlt = computed(() =>
  gefiltert.value.filter((device) => auswahl.value.has(Number(device.id)))
)

const etiketten = computed(() =>
  ausgewaehlt.value.map((device) => {
    const wo = woSteht(device)

    return {
      id: device.id,
      name: device.name || 'Ohne Namen',
      zeilen: [
        mitRack.value && wo ? wo : '',
        mitIp.value && device.ip_address ? device.ip_address : '',
        mitModell.value ? [device.manufacturer, device.model].filter(Boolean).join(' ') : '',
      ].filter(Boolean),
      qr: qrSvg(`${basis}/#device/${device.id}`),
    }
  })
)

// Seiten fuellen; auf dem A4-Bogen koennen vorne Felder frei bleiben,
// wenn der Bogen schon angebrochen ist.
const seiten = computed(() => {
  const leer = formatId.value === 'bogen' ? Math.max(0, Math.min(Number(ueberspringen.value) || 0, proSeite.value - 1)) : 0
  const felder = [...Array(leer).fill(null), ...etiketten.value]
  const gruppen = []

  for (let i = 0; i < felder.length; i += proSeite.value) {
    const seite = felder.slice(i, i + proSeite.value)

    // Letzte Seite auffuellen, damit der Bogen vollstaendig zu sehen ist
    while (seite.length < proSeite.value) seite.push(null)

    gruppen.push(seite)
  }

  return gruppen
})

// --- Drucken ----------------------------------------------------
//
// Bewusst in einem eigenen Fenster: Dort gelten nur die Masse unten,
// nicht das Seitenlayout von RackView. Der Druckdialog oeffnet sich
// von selbst.

function escape(text) {
  return String(text ?? '').replace(/[&<>"]/g, (zeichen) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
  })[zeichen])
}

function etikettHtml(eintrag) {
  if (!eintrag) return '<div class="etikett leer"></div>'

  const zeilen = eintrag.zeilen.map((zeile) => `<span>${escape(zeile)}</span>`).join('')

  return `<div class="etikett"><div class="qr">${eintrag.qr}</div>` +
    `<div class="text"><strong>${escape(eintrag.name)}</strong>${zeilen}</div></div>`
}

function drucken() {
  fehler.value = ''

  if (!etiketten.value.length) return

  const masse = format.value
  const inhalt = seiten.value
    .map((seite) => `<div class="seite">${seite.map(etikettHtml).join('')}</div>`)
    .join('')

  const html = `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>RackView-Etiketten</title>
<style>
  @page { size: ${masse.seite}; margin: 0; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: #000;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .seite {
    display: grid;
    grid-template-columns: repeat(${masse.spalten}, ${masse.breite}mm);
    grid-auto-rows: ${masse.hoehe}mm;
    break-after: page;
    page-break-after: always;
  }
  .seite:last-child { break-after: auto; page-break-after: auto; }
  .etikett {
    display: flex;
    align-items: center;
    gap: 2.5mm;
    padding: 2.5mm;
    overflow: hidden;
  }
  .etikett.leer { visibility: hidden; }
  .qr { flex: 0 0 auto; width: ${qrKante.value}mm; height: ${qrKante.value}mm; }
  .qr svg { display: block; width: 100%; height: 100%; }
  .text { display: flex; flex-direction: column; gap: 0.8mm; min-width: 0; }
  .text strong { font-size: ${masse.hoehe > 32 ? 10 : 9}pt; line-height: 1.15; overflow-wrap: anywhere; }
  .text span { font-size: 7pt; color: #222; overflow-wrap: anywhere; }
</style>
</head>
<body>${inhalt}<script>window.onload = () => window.print()<\/script></body>
</html>`

  const fenster = window.open('', '_blank')

  if (!fenster) {
    fehler.value = 'Der Browser hat das Druckfenster blockiert. Bitte Pop-ups für RackView erlauben.'
    return
  }

  fenster.document.open()
  fenster.document.write(html)
  fenster.document.close()
}
</script>

<template>
  <section class="panel view-panel">
    <div class="etiketten-kopf">
      <div class="etiketten-format">
        <span class="etiketten-titel">Format</span>

        <label v-for="eintrag in FORMATE" :key="eintrag.id" class="etiketten-option">
          <input v-model="formatId" type="radio" :value="eintrag.id" />
          <span>
            <strong>{{ eintrag.name }}</strong>
            <small>{{ eintrag.beschreibung }}</small>
          </span>
        </label>
      </div>

      <div class="etiketten-inhalt">
        <span class="etiketten-titel">Aufdruck</span>

        <label class="etiketten-haken">
          <input v-model="mitRack" type="checkbox" /> Rack und Höheneinheit bzw. Standort
        </label>

        <label class="etiketten-haken">
          <input v-model="mitIp" type="checkbox" /> IP-Adresse
        </label>

        <label class="etiketten-haken">
          <input v-model="mitModell" type="checkbox" /> Hersteller und Modell
        </label>

        <label v-if="formatId === 'bogen'" class="etiketten-haken etiketten-zahl">
          Erste Felder frei lassen
          <input v-model.number="ueberspringen" type="number" min="0" :max="proSeite - 1" />
        </label>
      </div>
    </div>

    <p class="etiketten-hinweis">
      Der QR-Code führt zu <code>{{ basis }}/#device/…</code> und öffnet das Gerät
      direkt in RackView. Zum Scannen muss man im selben Netz und in RackView
      angemeldet sein.
    </p>

    <div class="etiketten-toolbar">
      <input
        v-model="suche"
        type="text"
        placeholder="Name, IP, Seriennummer …"
        aria-label="Geräte filtern"
        spellcheck="false"
      />

      <select v-model="rackFilter" aria-label="Nach Rack oder Standort filtern">
        <option value="all">Überall</option>
        <optgroup v-if="racks.length" label="Racks">
          <option v-for="rack in racks" :key="`rack-${rack.id}`" :value="`rack:${rack.id}`">{{ rack.name }}</option>
        </optgroup>
        <optgroup v-if="locations.length" label="Standorte">
          <option v-for="ort in locations" :key="`ort-${ort.id}`" :value="`ort:${ort.id}`">{{ ort.name }}</option>
        </optgroup>
      </select>

      <button type="button" class="etiketten-button" @click="alleGefiltertenSetzen(true)">Alle auswählen</button>
      <button type="button" class="etiketten-button" @click="alleGefiltertenSetzen(false)">Auswahl leeren</button>

      <span class="etiketten-zaehler">
        {{ etiketten.length }} {{ etiketten.length === 1 ? 'Etikett' : 'Etiketten' }} ·
        {{ seiten.length }} {{ seiten.length === 1 ? 'Seite' : 'Seiten' }}
      </span>

      <button
        type="button"
        class="etiketten-button primary"
        :disabled="!etiketten.length"
        @click="drucken"
      >
        ⎙ Drucken
      </button>
    </div>

    <div v-if="fehler" class="etiketten-fehler" role="alert">{{ fehler }}</div>

    <div class="etiketten-spalten">
      <div class="etiketten-liste">
        <label
          v-for="device in gefiltert"
          :key="device.id"
          class="etiketten-zeile"
          :class="{ gewaehlt: auswahl.has(Number(device.id)) }"
        >
          <input
            type="checkbox"
            :checked="auswahl.has(Number(device.id))"
            @change="umschalten(device.id)"
          />

          <span class="etiketten-zeile-info">
            <strong>{{ device.name }}</strong>
            <small>
              {{ woSteht(device) || 'Ohne Rack' }}
              · {{ getDeviceTypeLabel(device.device_type) }}
              <template v-if="device.ip_address"> · {{ device.ip_address }}</template>
            </small>
          </span>
        </label>

        <p v-if="!gefiltert.length" class="etiketten-leer">Keine Geräte gefunden.</p>
      </div>

      <div class="etiketten-vorschau">
        <span class="etiketten-titel">Vorschau</span>

        <p v-if="!etiketten.length" class="etiketten-leer">
          Wähle links die Geräte aus, für die du Etiketten drucken willst.
        </p>

        <!-- Millimetergenau wie im Druck, nur etwas verkleinert -->
        <div
          v-for="(seite, index) in seiten"
          :key="index"
          class="vorschau-seite"
          :style="{
            '--spalten': format.spalten,
            '--breite': `${format.breite}mm`,
            '--hoehe': `${format.hoehe}mm`,
            '--qr': `${qrKante}mm`,
          }"
        >
          <div
            v-for="(eintrag, feld) in seite"
            :key="feld"
            class="vorschau-etikett"
            :class="{ leer: !eintrag }"
          >
            <template v-if="eintrag">
              <div class="vorschau-qr" v-html="eintrag.qr"></div>
              <div class="vorschau-text">
                <strong>{{ eintrag.name }}</strong>
                <span v-for="zeile in eintrag.zeilen" :key="zeile">{{ zeile }}</span>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.view-panel {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 22px 24px 26px;
}

.etiketten-titel {
  display: block;
  margin-bottom: 8px;

  color: var(--t-ton-67);
  font-size: 10px;
  font-weight: 850;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

/* --- Format und Aufdruck ------------------------------------- */

.etiketten-kopf {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  gap: 18px;
}

.etiketten-format,
.etiketten-inhalt {
  padding: 16px 18px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 14px;
  background: var(--f-grau-99);
}

.etiketten-option {
  display: flex;
  align-items: flex-start;
  gap: 10px;

  padding: 6px 0;

  cursor: pointer;
}

.etiketten-option span {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.etiketten-option strong {
  color: var(--t-ton-15);
  font-size: 13px;
}

.etiketten-option small,
.etiketten-zeile-info small {
  color: var(--t-ton-61-2);
  font-size: 11.5px;
}

.etiketten-haken {
  display: flex;
  align-items: center;
  gap: 8px;

  padding: 5px 0;

  color: var(--t-ton-40);
  font-size: 12.5px;
  font-weight: 600;

  cursor: pointer;
}

.etiketten-zahl input {
  width: 70px;
  margin-left: 6px;
}

.etiketten-hinweis {
  margin: 0;

  color: var(--t-ton-47-3) !important;
  font-size: 12px;
  line-height: 1.5;
}

.etiketten-hinweis code {
  padding: 1px 5px;

  border-radius: 5px;
  background: var(--f-ton-96-2);

  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11.5px;
}

/* --- Werkzeugleiste ------------------------------------------ */

.etiketten-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.etiketten-toolbar input[type='text'],
.etiketten-toolbar select {
  min-height: 38px;
  padding: 8px 11px;

  border: 1px solid var(--f-ton-91-5);
  border-radius: 10px;
  background: var(--f-grau-100);

  color: var(--t-ton-15);
  font: inherit;
  font-size: 13px;
}

.etiketten-toolbar input[type='text'] {
  flex: 1 1 220px;
  min-width: 0;
}

.etiketten-button {
  min-height: 38px;
  padding: 0 14px;

  border: 1px solid var(--f-ton-91-2);
  border-radius: 10px;
  background: var(--f-grau-100);

  color: var(--t-ton-40);
  font-size: 12.5px !important;
  font-weight: 700;

  white-space: nowrap;
}

.etiketten-button:hover:not(:disabled) {
  border-color: var(--f-blau-86-2);
  color: var(--t-blau-53);
}

.etiketten-button.primary {
  border-color: transparent;
  color: var(--t-grau-100);
  background: var(--rv-blue, var(--f-blau-53));
}

.etiketten-button:disabled {
  opacity: 0.5;
  cursor: default !important;
}

.etiketten-zaehler {
  margin-left: auto;

  color: var(--t-ton-47-3);
  font-size: 12px;
  font-weight: 700;
}

.etiketten-fehler {
  padding: 10px 12px;

  border-radius: 9px;
  background: var(--f-ton-97-3);

  color: var(--t-rot-42);
  font-size: 12px;
  font-weight: 600;
}

/* --- Liste und Vorschau -------------------------------------- */

.etiketten-spalten {
  display: grid;
  grid-template-columns: minmax(260px, 0.8fr) minmax(0, 1.2fr);
  align-items: start;
  gap: 18px;
}

.etiketten-liste {
  display: flex;
  flex-direction: column;
  gap: 6px;

  max-height: 640px;
  overflow-y: auto;
}

.etiketten-zeile {
  display: flex;
  align-items: center;
  gap: 10px;

  padding: 9px 12px;

  border: 1px solid var(--f-ton-95);
  border-radius: 10px;

  cursor: pointer;
}

.etiketten-zeile.gewaehlt {
  border-color: var(--f-blau-86-2);
  background: var(--f-ton-98-3);
}

.etiketten-zeile-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.etiketten-zeile-info strong {
  color: var(--t-ton-15);
  font-size: 13px;
  font-weight: 750;
}

.etiketten-leer {
  margin: 0;
  color: var(--t-ton-61-2) !important;
  font-size: 12.5px;
}

.etiketten-vorschau {
  overflow: auto;
  max-height: 640px;

  /* Nur die Vorschau ist verkleinert, der Druck bleibt maßhaltig */
  zoom: 0.8;
}

.vorschau-seite {
  display: grid;
  grid-template-columns: repeat(var(--spalten), var(--breite));
  grid-auto-rows: var(--hoehe);

  width: max-content;
  margin-bottom: 14px;

  border: 1px solid #dfe6ef;
  border-radius: 4px;
  background: #ffffff;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
}

.vorschau-etikett {
  display: flex;
  align-items: center;
  gap: 2.5mm;
  overflow: hidden;

  padding: 2.5mm;

  border-right: 1px dashed #e8edf4;
  border-bottom: 1px dashed #e8edf4;
}

.vorschau-etikett.leer {
  background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 4px, #eef2f7 4px, #eef2f7 8px);
}

.vorschau-qr {
  flex: 0 0 auto;
  width: var(--qr);
  height: var(--qr);
}

.vorschau-qr :deep(svg) {
  display: block;
  width: 100%;
  height: 100%;
}

.vorschau-text {
  display: flex;
  flex-direction: column;
  gap: 0.8mm;
  min-width: 0;
}

.vorschau-text strong {
  color: #000000;
  font-size: 10pt;
  font-weight: 700;
  line-height: 1.15;
  overflow-wrap: anywhere;
}

.vorschau-text span {
  color: #222222;
  font-size: 7pt;
  overflow-wrap: anywhere;
}

@media (max-width: 1100px) {
  .etiketten-kopf,
  .etiketten-spalten {
    grid-template-columns: minmax(0, 1fr);
  }

  .etiketten-liste {
    max-height: 340px;
  }
}
</style>

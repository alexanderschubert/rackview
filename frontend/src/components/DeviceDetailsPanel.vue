<script setup>
import { computed, ref, watch } from 'vue'
import {
  formatDate,
  getDeviceTypeLabel,
  getMountSideLabel,
  getPoeShort,
  getStatusLabel,
  getWarrantyInfo,
  hasOutlets,
} from '../lib/deviceMeta.js'
import { portNumber, portState, portStateLabel } from '../lib/ports.js'

const props = defineProps({
  device: { type: Object, required: true },
  ports: { type: Array, default: () => [] },
  rack: { type: Object, default: null },
  // Der Standort, falls das Geraet nicht im Rack steht
  location: { type: Object, default: null },
  selectedPortId: { type: Number, default: null },
  uploading: { type: Boolean, default: false },
  // Steckplaetze dieses Geraets, falls es eine Leiste oder USV ist
  outlets: { type: Array, default: () => [] },
  // Alle Geraete des Bereichs - fuer die Auswahl je Steckplatz
  devices: { type: Array, default: () => [] },
  // Mit welchem Reiter das Panel aufgeht. Aus der Platzuebersicht
  // fuehrt der Weg direkt zu den Steckplaetzen.
  startTab: { type: String, default: 'overview' },
})

const emit = defineEmits(['select-port', 'upload-image', 'save-outlet'])

function bildGewaehlt(event) {
  const datei = event.target.files?.[0]
  event.target.value = ''

  if (datei) emit('upload-image', datei)
}

// --- Tabs -----------------------------------------------------

const tab = ref(props.startTab)

/** Nur auf einen Reiter wechseln, den es bei diesem Geraet auch gibt */
function setzeReiter(wunsch) {
  tab.value = tabs.value.some((eintrag) => eintrag.key === wunsch) ? wunsch : 'overview'
}

// Beim Wechsel auf ein anderes Geraet wieder vorn beginnen
watch(
  () => props.device.id,
  () => {
    setzeReiter(props.startTab)
    externOffen.value = new Set()
  }
)

// Wird dasselbe Geraet erneut geoeffnet, wechselt nur die Vorgabe
watch(() => props.startTab, (wunsch) => setzeReiter(wunsch))

// Der Reiter erscheint bei Leisten und USVs - und ausserdem immer
// dann, wenn schon Plaetze angelegt sind. Sonst waeren sie nach einem
// Typwechsel unsichtbar, aber noch vorhanden.
const zeigeSteckplaetze = computed(() => hasOutlets(props.device) || props.outlets.length > 0)

const belegtePlaetze = computed(() =>
  props.outlets.filter((platz) => platz.connected_device_id || platz.external_label).length
)

const tabs = computed(() => {
  const liste = [
    { key: 'overview', label: 'Übersicht' },
    { key: 'ports', label: `Ports (${props.ports.length})` },
  ]

  if (zeigeSteckplaetze.value) {
    liste.push({
      key: 'outlets',
      label: `Steckplätze (${belegtePlaetze.value}/${props.outlets.length})`,
    })
  }

  return [...liste, { key: 'network', label: 'IP & MAC' }, { key: 'notes', label: 'Notizen' }]
})

// Zur Auswahl stehen alle Geraete ausser der Leiste selbst, nach Namen
// sortiert. Was schon an einem anderen Platz haengt, bleibt waehlbar -
// das Backend fragt dann, ob umgesteckt werden soll.
const waehlbareGeraete = computed(() =>
  [...props.devices]
    .filter((geraet) => Number(geraet.id) !== Number(props.device.id))
    .sort((a, b) => String(a.name).localeCompare(String(b.name), 'de'))
)

// Plaetze, bei denen „Anderes Gerät" gewaehlt wurde, aber noch kein
// Text dasteht. Ohne diese Merkliste spraenge die Auswahl sofort
// zurueck auf „frei", weil nichts gespeichert wurde.
const externOffen = ref(new Set())

// 'frei' | 'extern' | die ID eines Geraets
function auswahlWert(platz) {
  if (platz.connected_device_id) return String(platz.connected_device_id)

  return platz.external_label || externOffen.value.has(platz.id) ? 'extern' : 'frei'
}

function auswahlGeaendert(platz, wert) {
  const offen = new Set(externOffen.value)

  if (wert === 'extern') {
    offen.add(platz.id)
    externOffen.value = offen

    // Hing hier ein Geraet, wird es jetzt ausgesteckt; sonst gibt es
    // noch nichts zu speichern.
    if (platz.connected_device_id) {
      emit('save-outlet', platz, { connected_device_id: null, external_label: null })
    }

    return
  }

  offen.delete(platz.id)
  externOffen.value = offen

  emit('save-outlet', platz, {
    connected_device_id: wert === 'frei' ? null : Number(wert),
    external_label: null,
  })
}

// --- Daten ----------------------------------------------------

const hoehe = computed(() => Number(props.device.height_units) || 1)

const position = computed(() => {
  // Ausserhalb des Racks steht statt der Hoeheneinheit der Standort
  if (!props.device.rack_id) return props.location?.name || 'Ohne Rack'

  const start = Number(props.device.start_unit)

  if (!start) return ''

  const ende = start + hoehe.value - 1
  const einheiten = ende > start ? `HE ${start}–${ende}` : `HE ${start}`

  return props.rack ? `${props.rack.name} · ${einheiten}` : einheiten
})

const portZusammenfassung = computed(() => {
  const gesamt = props.ports.length

  if (!gesamt) return ''

  const verbunden = props.ports.filter((port) => portState(port) === 'connected').length
  const poe = props.ports.filter((port) => port.poe).length
  const teile = [`${gesamt} gesamt`, `${verbunden} verbunden`]

  if (poe) teile.push(`${poe} PoE`)

  return teile.join(' · ')
})

const uebersicht = computed(() => {
  const liste = [
    ['Hersteller', props.device.manufacturer],
    ['Modell', props.device.model],
    ['Typ', getDeviceTypeLabel(props.device.device_type)],
    ['Standort', position.value],
  ]

  // Hoehe und Einbauseite haben nur im Rack eine Bedeutung
  if (props.device.rack_id) {
    liste.push(
      ['Höhe', `${hoehe.value} HE`],
      ['Einbauseite', getMountSideLabel(props.device)]
    )
  }

  liste.push(
    ['Seriennummer', props.device.serial_number],
    ['Gekauft am', formatDate(props.device.purchase_date)],
    ['Ports', portZusammenfassung.value]
  )

  return liste
})

// Steht getrennt, weil der Zustand eingefaerbt wird - abgelaufen rot,
// demnaechst ablaufend gelb.
const garantie = computed(() => getWarrantyInfo(props.device))

const netzwerk = computed(() => [
  ['IP-Adresse', props.device.ip_address, true],
  ['MAC-Adresse', props.device.mac_address, true],
  ['VLAN', props.device.vlan, false],
  ['Switch-Port', props.device.switch_port, false],
  ['Uplink-Port', props.device.uplink_port, false],
])

// Nur fuer etwas, das nach IPv4-Adresse oder Hostname aussieht, einen
// Link anbieten - das Feld ist Freitext.
const webUrl = computed(() => {
  const wert = String(props.device.ip_address || '').trim()
  const istIp = /^(\d{1,3}\.){3}\d{1,3}$/.test(wert)
  const istHost = /^[a-z0-9-]+(\.[a-z0-9-]+)+$/i.test(wert)

  return istIp || istHost ? `https://${wert}` : null
})

function peerLabel(port) {
  const gegenstelle = port.connection?.peer_device

  return gegenstelle ? `→ ${gegenstelle.name}` : portStateLabel(port)
}
</script>

<template>
  <div class="device-panel">
    <div class="device-panel-hero">
      <div class="device-panel-media">
      <!-- Das Foto des echten Geraets; im Rack bleibt die Vektorgrafik -->
      <div class="device-panel-photo">
        <img v-if="device.image_url" :src="device.image_url" :alt="`Foto ${device.name}`" />
        <span v-else class="device-panel-photo-empty">Kein Foto</span>
      </div>

      <label class="device-panel-upload" :class="{ busy: uploading }">
        {{ uploading ? 'Wird hochgeladen …' : device.image_url ? 'Foto ändern' : 'Foto hinzufügen' }}
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp,image/avif"
          :disabled="uploading"
          @change="bildGewaehlt"
        />
      </label>
      </div>

      <div class="device-panel-title">
        <h3>{{ device.name }}</h3>

        <p v-if="device.manufacturer || device.model">
          {{ [device.manufacturer, device.model].filter(Boolean).join(' ') }}
        </p>

        <div class="device-panel-badges">
          <span class="device-panel-badge">{{ getDeviceTypeLabel(device.device_type) }}</span>
          <span v-if="device.vlan" class="device-panel-badge">VLAN {{ device.vlan }}</span>
          <span v-if="device.poe_ports" class="device-panel-badge">
            {{ device.poe_ports }} × {{ getPoeShort(device.poe_type) || 'PoE' }}
          </span>
        </div>

        <span class="device-panel-status" :class="`status-${device.status || 'active'}`">
          <i></i>{{ getStatusLabel(device.status) }}
        </span>
      </div>
    </div>

    <div class="device-panel-tabs" role="tablist">
      <button
        v-for="eintrag in tabs"
        :key="eintrag.key"
        type="button"
        role="tab"
        :aria-selected="tab === eintrag.key"
        :class="{ active: tab === eintrag.key }"
        @click="tab = eintrag.key"
      >
        {{ eintrag.label }}
      </button>
    </div>

    <!-- Uebersicht -->
    <dl v-if="tab === 'overview'" class="device-panel-table">
      <template v-for="[label, wert] in uebersicht" :key="label">
        <dt>{{ label }}</dt>
        <dd>{{ wert || '–' }}</dd>
      </template>

      <dt>Garantie</dt>
      <dd :class="`garantie-${garantie.zustand}`">{{ garantie.text }}</dd>
    </dl>

    <!-- Ports -->
    <div v-else-if="tab === 'ports'" class="device-panel-ports">
      <button
        v-for="(port, index) in ports"
        :key="port.id"
        type="button"
        class="device-panel-port"
        :class="[`port-${portState(port)}`, { active: port.id === selectedPortId }]"
        @click="emit('select-port', port)"
      >
        <span class="device-panel-port-number">{{ portNumber(port, index) }}</span>
        <span class="device-panel-port-name">{{ port.name }}</span>
        <span v-if="port.poe" class="device-panel-port-poe">PoE</span>
        <span class="device-panel-port-state">{{ peerLabel(port) }}</span>
      </button>

      <p v-if="!ports.length" class="device-panel-empty">
        Für dieses Gerät sind noch keine Ports erfasst.
      </p>
    </div>

    <!-- Steckplaetze -->
    <div v-else-if="tab === 'outlets'" class="device-panel-outlets">
      <div v-for="platz in outlets" :key="platz.id" class="outlet-row">
        <span class="outlet-nummer">{{ platz.position }}</span>

        <input
          class="outlet-bezeichnung"
          :value="platz.label || ''"
          type="text"
          placeholder="Bezeichnung"
          @change="emit('save-outlet', platz, { label: $event.target.value })"
        />

        <select
          class="outlet-geraet"
          :value="auswahlWert(platz)"
          @change="auswahlGeaendert(platz, $event.target.value)"
        >
          <option value="frei">— frei —</option>
          <option value="extern">Anderes Gerät (Text)</option>
          <option v-for="geraet in waehlbareGeraete" :key="geraet.id" :value="String(geraet.id)">
            {{ geraet.name }}
          </option>
        </select>

        <input
          v-if="auswahlWert(platz) === 'extern'"
          class="outlet-extern"
          :value="platz.external_label || ''"
          type="text"
          placeholder="z. B. Kaffeemaschine"
          @change="emit('save-outlet', platz, { connected_device_id: null, external_label: $event.target.value })"
        />

        <input
          class="outlet-notiz"
          :value="platz.notes || ''"
          type="text"
          placeholder="Notiz"
          @change="emit('save-outlet', platz, { notes: $event.target.value })"
        />
      </div>

      <p v-if="!outlets.length" class="device-panel-empty">
        Noch keine Steckplätze. Die Anzahl wird beim Gerät eingetragen
        („Gerät bearbeiten" → Steckplätze).
      </p>
    </div>

    <!-- IP & MAC -->
    <dl v-else-if="tab === 'network'" class="device-panel-table">
      <template v-for="[label, wert, mono] in netzwerk" :key="label">
        <dt>{{ label }}</dt>
        <dd :class="{ mono: mono && wert }">{{ wert || '–' }}</dd>
      </template>

      <template v-if="webUrl">
        <dt>Weboberfläche</dt>
        <dd>
          <a :href="webUrl" target="_blank" rel="noopener noreferrer">{{ webUrl }} ↗</a>
        </dd>
      </template>
    </dl>

    <!-- Notizen -->
    <div v-else class="device-panel-notes">
      <template v-if="device.description || device.notes">
        <section v-if="device.description">
          <h4>Beschreibung</h4>
          <p>{{ device.description }}</p>
        </section>

        <section v-if="device.notes">
          <h4>Notizen</h4>
          <p>{{ device.notes }}</p>
        </section>
      </template>

      <p v-else class="device-panel-empty">Keine Beschreibung oder Notizen hinterlegt.</p>
    </div>
  </div>
</template>

<style scoped>
.device-panel {
  display: flex;
  flex-direction: column;
  gap: 16px;

  padding: 0 24px 20px;
}

/* --- Kopf --- */

.device-panel-hero {
  display: grid;
  grid-template-columns: 160px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.device-panel-photo {
  display: grid;
  place-items: center;

  aspect-ratio: 4 / 3;
  overflow: hidden;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 10px;

  background: var(--f-grau-97-2);
}

.device-panel-photo-empty {
  color: var(--t-ton-66);
  font-size: 11px;
  font-weight: 600;
}

.device-panel-media {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.device-panel-upload {
  display: block;
  padding: 5px 8px;

  border: 1px dashed var(--f-ton-86);
  border-radius: 7px;

  color: var(--t-ton-40);
  background: var(--f-grau-99-3);

  font-size: 11px;
  font-weight: 700;
  text-align: center;

  cursor: pointer;
}

.device-panel-upload:hover {
  border-color: var(--f-blau-77);
  color: var(--t-blau-53);
}

.device-panel-upload.busy {
  cursor: progress;
  opacity: 0.7;
}

/* Das Dateifeld bleibt verborgen, das Label ist die Schaltflaeche */
.device-panel-upload input {
  position: absolute;
  width: 1px;
  height: 1px;

  opacity: 0;
  pointer-events: none;
}

.device-panel-photo img {
  display: block;
  width: 100%;
  height: 100%;

  object-fit: contain;
}

.device-panel-title {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;

  min-width: 0;
}

.device-panel-title h3 {
  margin: 0;

  color: var(--t-ton-11);
  font-size: 16px;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.device-panel-title p {
  margin: 0;

  color: var(--t-ton-54);
  font-size: 12px;
}

.device-panel-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.device-panel-badge {
  padding: 3px 8px;

  border: 1px solid var(--f-ton-91);
  border-radius: 6px;

  color: var(--t-ton-37);
  background: var(--f-grau-97-2);

  font-size: 10.5px;
  font-weight: 700;
}

.device-panel-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;

  padding: 4px 10px;
  border-radius: 999px;

  color: var(--t-gruen-30);
  background: var(--f-ton-92-2);

  font-size: 11px;
  font-weight: 750;
}

.device-panel-status i {
  width: 7px;
  height: 7px;

  border-radius: 50%;
  background: currentColor;
}

.device-panel-status.status-planned {
  color: var(--t-amber-33);
  background: var(--f-amber-89);
}

.device-panel-status.status-maintenance {
  color: var(--t-blau-50);
  background: var(--f-ton-95-4);
}

.device-panel-status.status-retired {
  color: var(--t-ton-47-3);
  background: var(--f-ton-95-2);
}

/* --- Tabs --- */

.device-panel-tabs {
  display: flex;
  gap: 2px;
  overflow-x: auto;

  border-bottom: 1px solid var(--f-ton-93-4);
}

.device-panel-tabs button {
  flex-shrink: 0;
  margin-bottom: -1px;
  padding: 9px 11px;

  border: 0;
  border-bottom: 2px solid transparent;

  color: var(--t-ton-54);
  background: transparent;

  font: inherit;
  font-size: 12px;
  font-weight: 700;

  cursor: pointer;
}

.device-panel-tabs button:hover {
  color: var(--t-ton-23);
}

.device-panel-tabs button.active {
  color: var(--t-blau-53);
  border-bottom-color: var(--f-blau-53);
}

/* --- Tabelle --- */

.device-panel-table {
  display: grid;
  grid-template-columns: minmax(110px, auto) minmax(0, 1fr);

  margin: 0;

  border: 1px solid var(--f-ton-95);
  border-radius: 10px;
  overflow: hidden;
}

.device-panel-table dt,
.device-panel-table dd {
  margin: 0;
  padding: 9px 12px;

  border-bottom: 1px solid var(--f-ton-95);

  font-size: 12px;
}

.device-panel-table dt {
  color: var(--t-ton-54);
  background: var(--f-grau-99-2);
  font-weight: 600;
}

.device-panel-table dd {
  overflow: hidden;

  color: var(--t-ton-11);
  font-weight: 650;

  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Zustand der Garantie. Der Selektor nennt die Tabelle mit, sonst
   gewinnt die Grundfarbe von ".device-panel-table dd". */
.device-panel-table dd.garantie-abgelaufen {
  color: var(--t-rot-42);
}

.device-panel-table dd.garantie-bald {
  color: var(--t-amber-37);
}

.device-panel-table dt:nth-last-of-type(1),
.device-panel-table dd:nth-last-of-type(1) {
  border-bottom: 0;
}

.device-panel-table dd.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11.5px;
}

.device-panel-table a {
  color: var(--t-blau-53);
  text-decoration: none;
}

.device-panel-table a:hover {
  text-decoration: underline;
}

/* --- Ports --- */

.device-panel-ports {
  display: flex;
  flex-direction: column;
  gap: 4px;

  max-height: 280px;
  overflow-y: auto;
}

.device-panel-port {
  display: flex;
  align-items: center;
  gap: 9px;

  padding: 7px 9px;

  border: 1px solid var(--f-ton-95);
  border-radius: 8px;

  color: var(--t-ton-23);
  background: var(--f-grau-100);

  font: inherit;
  font-size: 12px;
  text-align: left;

  cursor: pointer;
}

.device-panel-port:hover {
  border-color: var(--f-blau-85);
}

.device-panel-port.active {
  border-color: var(--f-blau-53);
  box-shadow: 0 0 0 2px var(--f-blau-53-6);
}

.device-panel-port-number {
  display: grid;
  place-items: center;

  min-width: 24px;
  height: 22px;
  padding: 0 4px;

  border: 2px solid var(--f-ton-84);
  border-radius: 5px;

  color: var(--t-ton-47-3);
  background: var(--f-ton-96-2);

  font-size: 10px;
  font-weight: 800;
}

.device-panel-port.port-connected .device-panel-port-number {
  color: var(--t-gruen-29);
  border-color: var(--f-gruen-45);
  background: var(--f-ton-93-2);
}

.device-panel-port.port-faulty .device-panel-port-number {
  color: var(--t-rot-42);
  border-color: var(--f-rot-60);
  background: var(--f-ton-94);
}

.device-panel-port.port-disabled .device-panel-port-number {
  color: var(--t-ton-27-2);
  border-color: var(--f-ton-35);
  background: var(--f-ton-84);
}

.device-panel-port-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;

  font-weight: 650;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.device-panel-port-poe {
  padding: 1px 5px;

  border-radius: 4px;

  color: var(--t-amber-33);
  background: var(--f-amber-89);

  font-size: 9.5px;
  font-weight: 800;
}

.device-panel-port-state {
  flex-shrink: 0;
  max-width: 45%;
  overflow: hidden;

  color: var(--t-ton-54);
  font-size: 11px;

  text-overflow: ellipsis;
  white-space: nowrap;
}

/* --- Notizen --- */

.device-panel-notes {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.device-panel-notes h4 {
  margin: 0 0 5px;

  color: var(--t-ton-54);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.device-panel-notes p {
  margin: 0;

  color: var(--t-ton-23);
  font-size: 12.5px;
  line-height: 1.55;

  white-space: pre-wrap;
}

.device-panel-empty {
  margin: 0;
  padding: 18px 12px;

  border: 1px dashed var(--f-ton-91-3);
  border-radius: 10px;

  color: var(--t-ton-61-2);
  font-size: 12px;
  text-align: center;
}

/* --- Steckplaetze ---------------------------------------------- */

.device-panel-outlets {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.outlet-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;

  padding: 7px 10px;

  border: 1px solid var(--f-ton-95);
  border-radius: 10px;
}

.outlet-nummer {
  display: grid;
  place-items: center;

  width: 24px;
  height: 24px;
  flex-shrink: 0;

  border-radius: 7px;
  background: var(--f-grau-97-2);

  color: var(--t-ton-54);
  font-size: 11px;
  font-weight: 750;
}

.outlet-row input,
.outlet-row select {
  min-width: 0;
  padding: 5px 8px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 8px;

  color: var(--t-ton-23);
  background: var(--f-grau-100);

  font-family: inherit;
  font-size: 12px;
}

.outlet-row input:focus,
.outlet-row select:focus {
  outline: 2px solid var(--f-blau-53);
  outline-offset: -1px;
}

.outlet-bezeichnung {
  width: 104px;
  flex-shrink: 0;
}

.outlet-geraet {
  flex: 1 1 170px;
}

.outlet-extern {
  flex: 1 1 150px;
}

.outlet-notiz {
  flex: 1 1 120px;
}

@media (max-width: 640px) {
  .device-panel-hero {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>

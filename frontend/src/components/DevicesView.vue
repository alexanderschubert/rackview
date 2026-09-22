<script setup>
import { computed, ref } from 'vue'
import {
  deviceStatuses,
  deviceTypes,
  getDeviceIcon,
  getDeviceTypeLabel,
  getPoeShort,
  getStatusLabel,
  getWarrantyInfo,
  WARRANTY_WARNING_DAYS,
} from '../lib/deviceMeta.js'
import { ipSortValue } from '../lib/network.js'

const props = defineProps({
  devices: { type: Array, default: () => [] },
  racks: { type: Array, default: () => [] },
  // Standorte ausserhalb der Racks
  locations: { type: Array, default: () => [] },
})

const emit = defineEmits(['select-device', 'add'])

const filter = ref('')
const typFilter = ref('all')
const statusFilter = ref('all')
const rackFilter = ref('all')

const sortKey = ref('name')
const sortAsc = ref(true)

function rackVon(device) {
  return props.racks.find((rack) => Number(rack.id) === Number(device.rack_id)) || null
}

function ortVon(device) {
  return props.locations.find((ort) => Number(ort.id) === Number(device.location_id)) || null
}

function standort(device) {
  // Ausserhalb des Racks gibt es keine Hoeheneinheiten
  if (!device.rack_id) return ortVon(device)?.name || 'Ohne Rack'

  const rack = rackVon(device)
  const start = Number(device.start_unit)
  const hoehe = Number(device.height_units) || 1
  const einheiten = start ? (hoehe > 1 ? `HE ${start}–${start + hoehe - 1}` : `HE ${start}`) : ''

  return [rack?.name, einheiten].filter(Boolean).join(' · ') || '–'
}

function portText(device) {
  const anzahl = Number(device.network_ports)

  if (!anzahl) return '–'

  const poe = Number(device.poe_ports)

  return poe ? `${anzahl} · ${poe} ${getPoeShort(device.poe_type) || 'PoE'}` : String(anzahl)
}

/** Garantie eines Geraets - in der Tabelle je Zeile gebraucht */
function garantie(device) {
  return getWarrantyInfo(device)
}

const SORTIERUNG = {
  name: (device) => String(device.name || '').toLowerCase(),
  typ: (device) => getDeviceTypeLabel(device.device_type),
  // Rackname, dann von oben nach unten wie im Rack. Standorte kommen
  // danach - sie haben keine Hoeheneinheiten, nach denen zu sortieren waere.
  standort: (device) => device.rack_id
    ? `1${rackVon(device)?.name || '~'}|${String(1000 - (Number(device.start_unit) || 0)).padStart(4, '0')}`
    : `2${ortVon(device)?.name || '~'}|${String(device.name || '')}`,
  ip: (device) => ipSortValue(device.ip_address),
  status: (device) => getStatusLabel(device.status),
  // Ohne Angabe ans Ende: "9999" ist groesser als jedes echte Datum
  garantie: (device) => device.warranty_until || '9999-12-31',
}

function sortiereNach(key) {
  if (sortKey.value === key) {
    sortAsc.value = !sortAsc.value
  } else {
    sortKey.value = key
    sortAsc.value = true
  }
}

function sortPfeil(key) {
  if (sortKey.value !== key) return ''
  return sortAsc.value ? '▲' : '▼'
}

const gefiltert = computed(() => {
  const suche = filter.value.trim().toLowerCase()
  const wert = SORTIERUNG[sortKey.value]
  const richtung = sortAsc.value ? 1 : -1

  return props.devices
    .filter((device) => typFilter.value === 'all' || device.device_type === typFilter.value)
    .filter((device) => statusFilter.value === 'all' || (device.status || 'active') === statusFilter.value)
    .filter((device) => {
      const wahl = String(rackFilter.value)

      if (wahl === 'all') return true
      if (wahl === 'ohne-rack') return ! device.rack_id
      if (wahl.startsWith('ort:')) return Number(device.location_id) === Number(wahl.slice(4))

      return Number(device.rack_id) === Number(rackFilter.value)
    })
    .filter(
      (device) =>
        !suche ||
        [device.name, device.manufacturer, device.model, device.ip_address, device.mac_address, device.serial_number, device.vlan]
          .some((feld) => String(feld || '').toLowerCase().includes(suche))
    )
    .sort((a, b) => {
      const x = wert(a)
      const y = wert(b)

      if (typeof x === 'number' && typeof y === 'number') return (x - y) * richtung

      return String(x).localeCompare(String(y), 'de', { numeric: true }) * richtung
    })
})

const kennzahlen = computed(() => {
  const status = (wert) => props.devices.filter((device) => (device.status || 'active') === wert).length

  return {
    gesamt: props.devices.length,
    aktiv: status('active'),
    wartung: status('maintenance') + status('planned'),
    // Geraete am Standort haben rack_id null - das ist kein weiteres Rack
    racks: new Set(props.devices.filter((device) => device.rack_id).map((device) => Number(device.rack_id))).size,
    // Abgelaufen oder laeuft demnaechst ab - beides will man sehen
    garantie: props.devices.filter((device) => ['bald', 'abgelaufen'].includes(garantie(device).zustand)).length,
  }
})

const filterAktiv = computed(() => {
  return Boolean(filter.value.trim()) || typFilter.value !== 'all' || statusFilter.value !== 'all' || rackFilter.value !== 'all'
})

function filterZuruecksetzen() {
  filter.value = ''
  typFilter.value = 'all'
  statusFilter.value = 'all'
  rackFilter.value = 'all'
}
</script>

<template>
  <section class="panel view-panel">
    <div class="view-stats">
      <div class="view-stat">
        <span>Geräte</span>
        <strong>{{ kennzahlen.gesamt }}</strong>
      </div>
      <div class="view-stat">
        <span>aktiv</span>
        <strong>{{ kennzahlen.aktiv }}</strong>
      </div>
      <div class="view-stat">
        <span>geplant / Wartung</span>
        <strong>{{ kennzahlen.wartung }}</strong>
      </div>
      <div class="view-stat">
        <span>in Racks</span>
        <strong>{{ kennzahlen.racks }}</strong>
      </div>

      <!-- Nur zeigen, wenn es etwas zu beachten gibt -->
      <div
        v-if="kennzahlen.garantie"
        class="view-stat view-stat-warnung"
        :title="`Abgelaufen oder läuft in den nächsten ${WARRANTY_WARNING_DAYS} Tagen ab`"
      >
        <span>Garantie beachten</span>
        <strong>{{ kennzahlen.garantie }}</strong>
      </div>
    </div>

    <div class="view-toolbar">
      <input
        v-model="filter"
        type="text"
        placeholder="Name, IP, MAC, Seriennummer …"
        aria-label="Geräte filtern"
        spellcheck="false"
      />

      <select v-model="typFilter" aria-label="Nach Typ filtern">
        <option value="all">Alle Typen</option>
        <option v-for="typ in deviceTypes" :key="typ.value" :value="typ.value">{{ typ.label }}</option>
      </select>

      <select v-model="statusFilter" aria-label="Nach Status filtern">
        <option value="all">Alle Status</option>
        <option v-for="status in deviceStatuses" :key="status.value" :value="status.value">{{ status.label }}</option>
      </select>

      <select v-model="rackFilter" aria-label="Nach Rack oder Standort filtern">
        <option value="all">Überall</option>
        <option value="ohne-rack">Ohne Rack</option>

        <optgroup v-if="racks.length" label="Racks">
          <option v-for="rack in racks" :key="rack.id" :value="rack.id">{{ rack.name }}</option>
        </optgroup>

        <optgroup v-if="locations.length" label="Standorte">
          <option v-for="ort in locations" :key="`o${ort.id}`" :value="`ort:${ort.id}`">{{ ort.name }}</option>
        </optgroup>
      </select>

      <button v-if="filterAktiv" type="button" class="view-reset" @click="filterZuruecksetzen">
        Filter zurücksetzen
      </button>

      <span class="view-count">{{ gefiltert.length }} von {{ devices.length }}</span>

      <button
        type="button"
        class="view-add"
        :disabled="!racks.length"
        :title="racks.length ? 'Neues Gerät anlegen' : 'Lege zuerst ein Rack an'"
        @click="emit('add')"
      >
        + Gerät
      </button>
    </div>

    <div v-if="gefiltert.length" class="view-table-wrap">
      <table class="view-table">
        <thead>
          <tr>
            <th><button type="button" @click="sortiereNach('name')">Gerät <i>{{ sortPfeil('name') }}</i></button></th>
            <th><button type="button" @click="sortiereNach('typ')">Typ <i>{{ sortPfeil('typ') }}</i></button></th>
            <th><button type="button" @click="sortiereNach('standort')">Standort <i>{{ sortPfeil('standort') }}</i></button></th>
            <th><button type="button" @click="sortiereNach('ip')">IP-Adresse <i>{{ sortPfeil('ip') }}</i></button></th>
            <th class="plain">Ports</th>
            <th><button type="button" @click="sortiereNach('garantie')">Garantie <i>{{ sortPfeil('garantie') }}</i></button></th>
            <th><button type="button" @click="sortiereNach('status')">Status <i>{{ sortPfeil('status') }}</i></button></th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="device in gefiltert" :key="device.id">
            <td>
              <button type="button" class="view-link" @click="emit('select-device', device)">
                <!-- Wo sonst das Symbol steht: das Foto des Geraets,
                     sofern eines hochgeladen wurde. -->
                <img
                  v-if="device.image_url"
                  class="view-vorschau"
                  :src="device.image_url"
                  :alt="`Foto von ${device.name}`"
                  loading="lazy"
                  decoding="async"
                />
                <span v-else class="view-icon">{{ getDeviceIcon(device.device_type) }}</span>
                {{ device.name }}
              </button>
              <small v-if="device.manufacturer || device.model" class="sub">
                {{ [device.manufacturer, device.model].filter(Boolean).join(' ') }}
              </small>
            </td>
            <td>{{ getDeviceTypeLabel(device.device_type) }}</td>
            <td>{{ standort(device) }}</td>
            <td class="mono">{{ device.ip_address || '–' }}</td>
            <td>{{ portText(device) }}</td>
            <td>
              <span
                v-if="garantie(device).zustand !== 'keine'"
                class="garantie-badge"
                :class="`garantie-${garantie(device).zustand}`"
                :title="garantie(device).text"
              >
                {{ garantie(device).kurz }}
              </span>
              <template v-else>–</template>
            </td>
            <td>
              <span class="status-badge" :class="`status-${device.status || 'active'}`">
                {{ getStatusLabel(device.status) }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-else class="view-empty">
      {{ devices.length ? 'Kein Gerät passt zu diesem Filter.' : 'Noch keine Geräte erfasst.' }}
    </p>
  </section>
</template>

<style scoped>
.view-panel {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 22px 24px 26px;
}

/* auto-fit statt fester Spaltenzahl: Die Garantiekachel kommt nur
   dazu, wenn es etwas zu melden gibt - mit vier Kacheln sieht es aus
   wie vorher. */
.view-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 10px;
}

.view-stat-warnung strong {
  color: var(--t-amber-37);
}

/* Garantie in der Tabelle */
.garantie-badge {
  display: inline-block;
  padding: 3px 9px;

  border-radius: 999px;

  font-size: 11px;
  font-weight: 750;
  white-space: nowrap;
}

.garantie-badge.garantie-aktiv {
  color: var(--t-ton-54);
  background: var(--f-ton-96-2);
}

.garantie-badge.garantie-bald {
  color: var(--t-amber-37);
  background: var(--f-amber-89);
}

.garantie-badge.garantie-abgelaufen {
  color: var(--t-rot-42);
  background: var(--f-rot-89);
}

.view-stat {
  display: flex;
  flex-direction: column;
  gap: 4px;

  padding: 12px 14px;

  border: 1px solid var(--f-ton-95);
  border-radius: 12px;
  background: var(--f-grau-99-2);
}

.view-stat span {
  color: var(--t-ton-54);
  font-size: 11px;
  font-weight: 600;
}

.view-stat strong {
  color: var(--t-ton-11);
  font-size: 22px;
  font-weight: 800;
}

.view-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.view-toolbar input,
.view-toolbar select {
  padding: 10px 12px;

  border: 1px solid var(--f-ton-91);
  border-radius: 10px;

  color: var(--t-ton-23);
  background: var(--f-grau-100);

  font: inherit;
  font-size: 12px;
}

.view-toolbar input {
  width: min(100%, 280px);
}

.view-toolbar input:focus,
.view-toolbar select:focus {
  border-color: var(--f-blau-86-2);
  outline: none;
  box-shadow: 0 0 0 3px var(--f-blau-60);
}

.view-reset {
  padding: 9px 11px;

  border: 0;
  border-radius: 9px;

  color: var(--t-blau-53);
  background: var(--f-ton-97);

  font: inherit;
  font-size: 12px;
  font-weight: 700;

  cursor: pointer;
}

.view-add {
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

.view-add:disabled {
  opacity: 0.5;
  cursor: default;
}

.view-count {
  margin-left: auto;

  color: var(--t-ton-61-2);
  font-size: 12px;
}

.view-table-wrap {
  overflow-x: auto;

  border: 1px solid var(--f-ton-95);
  border-radius: 10px;
}

.view-table {
  width: 100%;
  border-collapse: collapse;

  font-size: 12px;
}

.view-table th {
  padding: 0;

  border-bottom: 1px solid var(--f-ton-95);

  background: var(--f-grau-99-2);

  text-align: left;
  white-space: nowrap;
}

.view-table th button,
.view-table th.plain {
  padding: 9px 12px;

  color: var(--t-ton-54);

  font-size: 11px;
  font-weight: 700;
}

.view-table th button {
  display: flex;
  align-items: center;
  gap: 5px;

  width: 100%;

  border: 0;
  background: transparent;

  font-family: inherit;
  text-align: left;

  cursor: pointer;
}

.view-table th button:hover {
  color: var(--t-ton-23);
}

.view-table th i {
  font-size: 8px;
  font-style: normal;
}

.view-table td {
  padding: 9px 12px;

  border-bottom: 1px solid var(--f-ton-96-5);

  color: var(--t-ton-23);
  white-space: nowrap;
}

.view-table tbody tr:last-child td {
  border-bottom: 0;
}

.view-table tbody tr:hover td {
  background: var(--f-grau-99-3);
}

.sub {
  display: block;
  margin-top: 2px;

  color: var(--t-ton-61-2);
  font-size: 11px;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11.5px;
}

.status-badge {
  display: inline-block;
  padding: 3px 9px;

  border-radius: 999px;

  color: var(--t-gruen-30);
  background: var(--f-ton-92-2);

  font-size: 11px;
  font-weight: 750;
}

.status-badge.status-planned {
  color: var(--t-amber-33);
  background: var(--f-amber-89);
}

.status-badge.status-maintenance {
  color: var(--t-blau-50);
  background: var(--f-ton-95-4);
}

.status-badge.status-retired {
  color: var(--t-ton-47-3);
  background: var(--f-ton-95-2);
}

.view-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;

  padding: 0;
  border: 0;

  color: var(--t-blau-48-2);
  background: transparent;

  font: inherit;
  font-weight: 700;

  cursor: pointer;
}

.view-link:hover {
  text-decoration: underline;
}

.view-icon {
  color: var(--t-ton-54);
}

/* Vorschaubild an der Stelle des Symbols. Feste Groesse, damit die
   Zeilen gleich hoch bleiben - Geraetefotos sind meist breit und flach. */
.view-vorschau {
  width: 40px;
  height: 22px;
  flex-shrink: 0;

  object-fit: cover;

  border: 1px solid var(--f-ton-91-3);
  border-radius: 5px;
  background: var(--f-ton-96);
}

.view-empty {
  margin: 0;
  padding: 28px 12px;

  border: 1px dashed var(--f-ton-91-3);
  border-radius: 12px;

  color: var(--t-ton-61-2);
  font-size: 13px;
  text-align: center;
}

@media (max-width: 900px) {
  .view-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>

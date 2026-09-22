<script setup>
import { computed, ref } from 'vue'
import { getDeviceIcon, getDeviceTypeLabel } from '../lib/deviceMeta.js'
import { ipToNumber, SORT_LAST as ANS_ENDE } from '../lib/network.js'

const props = defineProps({
  devices: { type: Array, default: () => [] },
  racks: { type: Array, default: () => [] },
})

const emit = defineEmits(['select-device'])

const filter = ref('')


function ipOf(device) {
  return String(device.ip_address || '').trim()
}

function netzVon(ip) {
  return ipToNumber(ip) === null ? null : ip.split('.').slice(0, 3).join('.')
}

function rackLabel(device) {
  const rack = props.racks.find((item) => Number(item.id) === Number(device.rack_id))
  const einheit = device.start_unit ? `HE ${device.start_unit}` : ''

  return [rack?.name, einheit].filter(Boolean).join(' · ') || '–'
}

const gefiltert = computed(() => {
  const suche = filter.value.trim().toLowerCase()

  if (!suche) return props.devices

  return props.devices.filter((device) =>
    [device.name, device.ip_address, device.mac_address, device.vlan, device.manufacturer, device.model]
      .some((wert) => String(wert || '').toLowerCase().includes(suche))
  )
})

// Doppelte IPs ueber ALLE Geraete - ein Filter darf einen Konflikt
// nicht verstecken.
const doppelte = computed(() => {
  const zaehler = new Map()

  for (const device of props.devices) {
    const ip = ipOf(device)
    if (ip) zaehler.set(ip, (zaehler.get(ip) || 0) + 1)
  }

  return new Set([...zaehler].filter(([, anzahl]) => anzahl > 1).map(([ip]) => ip))
})

const ohneIp = computed(() => gefiltert.value.filter((device) => !ipOf(device)))

const gruppen = computed(() => {
  const map = new Map()

  for (const device of gefiltert.value) {
    const ip = ipOf(device)
    if (!ip) continue

    const zahl = ipToNumber(ip)
    const netz = netzVon(ip)
    const key = netz || 'sonstige'

    if (!map.has(key)) {
      map.set(key, {
        key,
        label: netz ? `${netz}.0/24` : 'Keine gültige IPv4-Adresse',
        sortierung: zahl === null ? ANS_ENDE : Math.floor(zahl / 256),
        eintraege: [],
      })
    }

    map.get(key).eintraege.push({ device, ip, zahl: zahl === null ? ANS_ENDE : zahl })
  }

  const liste = [...map.values()].sort((a, b) => a.sortierung - b.sortierung)

  for (const gruppe of liste) {
    gruppe.eintraege.sort((a, b) => a.zahl - b.zahl || a.ip.localeCompare(b.ip))
  }

  return liste
})

const kennzahlen = computed(() => ({
  gesamt: props.devices.length,
  mitIp: props.devices.filter((device) => ipOf(device)).length,
  netze: new Set(props.devices.map((device) => netzVon(ipOf(device))).filter(Boolean)).size,
  konflikte: doppelte.value.size,
}))
</script>

<template>
  <section class="panel view-panel">
    <div class="view-stats">
      <div class="view-stat">
        <span>Geräte</span>
        <strong>{{ kennzahlen.gesamt }}</strong>
      </div>
      <div class="view-stat">
        <span>mit IP-Adresse</span>
        <strong>{{ kennzahlen.mitIp }}</strong>
      </div>
      <div class="view-stat">
        <span>Netze (/24)</span>
        <strong>{{ kennzahlen.netze }}</strong>
      </div>
      <div class="view-stat" :class="{ warn: kennzahlen.konflikte }">
        <span>Doppelte IPs</span>
        <strong>{{ kennzahlen.konflikte }}</strong>
      </div>
    </div>

    <div class="view-toolbar">
      <input
        v-model="filter"
        type="text"
        placeholder="Filtern nach Name, IP, MAC, VLAN …"
        aria-label="Liste filtern"
        spellcheck="false"
      />
    </div>

    <div v-for="gruppe in gruppen" :key="gruppe.key" class="view-group">
      <h3>
        {{ gruppe.label }}
        <small>
          {{ gruppe.eintraege.length }}
          {{ gruppe.eintraege.length === 1 ? 'Adresse' : 'Adressen' }}
        </small>
      </h3>

      <div class="view-table-wrap">
        <table class="view-table">
          <thead>
            <tr>
              <th>IP-Adresse</th>
              <th>Gerät</th>
              <th>Typ</th>
              <th>Standort</th>
              <th>MAC-Adresse</th>
              <th>VLAN</th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="eintrag in gruppe.eintraege"
              :key="eintrag.device.id"
              :class="{ conflict: doppelte.has(eintrag.ip) }"
            >
              <td class="mono">
                {{ eintrag.ip }}
                <span v-if="doppelte.has(eintrag.ip)" class="view-flag">doppelt</span>
              </td>
              <td>
                <button type="button" class="view-link" @click="emit('select-device', eintrag.device)">
                  <span class="view-icon">{{ getDeviceIcon(eintrag.device.device_type) }}</span>
                  {{ eintrag.device.name }}
                </button>
              </td>
              <td>{{ getDeviceTypeLabel(eintrag.device.device_type) }}</td>
              <td>{{ rackLabel(eintrag.device) }}</td>
              <td class="mono">{{ eintrag.device.mac_address || '–' }}</td>
              <td>{{ eintrag.device.vlan || '–' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <details v-if="ohneIp.length" class="view-missing">
      <summary>Ohne IP-Adresse ({{ ohneIp.length }})</summary>

      <ul>
        <li v-for="device in ohneIp" :key="device.id">
          <button type="button" class="view-link" @click="emit('select-device', device)">
            <span class="view-icon">{{ getDeviceIcon(device.device_type) }}</span>
            {{ device.name }}
          </button>
          <span>{{ rackLabel(device) }}</span>
        </li>
      </ul>
    </details>

    <p v-if="!gruppen.length && !ohneIp.length" class="view-empty">
      {{ filter ? 'Keine Treffer für diesen Filter.' : 'Noch keine Geräte erfasst.' }}
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

.view-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
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

.view-stat.warn {
  border-color: var(--f-rot-89);
  background: var(--f-ton-97-3);
}

.view-stat.warn strong {
  color: var(--t-rot-42);
}

.view-toolbar input {
  width: min(100%, 360px);
  padding: 10px 12px;

  border: 1px solid var(--f-ton-91);
  border-radius: 10px;

  color: var(--t-ton-23);
  font: inherit;
  font-size: 12px;
}

.view-toolbar input:focus {
  border-color: var(--f-blau-86-2);
  outline: none;
  box-shadow: 0 0 0 3px var(--f-blau-60);
}

.view-group h3 {
  display: flex;
  align-items: baseline;
  gap: 10px;

  margin: 0 0 8px;

  color: var(--t-ton-11);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 14px;
  font-weight: 750;
}

.view-group h3 small {
  color: var(--t-ton-61-2);
  font-family: inherit;
  font-size: 11px;
  font-weight: 600;
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
  padding: 9px 12px;

  border-bottom: 1px solid var(--f-ton-95);

  color: var(--t-ton-54);
  background: var(--f-grau-99-2);

  font-size: 11px;
  font-weight: 700;
  text-align: left;
  white-space: nowrap;
}

.view-table td {
  padding: 8px 12px;

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

.view-table tr.conflict td {
  background: var(--f-ton-97-3);
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11.5px;
}

.view-flag {
  margin-left: 6px;
  padding: 1px 6px;

  border-radius: 4px;

  color: var(--t-grau-100);
  background: var(--f-rot-51);

  font-family: system-ui, sans-serif;
  font-size: 9.5px;
  font-weight: 800;
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

.view-missing summary {
  color: var(--t-ton-40);
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.view-missing ul {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 6px 16px;

  margin: 10px 0 0;
  padding: 0;

  list-style: none;
}

.view-missing li {
  display: flex;
  justify-content: space-between;
  gap: 10px;

  font-size: 12px;
}

.view-missing li span {
  color: var(--t-ton-61-2);
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

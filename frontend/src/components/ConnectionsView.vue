<script setup>
import { computed, ref } from 'vue'
import { getDeviceIcon } from '../lib/deviceMeta.js'

const props = defineProps({
  connections: { type: Array, default: () => [] },
  devices: { type: Array, default: () => [] },
})

const emit = defineEmits(['select-device', 'add', 'edit', 'delete'])

const STATUS = {
  active: { label: 'Aktiv', tone: 'ok' },
  planned: { label: 'Geplant', tone: 'warn' },
  faulty: { label: 'Defekt', tone: 'bad' },
  disconnected: { label: 'Getrennt', tone: 'neutral' },
}

function statusInfo(wert) {
  return STATUS[wert || 'active'] || { label: wert, tone: 'neutral' }
}

const filter = ref('')
const statusFilter = ref('all')

function geraet(verbindung, seite) {
  const id = Number(verbindung[`${seite}_device_id`])

  return (
    props.devices.find((device) => Number(device.id) === id) ||
    verbindung[`${seite}_device`] ||
    null
  )
}

function typLabel(wert) {
  return !wert || wert === 'direct' ? 'Direkt' : wert
}

const zeilen = computed(() => {
  return props.connections.map((verbindung) => {
    const quelle = geraet(verbindung, 'source')
    const ziel = geraet(verbindung, 'target')
    const quellPort = verbindung.source_port?.name || `Port #${verbindung.source_port_id}`
    const zielPort = verbindung.target_port?.name || `Port #${verbindung.target_port_id}`

    return {
      verbindung,
      quelle,
      ziel,
      quellPort,
      zielPort,
      status: statusInfo(verbindung.status),
      suchtext: [
        quelle?.name,
        ziel?.name,
        quellPort,
        zielPort,
        verbindung.connection_type,
        verbindung.notes,
      ]
        .join(' ')
        .toLowerCase(),
    }
  })
})

const gefiltert = computed(() => {
  const suche = filter.value.trim().toLowerCase()

  return zeilen.value
    .filter((zeile) => statusFilter.value === 'all' || (zeile.verbindung.status || 'active') === statusFilter.value)
    .filter((zeile) => !suche || zeile.suchtext.includes(suche))
    .sort(
      (a, b) =>
        String(a.quelle?.name || '').localeCompare(String(b.quelle?.name || ''), 'de') ||
        a.quellPort.localeCompare(b.quellPort, 'de', { numeric: true })
    )
})

const kennzahlen = computed(() => {
  let aktiv = 0
  let defekt = 0
  let inaktiv = 0

  for (const verbindung of props.connections) {
    const status = verbindung.status || 'active'

    if (status === 'active') aktiv += 1
    else if (status === 'faulty') defekt += 1
    else inaktiv += 1
  }

  return { gesamt: props.connections.length, aktiv, defekt, inaktiv }
})
</script>

<template>
  <section class="panel view-panel">
    <div class="view-stats">
      <div class="view-stat">
        <span>Verbindungen</span>
        <strong>{{ kennzahlen.gesamt }}</strong>
      </div>
      <div class="view-stat">
        <span>aktiv</span>
        <strong>{{ kennzahlen.aktiv }}</strong>
      </div>
      <div class="view-stat" :class="{ warn: kennzahlen.defekt }">
        <span>defekt</span>
        <strong>{{ kennzahlen.defekt }}</strong>
      </div>
      <div class="view-stat">
        <span>geplant / getrennt</span>
        <strong>{{ kennzahlen.inaktiv }}</strong>
      </div>
    </div>

    <div class="view-toolbar">
      <input
        v-model="filter"
        type="text"
        placeholder="Filtern nach Gerät oder Port …"
        aria-label="Verbindungen filtern"
        spellcheck="false"
      />

      <select v-model="statusFilter" aria-label="Nach Status filtern">
        <option value="all">Alle Status</option>
        <option v-for="(info, wert) in STATUS" :key="wert" :value="wert">{{ info.label }}</option>
      </select>

      <span class="view-toolbar-spacer"></span>

      <button type="button" class="view-button" @click="emit('add')">+ Verbindung</button>
    </div>

    <div v-if="gefiltert.length" class="view-table-wrap">
      <table class="view-table">
        <thead>
          <tr>
            <th>Quelle</th>
            <th aria-label="Richtung"></th>
            <th>Ziel</th>
            <th>Typ</th>
            <th>Status</th>
            <th aria-label="Aktionen"></th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="zeile in gefiltert"
            :key="zeile.verbindung.id"
            :class="`tone-${zeile.status.tone}`"
          >
            <td>
              <div class="endpoint">
                <button
                  v-if="zeile.quelle"
                  type="button"
                  class="view-link"
                  @click="emit('select-device', zeile.quelle)"
                >
                  <span class="view-icon">{{ getDeviceIcon(zeile.quelle.device_type) }}</span>
                  {{ zeile.quelle.name }}
                </button>
                <span v-else>Gerät #{{ zeile.verbindung.source_device_id }}</span>
                <small>{{ zeile.quellPort }}</small>
              </div>
            </td>

            <td class="arrow">⇄</td>

            <td>
              <div class="endpoint">
                <button
                  v-if="zeile.ziel"
                  type="button"
                  class="view-link"
                  @click="emit('select-device', zeile.ziel)"
                >
                  <span class="view-icon">{{ getDeviceIcon(zeile.ziel.device_type) }}</span>
                  {{ zeile.ziel.name }}
                </button>
                <span v-else>Gerät #{{ zeile.verbindung.target_device_id }}</span>
                <small>{{ zeile.zielPort }}</small>
              </div>
            </td>

            <td>{{ typLabel(zeile.verbindung.connection_type) }}</td>
            <td><span class="status-badge" :class="`tone-${zeile.status.tone}`">{{ zeile.status.label }}</span></td>

            <td class="actions">
              <button type="button" class="row-button" @click="emit('edit', zeile.verbindung)">Bearbeiten</button>
              <button type="button" class="row-button danger" @click="emit('delete', zeile.verbindung)">Löschen</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="view-empty">
      <template v-if="connections.length">Keine Verbindung passt zu diesem Filter.</template>
      <template v-else>
        Noch keine Verbindungen dokumentiert.
        <button type="button" class="view-button" @click="emit('add')">Erste Verbindung anlegen</button>
      </template>
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
  width: min(100%, 320px);
}

.view-toolbar input:focus,
.view-toolbar select:focus {
  border-color: var(--f-blau-86-2);
  outline: none;
  box-shadow: 0 0 0 3px var(--f-blau-60);
}

.view-toolbar-spacer {
  flex: 1;
}

.view-button {
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
  padding: 9px 12px;

  border-bottom: 1px solid var(--f-ton-96-5);

  color: var(--t-ton-23);
  vertical-align: middle;
}

.view-table tbody tr:last-child td {
  border-bottom: 0;
}

.view-table tbody tr:hover td {
  background: var(--f-grau-99-3);
}

.view-table tr.tone-bad td {
  background: var(--f-ton-97-3);
}

.view-table tr.tone-neutral td {
  color: var(--t-ton-54);
}

.endpoint {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.endpoint small {
  color: var(--t-ton-61-2);
  font-size: 11px;
}

.arrow {
  color: var(--t-ton-66);
  text-align: center;
}

.status-badge {
  display: inline-block;
  padding: 3px 9px;

  border-radius: 999px;

  font-size: 11px;
  font-weight: 750;
  white-space: nowrap;
}

.status-badge.tone-ok {
  color: var(--t-gruen-30);
  background: var(--f-ton-92-2);
}

.status-badge.tone-warn {
  color: var(--t-amber-33);
  background: var(--f-amber-89);
}

.status-badge.tone-bad {
  color: var(--t-rot-42);
  background: var(--f-ton-94);
}

.status-badge.tone-neutral {
  color: var(--t-ton-47-3);
  background: var(--f-ton-95-2);
}

.actions {
  text-align: right;
  white-space: nowrap;
}

.row-button {
  margin-left: 4px;
  padding: 5px 9px;

  border: 1px solid var(--f-ton-91);
  border-radius: 7px;

  color: var(--t-ton-40);
  background: var(--f-grau-100);

  font: inherit;
  font-size: 11px;
  font-weight: 700;

  cursor: pointer;
}

.row-button:hover {
  border-color: var(--f-blau-86-2);
  color: var(--t-blau-53);
}

.row-button.danger:hover {
  border-color: var(--f-rot-89);
  color: var(--t-rot-42);
  background: var(--f-ton-97-3);
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
  text-align: left;

  cursor: pointer;
}

.view-link:hover {
  text-decoration: underline;
}

.view-icon {
  color: var(--t-ton-54);
}

.view-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;

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

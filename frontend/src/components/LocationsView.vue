<script setup>
import { computed } from 'vue'
import { getDeviceIcon, getDeviceTypeLabel, getStatusLabel } from '../lib/deviceMeta.js'

const props = defineProps({
  locations: { type: Array, default: () => [] },
  // Alle Geraete des Bereichs; hier zaehlen nur die ohne Rack
  devices: { type: Array, default: () => [] },
})

const emit = defineEmits(['select-device', 'add-location', 'edit-location', 'add-device'])

const karten = computed(() =>
  props.locations.map((ort) => ({
    ort,
    devices: props.devices
      .filter((device) => Number(device.location_id) === Number(ort.id))
      .sort((a, b) => String(a.name).localeCompare(String(b.name), 'de', { numeric: true })),
  }))
)

const gesamtGeraete = computed(() =>
  props.devices.filter((device) => device.location_id).length
)

/** Kurzzeile unter dem Geraetenamen */
function untertitel(device) {
  return [device.manufacturer, device.model].filter(Boolean).join(' ')
}
</script>

<template>
  <section class="panel view-panel">
    <div class="view-stats">
      <div class="view-stat">
        <span>Standorte</span>
        <strong>{{ locations.length }}</strong>
      </div>
      <div class="view-stat">
        <span>Geräte dort</span>
        <strong>{{ gesamtGeraete }}</strong>
      </div>
    </div>

    <div class="view-toolbar">
      <p class="ort-erklaerung">
        Alles, was nicht im Rack hängt: Access Points, Modems, Kameras,
        Switches hinter dem Fernseher. Ports, IP-Adressen und Verbindungen
        führen diese Geräte genau wie die im Rack.
      </p>

      <button type="button" class="view-add" @click="emit('add-location')">
        + Standort
      </button>
    </div>

    <div v-if="karten.length" class="ort-liste">
      <article v-for="eintrag in karten" :key="eintrag.ort.id" class="ort-karte">
        <header class="ort-kopf">
          <div class="ort-titel">
            <h3>{{ eintrag.ort.name }}</h3>
            <p v-if="eintrag.ort.description">{{ eintrag.ort.description }}</p>
            <p v-else class="ort-zahl">
              {{ eintrag.devices.length }}
              {{ eintrag.devices.length === 1 ? 'Gerät' : 'Geräte' }}
            </p>
          </div>

          <div class="ort-aktionen">
            <button type="button" class="secondary-button" @click="emit('add-device', eintrag.ort)">
              + Gerät
            </button>
            <button
              type="button"
              class="secondary-button"
              :title="`„${eintrag.ort.name}“ bearbeiten`"
              @click="emit('edit-location', eintrag.ort)"
            >
              ✎
            </button>
          </div>
        </header>

        <ul v-if="eintrag.devices.length" class="ort-geraete">
          <li v-for="device in eintrag.devices" :key="device.id">
            <button type="button" class="ort-geraet" @click="emit('select-device', device)">
              <img
                v-if="device.image_url"
                class="ort-vorschau"
                :src="device.image_url"
                :alt="`Foto von ${device.name}`"
                loading="lazy"
                decoding="async"
              />
              <span v-else class="ort-icon">{{ getDeviceIcon(device.device_type) }}</span>

              <span class="ort-name">
                <strong>{{ device.name }}</strong>
                <small>{{ untertitel(device) || getDeviceTypeLabel(device.device_type) }}</small>
              </span>

              <span class="ort-ip">{{ device.ip_address || '' }}</span>

              <span class="status-badge" :class="`status-${device.status || 'active'}`">
                {{ getStatusLabel(device.status) }}
              </span>
            </button>
          </li>
        </ul>

        <p v-else class="ort-leer">
          Hier ist noch nichts eingetragen.
        </p>
      </article>
    </div>

    <p v-else class="view-empty">
      Noch kein Standort angelegt. Ein Standort ist das Gegenstück zum Rack –
      für alles, was keine Höheneinheiten hat.
    </p>
  </section>
</template>

<style scoped>
.view-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
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
  color: var(--t-ton-61-2);
  font-size: 11px;
  font-weight: 700;
}

.view-stat strong {
  color: var(--t-ton-15);
  font-size: 20px;
  font-weight: 800;
}

.view-toolbar {
  display: flex;
  align-items: flex-start;
  gap: 14px;

  margin-top: 16px;
}

.ort-erklaerung {
  flex: 1;
  margin: 0;

  color: var(--t-ton-61-2);
  font-size: 12px;
  line-height: 1.5;
}

.view-add {
  flex: 0 0 auto;
  padding: 9px 14px;

  border: 0;
  border-radius: 10px;

  color: var(--t-grau-100);
  background: var(--rv-blue);

  font-size: 12px;
  font-weight: 750;
  cursor: pointer;
}

.ort-liste {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 14px;

  margin-top: 18px;
}

.ort-karte {
  padding: 16px;

  border: 1px solid var(--f-ton-95);
  border-radius: 14px;

  background: var(--f-grau-99-2);
}

.ort-kopf {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.ort-titel h3 {
  margin: 0;

  color: var(--t-ton-15);
  font-size: 15px;
  font-weight: 800;
}

.ort-titel p {
  margin: 4px 0 0;

  color: var(--t-ton-61-2);
  font-size: 12px;
}

.ort-aktionen {
  display: flex;
  flex: 0 0 auto;
  gap: 6px;
}

.ort-geraete {
  display: flex;
  flex-direction: column;
  gap: 6px;

  margin: 14px 0 0;
  padding: 0;

  list-style: none;
}

.ort-geraet {
  display: flex;
  align-items: center;
  gap: 10px;

  width: 100%;
  padding: 8px 10px;

  border: 1px solid var(--f-ton-95);
  border-radius: 10px;

  background: var(--f-grau-100);

  font: inherit;
  text-align: left;
  cursor: pointer;
}

.ort-geraet:hover {
  border-color: var(--f-blau-60-2);
}

.ort-vorschau {
  width: 40px;
  height: 22px;
  flex: 0 0 auto;

  border-radius: 4px;
  object-fit: cover;
}

.ort-icon {
  width: 40px;
  flex: 0 0 auto;

  color: var(--t-ton-54);
  font-size: 15px;
  text-align: center;
}

.ort-name {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.ort-name strong {
  overflow: hidden;

  color: var(--t-ton-15);
  font-size: 13px;
  font-weight: 750;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.ort-name small {
  color: var(--t-ton-61-2);
  font-size: 11px;
}

.ort-ip {
  color: var(--t-ton-54);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
}

.ort-leer,
.view-empty {
  margin: 14px 0 0;

  color: var(--t-ton-61-2);
  font-size: 12px;
}

.view-empty {
  margin-top: 24px;
}
</style>

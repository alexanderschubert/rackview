<script setup>
import { computed } from 'vue'
import { getDeviceIcon } from '../lib/deviceMeta.js'

const props = defineProps({
  devices: { type: Array, default: () => [] },
  racks: { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
})

const emit = defineEmits(['select-device'])

// Das VLAN-Feld ist Freitext. Erkannt werden "10", "VLAN 10",
// "10 (Management)", "10 - Management", "10: Management" sowie mehrere
// Eintraege, getrennt durch Komma oder Semikolon.
function parseVlans(wert) {
  return String(wert || '')
    .split(/[,;]/)
    .map((teil) => teil.trim())
    .filter(Boolean)
    .map((teil) => {
      const treffer = teil.match(/^(?:vlan\s*)?(\d{1,4})\s*(?:[-–:]\s*|\(\s*)?([^)]*?)\)?\s*$/i)

      return treffer
        ? { id: Number(treffer[1]), name: treffer[2].trim() }
        : { id: null, name: teil }
    })
}

function netzVon(ip) {
  const teile = String(ip || '').trim().split('.')

  if (teile.length !== 4 || teile.some((teil) => !/^\d{1,3}$/.test(teil) || Number(teil) > 255)) {
    return null
  }

  return `${teile.slice(0, 3).join('.')}.0/24`
}

// Rack oder Standort - je nachdem, wo das Geraet steht
function rackName(device) {
  if (!device.rack_id) {
    return props.locations.find((ort) => Number(ort.id) === Number(device.location_id))?.name || ''
  }

  return props.racks.find((item) => Number(item.id) === Number(device.rack_id))?.name || ''
}

const gruppen = computed(() => {
  const map = new Map()
  const ohne = []

  for (const device of props.devices) {
    const vlans = parseVlans(device.vlan)

    if (!vlans.length) {
      ohne.push(device)
      continue
    }

    for (const vlan of vlans) {
      const key = vlan.id !== null ? `id-${vlan.id}` : `name-${vlan.name.toLowerCase()}`

      if (!map.has(key)) {
        map.set(key, { key, id: vlan.id, name: '', geraete: [] })
      }

      const gruppe = map.get(key)

      // Der erste gepflegte Name gewinnt - "10" und "10 (Management)"
      // landen im selben VLAN, angezeigt wird "Management".
      if (!gruppe.name && vlan.name) gruppe.name = vlan.name
      if (!gruppe.geraete.includes(device)) gruppe.geraete.push(device)
    }
  }

  const liste = [...map.values()].map((gruppe) => {
    // Das haeufigste /24 unter den Geraeten des VLANs
    const netze = new Map()

    for (const device of gruppe.geraete) {
      const netz = netzVon(device.ip_address)
      if (netz) netze.set(netz, (netze.get(netz) || 0) + 1)
    }

    const netz = [...netze].sort((a, b) => b[1] - a[1])[0]?.[0] || null

    return {
      ...gruppe,
      netz,
      geraete: [...gruppe.geraete].sort((a, b) => String(a.name).localeCompare(String(b.name), 'de')),
    }
  })

  liste.sort((a, b) => {
    if (a.id === null && b.id === null) return a.name.localeCompare(b.name, 'de')
    if (a.id === null) return 1
    if (b.id === null) return -1
    return a.id - b.id
  })

  return { liste, ohne }
})
</script>

<template>
  <section class="panel view-panel">
    <div class="vlan-grid">
      <article v-for="gruppe in gruppen.liste" :key="gruppe.key" class="vlan-card">
        <header>
          <div>
            <strong>{{ gruppe.id !== null ? `VLAN ${gruppe.id}` : gruppe.name }}</strong>
            <span v-if="gruppe.id !== null && gruppe.name">{{ gruppe.name }}</span>
          </div>
          <span class="vlan-count">{{ gruppe.geraete.length }}</span>
        </header>

        <p v-if="gruppe.netz" class="vlan-net">{{ gruppe.netz }}</p>

        <ul>
          <li v-for="device in gruppe.geraete" :key="device.id">
            <button type="button" class="view-link" @click="emit('select-device', device)">
              <span class="view-icon">{{ getDeviceIcon(device.device_type) }}</span>
              {{ device.name }}
            </button>
            <span class="vlan-meta">
              <span v-if="device.ip_address" class="mono">{{ device.ip_address }}</span>
              <span v-if="rackName(device)">{{ rackName(device) }}</span>
            </span>
          </li>
        </ul>
      </article>

      <article v-if="gruppen.ohne.length" class="vlan-card vlan-card-none">
        <header>
          <div>
            <strong>Ohne VLAN</strong>
            <span>kein VLAN hinterlegt</span>
          </div>
          <span class="vlan-count">{{ gruppen.ohne.length }}</span>
        </header>

        <ul>
          <li v-for="device in gruppen.ohne" :key="device.id">
            <button type="button" class="view-link" @click="emit('select-device', device)">
              <span class="view-icon">{{ getDeviceIcon(device.device_type) }}</span>
              {{ device.name }}
            </button>
            <span class="vlan-meta">
              <span v-if="rackName(device)">{{ rackName(device) }}</span>
            </span>
          </li>
        </ul>
      </article>
    </div>

    <p v-if="!gruppen.liste.length && !gruppen.ohne.length" class="view-empty">
      Noch keine Geräte erfasst.
    </p>

    <p class="view-note">
      Grundlage ist das VLAN-Feld der Geräte. VLANs einzelner Ports sind hier
      noch nicht berücksichtigt.
    </p>
  </section>
</template>

<style scoped>
.view-panel {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 22px 24px 26px;
}

.vlan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
}

.vlan-card {
  display: flex;
  flex-direction: column;
  gap: 10px;

  padding: 14px 16px;

  border: 1px solid var(--f-ton-92-4);
  border-radius: 12px;
  background: var(--f-ton-99);
}

.vlan-card-none {
  border-color: var(--f-ton-92-5);
  border-style: dashed;
  background: var(--f-grau-99-2);
}

.vlan-card header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.vlan-card header div {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.vlan-card header strong {
  color: var(--t-blau-48-2);
  font-size: 15px;
  font-weight: 800;
}

.vlan-card-none header strong {
  color: var(--t-ton-40);
}

.vlan-card header div span {
  color: var(--t-ton-40);
  font-size: 12px;
  font-weight: 600;
}

.vlan-count {
  display: grid;
  place-items: center;

  min-width: 26px;
  height: 26px;
  padding: 0 6px;

  border-radius: 8px;
  background: var(--f-grau-100);

  color: var(--t-ton-23);
  font-size: 12px;
  font-weight: 800;
}

.vlan-net {
  align-self: flex-start;
  margin: 0;
  padding: 3px 8px;

  border-radius: 6px;
  background: var(--f-ton-94-2);

  color: var(--t-blau-40-2);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
}

.vlan-card ul {
  display: flex;
  flex-direction: column;
  gap: 6px;

  margin: 0;
  padding: 0;

  list-style: none;
}

.vlan-card li {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;

  font-size: 12px;
}

.vlan-meta {
  display: flex;
  gap: 8px;

  color: var(--t-ton-61-2);
  font-size: 11px;
  white-space: nowrap;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.view-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-width: 0;

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
  margin: 0;
  padding: 28px 12px;

  border: 1px dashed var(--f-ton-91-3);
  border-radius: 12px;

  color: var(--t-ton-61-2);
  font-size: 13px;
  text-align: center;
}

.view-note {
  margin: 0;
  color: var(--t-ton-61-2);
  font-size: 11px;
}
</style>

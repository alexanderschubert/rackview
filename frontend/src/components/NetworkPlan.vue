<script setup>
import { computed } from 'vue'
import { getDeviceIcon, getDeviceTypeLabel } from '../lib/deviceMeta.js'

const props = defineProps({
  // Alle Geraete aus allen Racks - Verbindungen gehen ueber Rackgrenzen
  devices: { type: Array, default: () => [] },
  connections: { type: Array, default: () => [] },
  racks: { type: Array, default: () => [] },
  // Standorte ausserhalb der Racks - fuer die Beschriftung im Plan
  locations: { type: Array, default: () => [] },
  selectedDeviceId: { type: Number, default: null },
})

const emit = defineEmits(['select-device', 'add-connection'])

// Masse im viewBox-Raster
const NODE_W = 138
const NODE_H = 58
const TIER_GAP = 116
const COL_GAP = 168
const PAD = 28

const ROOT_TYPES = ['router', 'firewall']

// --- Geschwindigkeit ---------------------------------------------

// "10G", "2.5G", "1000M", "100 M" -> Mbit/s
function speedMbit(value) {
  const treffer = String(value || '').replace(',', '.').match(/([\d.]+)\s*([gmk]?)/i)

  if (!treffer) return 0

  const zahl = parseFloat(treffer[1])
  const einheit = treffer[2].toLowerCase()

  if (einheit === 'g') return zahl * 1000
  if (einheit === 'k') return zahl / 1000
  return zahl
}

function speedClass(mbit) {
  if (mbit >= 10000) return 'speed-10g'
  if (mbit > 1000) return 'speed-multi'
  if (mbit >= 1000) return 'speed-1g'
  if (mbit > 0) return 'speed-slow'
  return 'speed-unknown'
}

const SPEED_LEGEND = [
  { klasse: 'speed-10g', label: '10 Gbit/s und mehr' },
  { klasse: 'speed-multi', label: '2,5 / 5 Gbit/s' },
  { klasse: 'speed-1g', label: '1 Gbit/s' },
  { klasse: 'speed-slow', label: 'unter 1 Gbit/s' },
  { klasse: 'speed-unknown', label: 'ohne Angabe' },
]

// --- Kanten: eine Linie je Geraetepaar ---------------------------

const kanten = computed(() => {
  const paare = new Map()

  for (const verbindung of props.connections) {
    const a = Number(verbindung.source_device_id)
    const b = Number(verbindung.target_device_id)

    if (!a || !b || a === b) continue

    const key = a < b ? `${a}-${b}` : `${b}-${a}`
    const mbit = Math.max(
      speedMbit(verbindung.source_port?.speed),
      speedMbit(verbindung.target_port?.speed)
    )

    const kante = paare.get(key) || { key, a: Math.min(a, b), b: Math.max(a, b), anzahl: 0, aktiv: false, mbit: 0 }

    kante.anzahl += 1
    kante.mbit = Math.max(kante.mbit, mbit)

    if ((verbindung.status || 'active') === 'active') kante.aktiv = true

    paare.set(key, kante)
  }

  return [...paare.values()]
})

// Geraete nachschlagen; Rueckfall auf die mitgelieferten Objekte der
// Verbindung, falls ein Geraet (noch) nicht in der Liste ist.
const geraete = computed(() => {
  const map = new Map(props.devices.map((device) => [Number(device.id), device]))

  for (const verbindung of props.connections) {
    for (const device of [verbindung.source_device, verbindung.target_device]) {
      if (device && !map.has(Number(device.id))) map.set(Number(device.id), device)
    }
  }

  return map
})

const nachbarn = computed(() => {
  const map = new Map()

  for (const { a, b } of kanten.value) {
    if (!map.has(a)) map.set(a, new Set())
    if (!map.has(b)) map.set(b, new Set())
    map.get(a).add(b)
    map.get(b).add(a)
  }

  return map
})

// --- Ebenen: Abstand von Router/Firewall -------------------------

function wurzelRang(id) {
  const grad = nachbarn.value.get(id)?.size || 0
  const typ = geraete.value.get(id)?.device_type

  return (ROOT_TYPES.includes(typ) ? 1000 : 0) + grad
}

const ebenen = computed(() => {
  const ids = [...nachbarn.value.keys()].sort((x, y) => wurzelRang(y) - wurzelRang(x))
  const tiefe = new Map()

  // Jede zusammenhaengende Gruppe wird von ihrem ranghoechsten Geraet
  // aus per Breitensuche durchlaufen - bevorzugt Router/Firewall.
  for (const start of ids) {
    if (tiefe.has(start)) continue

    tiefe.set(start, 0)
    const warteschlange = [start]

    while (warteschlange.length) {
      const id = warteschlange.shift()

      for (const nachbar of nachbarn.value.get(id) || []) {
        if (!tiefe.has(nachbar)) {
          tiefe.set(nachbar, tiefe.get(id) + 1)
          warteschlange.push(nachbar)
        }
      }
    }
  }

  const liste = []

  for (const id of ids) {
    const t = tiefe.get(id)
    if (!liste[t]) liste[t] = []
    liste[t].push(id)
  }

  // Innerhalb einer Ebene nach der Lage der Nachbarn in der Ebene
  // darueber sortieren - das vermeidet die meisten Kreuzungen.
  for (let t = 1; t < liste.length; t += 1) {
    const oben = new Map(liste[t - 1].map((id, index) => [id, index]))

    const schwerpunkt = (id) => {
      const positionen = [...(nachbarn.value.get(id) || [])]
        .filter((nachbar) => oben.has(nachbar))
        .map((nachbar) => oben.get(nachbar))

      return positionen.length
        ? positionen.reduce((summe, wert) => summe + wert, 0) / positionen.length
        : Number.MAX_SAFE_INTEGER
    }

    liste[t].sort((x, y) => schwerpunkt(x) - schwerpunkt(y))
  }

  return liste
})

// --- Positionen ---------------------------------------------------

const breite = computed(() => {
  const maxProEbene = Math.max(1, ...ebenen.value.map((ebene) => ebene.length))
  return Math.max(560, maxProEbene * COL_GAP + PAD * 2)
})

const hoehe = computed(() => {
  return PAD * 2 + Math.max(0, ebenen.value.length - 1) * TIER_GAP + NODE_H
})

const knoten = computed(() => {
  const liste = []
  const nutzbar = breite.value - PAD * 2

  ebenen.value.forEach((ebene, t) => {
    ebene.forEach((id, i) => {
      const device = geraete.value.get(id)
      if (!device) return

      liste.push({
        id,
        device,
        x: PAD + (nutzbar * (i + 0.5)) / ebene.length,
        y: PAD + t * TIER_GAP,
        ebene: t,
      })
    })
  })

  return liste
})

const knotenById = computed(() => new Map(knoten.value.map((k) => [k.id, k])))

const linien = computed(() => {
  return kanten.value
    .map((kante) => {
      const p = knotenById.value.get(kante.a)
      const q = knotenById.value.get(kante.b)

      if (!p || !q) return null

      let pfad

      if (p.ebene === q.ebene) {
        // Querverbindung innerhalb einer Ebene: seitlich von Kasten zu Kasten
        const [links, rechts] = p.x < q.x ? [p, q] : [q, p]
        const y = links.y + NODE_H / 2
        pfad = `M ${links.x + NODE_W / 2} ${y} L ${rechts.x - NODE_W / 2} ${y}`
      } else {
        const [oben, unten] = p.ebene < q.ebene ? [p, q] : [q, p]
        const y1 = oben.y + NODE_H
        const y2 = unten.y
        const mitte = (y1 + y2) / 2
        pfad = `M ${oben.x} ${y1} C ${oben.x} ${mitte}, ${unten.x} ${mitte}, ${unten.x} ${y2}`
      }

      return {
        key: kante.key,
        pfad,
        klasse: speedClass(kante.mbit),
        aktiv: kante.aktiv,
        anzahl: kante.anzahl,
        labelX: (p.x + q.x) / 2,
        labelY: (p.y + q.y) / 2 + NODE_H / 2,
      }
    })
    .filter(Boolean)
})

const vorhandeneGeschwindigkeiten = computed(() => {
  const klassen = new Set(linien.value.map((linie) => linie.klasse))
  return SPEED_LEGEND.filter((eintrag) => klassen.has(eintrag.klasse))
})

const hatInaktive = computed(() => linien.value.some((linie) => !linie.aktiv))

// --- Beschriftung -------------------------------------------------

function kurz(text, laenge) {
  const wert = String(text || '')
  return wert.length > laenge ? `${wert.slice(0, laenge - 1)}…` : wert
}

function metaZeile(device) {
  const teile = [getDeviceTypeLabel(device.device_type)]
  if (device.vlan) teile.push(`VLAN ${device.vlan}`)
  return teile.join(' · ')
}

function tooltip(device) {
  const rack = props.racks.find((item) => Number(item.id) === Number(device.rack_id))
  const ort = props.locations.find((item) => Number(item.id) === Number(device.location_id))
  const teile = [device.name, getDeviceTypeLabel(device.device_type)]

  if (rack) teile.push(`Rack ${rack.name}`)
  else if (ort) teile.push(ort.name)
  if (device.ip_address) teile.push(device.ip_address)

  return teile.join(' · ')
}
</script>

<template>
  <div class="network-plan">
    <div v-if="knoten.length" class="plan-scroll">
      <svg
        class="plan-svg"
        :viewBox="`0 0 ${breite} ${hoehe}`"
        :style="{ minWidth: `${Math.round(breite * 0.72)}px` }"
        role="group"
        aria-label="Netzwerkplan"
      >
        <!-- Verbindungen zuerst, damit sie unter den Kaesten liegen -->
        <g class="plan-edges">
          <g v-for="linie in linien" :key="linie.key">
            <path
              class="plan-edge"
              :class="[linie.klasse, { inaktiv: !linie.aktiv }]"
              :d="linie.pfad"
            />

            <text
              v-if="linie.anzahl > 1"
              class="plan-edge-count"
              :x="linie.labelX"
              :y="linie.labelY"
              text-anchor="middle"
              dominant-baseline="central"
            >
              ×{{ linie.anzahl }}
            </text>
          </g>
        </g>

        <g
          v-for="k in knoten"
          :key="k.id"
          class="plan-node"
          :class="{ selected: k.id === selectedDeviceId }"
          :transform="`translate(${k.x - NODE_W / 2}, ${k.y})`"
          role="button"
          tabindex="0"
          :aria-label="tooltip(k.device)"
          @click="emit('select-device', k.device)"
          @keydown.enter.prevent="emit('select-device', k.device)"
          @keydown.space.prevent="emit('select-device', k.device)"
        >
          <title>{{ tooltip(k.device) }}</title>

          <rect class="plan-node-box" :width="NODE_W" :height="NODE_H" rx="12" />

          <text class="plan-node-icon" x="18" :y="NODE_H / 2" text-anchor="middle" dominant-baseline="central">
            {{ getDeviceIcon(k.device.device_type) }}
          </text>

          <text class="plan-node-name" x="36" y="24">{{ kurz(k.device.name, 15) }}</text>
          <text class="plan-node-meta" x="36" y="41">{{ kurz(metaZeile(k.device), 20) }}</text>
        </g>
      </svg>
    </div>

    <div v-if="knoten.length" class="plan-legend">
      <span v-for="eintrag in vorhandeneGeschwindigkeiten" :key="eintrag.klasse">
        <i class="plan-legend-line" :class="eintrag.klasse"></i>{{ eintrag.label }}
      </span>

      <span v-if="hatInaktive">
        <i class="plan-legend-line inaktiv"></i>geplant / getrennt
      </span>
    </div>

    <div v-else class="plan-empty">
      <div class="plan-empty-icon">⇄</div>
      <strong>Noch keine Verbindungen dokumentiert</strong>
      <p>
        Der Plan entsteht aus den Port-Verbindungen zwischen deinen Geräten.
        Sobald die erste angelegt ist, erscheint sie hier.
      </p>
      <button class="plan-empty-button" type="button" @click="emit('add-connection')">
        Verbindung anlegen
      </button>
    </div>
  </div>
</template>

<style scoped>
.network-plan {
  padding: 0 24px 8px;
}

.plan-scroll {
  overflow-x: auto;
  padding-bottom: 4px;
}

.plan-svg {
  display: block;
  width: 100%;
  height: auto;
}

/* --- Kanten --- */

.plan-edge {
  fill: none;
  stroke-width: 2.5;
  stroke-linecap: round;
}

.plan-edge.speed-10g { stroke: var(--f-blau-58); stroke-width: 3.5; }
.plan-edge.speed-multi { stroke: var(--f-cyan-36); stroke-width: 3; }
.plan-edge.speed-1g { stroke: var(--f-blau-53); }
.plan-edge.speed-slow { stroke: var(--f-ton-47); }
.plan-edge.speed-unknown { stroke: var(--f-ton-65-2); }

.plan-edge.inaktiv {
  stroke-dasharray: 6 6;
  opacity: 0.7;
}

.plan-edge-count {
  fill: var(--f-ton-35);
  font-size: 11px;
  font-weight: 800;
  paint-order: stroke;
  stroke: var(--f-grau-100);
  stroke-width: 4px;
}

/* --- Knoten --- */

.plan-node {
  cursor: pointer;
  outline: none;
}

.plan-node-box {
  fill: var(--f-grau-100);
  stroke: var(--f-ton-89);
  stroke-width: 1.5;
  filter: drop-shadow(0 2px 4px var(--f-schatten-11-2));
  transition: stroke 0.15s ease;
}

.plan-node:hover .plan-node-box,
.plan-node:focus-visible .plan-node-box {
  stroke: var(--f-blau-77);
}

.plan-node.selected .plan-node-box {
  stroke: var(--f-blau-53);
  stroke-width: 2.5;
  fill: var(--f-ton-98);
}

.plan-node-icon {
  font-size: 16px;
  fill: var(--f-ton-40);
}

.plan-node-name {
  fill: var(--f-ton-11);
  font-size: 13px;
  font-weight: 750;
}

.plan-node-meta {
  fill: var(--f-ton-54);
  font-size: 10.5px;
}

/* --- Legende --- */

.plan-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;

  padding-top: 12px;

  color: var(--t-ton-47-3);
  font-size: 11px;
}

.plan-legend span {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}

.plan-legend-line {
  display: inline-block;
  width: 22px;
  height: 0;
  border-top: 3px solid var(--f-ton-65-2);
}

.plan-legend-line.speed-10g { border-color: var(--f-blau-58); }
.plan-legend-line.speed-multi { border-color: var(--f-cyan-36); }
.plan-legend-line.speed-1g { border-color: var(--f-blau-53); }
.plan-legend-line.speed-slow { border-color: var(--f-ton-47); }
.plan-legend-line.inaktiv { border-top-style: dashed; }

/* --- Leerzustand --- */

.plan-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;

  padding: 24px 12px;

  text-align: center;
}

.plan-empty-icon {
  display: grid;
  place-items: center;

  width: 44px;
  height: 44px;

  border-radius: 12px;
  background: var(--f-ton-96);

  color: var(--t-blau-53);
  font-size: 18px;
}

.plan-empty strong {
  color: var(--t-ton-11);
  font-size: 14px;
}

.plan-empty p {
  max-width: 320px;
  margin: 0;

  color: var(--t-ton-54);
  font-size: 12px;
  line-height: 1.5;
}

.plan-empty-button {
  margin-top: 6px;
  padding: 9px 12px;

  border: 1px solid var(--f-ton-91-2);
  border-radius: 10px;

  color: var(--t-ton-40);
  background: var(--f-grau-100);

  font: inherit;
  font-size: 12px;
  font-weight: 750;

  cursor: pointer;
}

.plan-empty-button:hover {
  border-color: var(--f-blau-86-2);
  color: var(--t-blau-53);
  background: var(--f-ton-98);
}
</style>

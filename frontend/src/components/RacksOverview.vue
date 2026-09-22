<script setup>
import { computed } from 'vue'

const props = defineProps({
  racks: { type: Array, default: () => [] },
  selectedRackId: { type: Number, default: null },
  // Drag & Drop aus der Rackansicht: Karte unter dem Zeiger hervorheben
  dropRackId: { type: Number, default: null },
  dropOk: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'add'])

function hoehe(rack) {
  const wert = Number(rack.height_units)
  return Number.isFinite(wert) && wert > 0 ? wert : 18
}

const karten = computed(() => {
  return props.racks.map((rack) => {
    const geraete = rack.devices || []
    // Belegte Hoeheneinheiten zaehlen: Ein Geraet vorne und eines
    // hinten teilen sich dieselbe HE.
    const einheiten = new Set()

    for (const device of geraete) {
      const start = Number(device.start_unit) || 1

      for (let u = start; u < start + (Number(device.height_units) || 1); u += 1) einheiten.add(u)
    }

    const belegt = einheiten.size
    const gesamt = hoehe(rack)
    const nichtAktiv = geraete.filter((device) => (device.status || 'active') !== 'active').length

    return {
      rack,
      belegt,
      gesamt,
      frei: Math.max(gesamt - belegt, 0),
      anteil: Math.min(100, Math.round((belegt / gesamt) * 100)),
      anzahl: geraete.length,
      nichtAktiv,
    }
  })
})
</script>

<template>
  <section class="panel racks-overview">
    <button
      v-for="karte in karten"
      :key="karte.rack.id"
      type="button"
      class="rack-card"
      :class="{
        active: karte.rack.id === selectedRackId,
        'drop-target': karte.rack.id === dropRackId,
        'drop-invalid': karte.rack.id === dropRackId && !dropOk,
      }"
      :data-rack-drop="karte.rack.id"
      :aria-pressed="karte.rack.id === selectedRackId"
      @click="emit('select', karte.rack)"
    >
      <span class="rack-card-head">
        <strong>{{ karte.rack.name }}</strong>
        <small>{{ karte.gesamt }} HE</small>
      </span>

      <span class="rack-card-location">{{ karte.rack.location || 'Ohne Standort' }}</span>

      <span
        class="rack-card-bar"
        role="img"
        :aria-label="`${karte.belegt} von ${karte.gesamt} Höheneinheiten belegt`"
      >
        <span :style="{ width: `${karte.anteil}%` }" :class="{ full: karte.anteil >= 90 }"></span>
      </span>

      <span class="rack-card-meta">
        <span>{{ karte.belegt }} / {{ karte.gesamt }} HE belegt</span>
        <span>{{ karte.anzahl }} {{ karte.anzahl === 1 ? 'Gerät' : 'Geräte' }}</span>
      </span>

      <span v-if="karte.nichtAktiv" class="rack-card-warn">
        {{ karte.nichtAktiv }} nicht aktiv
      </span>
    </button>

    <button type="button" class="rack-card rack-card-add" @click="emit('add')">
      <span class="rack-card-plus">+</span>
      <span>Neues Rack</span>
    </button>
  </section>
</template>

<style scoped>
.racks-overview {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 12px;

  padding: 18px 20px;
}

.rack-card {
  display: flex;
  flex-direction: column;
  gap: 8px;

  padding: 14px 16px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 12px;

  color: var(--t-ton-23);
  background: var(--f-grau-100);

  font: inherit;
  text-align: left;

  cursor: pointer;

  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.rack-card:hover {
  border-color: var(--f-blau-85);
  box-shadow: 0 3px 10px var(--f-schatten-11-9);
}

.rack-card.active {
  border-color: var(--f-blau-53);
  box-shadow: 0 0 0 3px var(--f-blau-53-6);
}

.rack-card-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.rack-card-head strong {
  overflow: hidden;

  color: var(--t-ton-11);
  font-size: 14px;
  font-weight: 800;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.rack-card-head small {
  flex-shrink: 0;
  padding: 2px 7px;

  border-radius: 6px;
  background: var(--f-ton-96);

  color: var(--t-ton-37);
  font-size: 10.5px;
  font-weight: 750;
}

.rack-card-location {
  color: var(--t-ton-54);
  font-size: 12px;
}

.rack-card-bar {
  display: block;
  height: 7px;
  overflow: hidden;

  border-radius: 99px;
  background: var(--f-ton-95-2);
}

.rack-card-bar span {
  display: block;
  height: 100%;

  border-radius: 99px;
  background: linear-gradient(90deg, var(--f-blau-61), var(--f-blau-53));
}

.rack-card-bar span.full {
  background: linear-gradient(90deg, var(--f-amber-50), var(--f-amber-44));
}

.rack-card-meta {
  display: flex;
  justify-content: space-between;
  gap: 8px;

  color: var(--t-ton-47-3);
  font-size: 11px;
}

.rack-card-warn {
  align-self: flex-start;
  padding: 2px 8px;

  border-radius: 99px;
  background: var(--f-amber-89);

  color: var(--t-amber-33);
  font-size: 10.5px;
  font-weight: 750;
}

.rack-card-add {
  align-items: center;
  justify-content: center;

  min-height: 118px;

  border-style: dashed;

  color: var(--t-blau-53);
  background: var(--f-grau-99-3);

  font-size: 13px;
  font-weight: 750;
}

.rack-card-plus {
  font-size: 22px;
  line-height: 1;
}

/* Ein Geraet wird auf diese Karte gezogen */
.rack-card.drop-target {
  border-color: var(--f-gruen-58);
  background: var(--f-ton-97-4);
  box-shadow: 0 0 0 3px var(--f-gruen-58-2);
}

.rack-card.drop-invalid {
  border-color: var(--f-rot-71);
  background: var(--f-ton-97-3);
  box-shadow: 0 0 0 3px var(--f-rot-71-2);
}
</style>

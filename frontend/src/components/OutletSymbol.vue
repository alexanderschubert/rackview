<script setup>
import { computed } from 'vue'
import { schukoTeile } from '../lib/ports.js'

const props = defineProps({
  // Steckt etwas in diesem Platz?
  belegt: { type: Boolean, default: false },
})

/* Gleiches Zeichenraster wie PortSymbol, damit Buchsen und Dosen im
   Raster dieselbe Kachelhoehe haben. */
const W = 34
const H = 40
const CX = W / 2
const CY = 18
const R = 15

const teile = computed(() => schukoTeile(CX, CY, R))
const belegt = computed(() => props.belegt)

function masse(teil) {
  return { x: teil.x, y: teil.y, width: teil.w, height: teil.h }
}
</script>

<template>
  <svg class="dose" :viewBox="`0 0 ${W} ${H}`" aria-hidden="true" focusable="false">
    <!-- Rand der Dose -->
    <circle class="dose-koerper" :cx="CX" :cy="CY" :r="R" />

    <!-- Vertiefung - oder der steckende Stecker -->
    <circle
      class="dose-innen"
      :class="{ gesteckt: belegt }"
      :cx="CX"
      :cy="CY"
      :r="teile.innen"
    />

    <!-- Die beiden Loecher sieht man nur, solange nichts steckt -->
    <template v-if="!belegt">
      <circle
        v-for="(loch, i) in teile.loecher"
        :key="`l${i}`"
        class="dose-loch"
        :cx="loch.cx"
        :cy="loch.cy"
        :r="loch.r"
      />
    </template>

    <!-- Schutzkontaktbuegel oben und unten -->
    <rect
      v-for="(buegel, i) in teile.buegel"
      :key="`b${i}`"
      class="dose-buegel"
      :class="{ gesteckt: belegt }"
      v-bind="masse(buegel)"
      rx="0.6"
    />

    <!-- Steg des Steckers -->
    <rect v-if="belegt" class="dose-griff" v-bind="masse(teile.griff)" rx="1.2" />
  </svg>
</template>

<style scoped>
/* Wie bei PortSymbol setzt die aufrufende Ansicht die Farben; die
   Vorgaben hier gelten nur, falls sie fehlen. */
.dose {
  display: block;
  width: 100%;
  height: auto;
}

.dose-koerper {
  fill: var(--jack-fuellung, #f1f5f9);
  stroke: var(--jack-kante, #cbd5e1);
  stroke-width: 1.6;
}

/* Die Vertiefung ist grau, die beiden Loecher darin sind dunkel -
   nur so bleibt das Dosenmuster in heller wie dunkler Ansicht
   erkennbar. Steckt etwas drin, fuellt der dunkle Stecker die
   Vertiefung und verdeckt die Loecher. */
.dose-innen {
  fill: var(--jack-teil, #94a3b8);
}

.dose-innen.gesteckt {
  fill: var(--jack-oeffnung, #0f172a);
}

.dose-loch {
  fill: var(--jack-oeffnung, #0f172a);
}

/* Die Schutzkontaktbuegel liegen auf dem Rand der Dose - deshalb die
   Randfarbe und nicht die helle Flaeche, sonst verschwinden sie. */
.dose-buegel {
  fill: var(--jack-kante, #cbd5e1);
}

.dose-buegel.gesteckt {
  fill: var(--jack-teil, #94a3b8);
}

.dose-griff {
  fill: var(--jack-teil, #94a3b8);
  opacity: 0.85;
}
</style>

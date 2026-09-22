<script setup>
import { computed } from 'vue'
import { portForm, rj45Kontakte, rj45Oeffnung, sfpTeile, stromTeile, usbTeile } from '../lib/ports.js'

const props = defineProps({
  port: { type: Object, required: true },

  // 'connected' | 'free' | 'faulty' | 'disabled'. Steuert nur, ob ein
  // Stecker in der Buchse sitzt - die Farben kommen aus dem CSS der
  // aufrufenden Ansicht, damit helle und dunkle Ansicht stimmen.
  zustand: { type: String, default: 'free' },
})

/* Zeichenraster: 34 breit, 40 hoch. Die Buchse endet bei 35, darunter
   bleibt Platz fuer die PoE-Kennung. So stehen alle Buchsen im Raster
   auf derselben Hoehe - mit PoE wie ohne. */
const W = 34
const H = 40
const UNTEN = 35

const form = computed(() => portForm(props.port?.port_type))
const gesteckt = computed(() => props.zustand === 'connected')
const poe = computed(() => Boolean(props.port?.poe))

/** Das Rechteck des Gehaeuses - je Bauform ein anderes Format */
const kasten = computed(() => {
  const mitte = (1 + UNTEN) / 2

  if (form.value === 'sfp' || form.value === 'strom') {
    return { x: 1, y: mitte - 10.5, w: W - 2, h: 21 }
  }

  if (form.value === 'usb') {
    return { x: 7, y: mitte - 12.5, w: W - 14, h: 25 }
  }

  // RJ45 steht hochkant, wie die echte Buchse
  return { x: 2.5, y: 1, w: W - 5, h: UNTEN - 1 }
})

const teile = computed(() => {
  const k = kasten.value

  if (form.value === 'sfp') return sfpTeile(k.x, k.y, k.w, k.h)
  if (form.value === 'usb') return usbTeile(k.x, k.y, k.w, k.h)
  if (form.value === 'strom') return stromTeile(k.x, k.y, k.w, k.h)

  return {
    pfad: rj45Oeffnung(k.x, k.y, k.w, k.h, true),
    kontakte: rj45Kontakte(k.x, k.y, k.w, k.h, true),
    // Lichtkante auf dem steckenden Stecker
    steg: { x: k.x + k.w * 0.24, y: k.y + k.h * 0.58, w: k.w * 0.52, h: k.h * 0.08 },
  }
})

const poeBalken = computed(() => ({ x: W * 0.24, y: UNTEN + 1.6, w: W * 0.52, h: 2.6 }))

/** {x,y,w,h} in die Attribute eines <rect> uebersetzen */
function masse(teil) {
  return { x: teil.x, y: teil.y, width: teil.w, height: teil.h }
}
</script>

<template>
  <svg class="jack" :viewBox="`0 0 ${W} ${H}`" aria-hidden="true" focusable="false">
    <!-- Gehaeuse -->
    <rect
      class="jack-koerper"
      v-bind="masse(kasten)"
      :rx="form === 'rj45' ? 3 : 2.4"
    />

    <!-- RJ45: Oeffnung mit der Nut fuer die Rastnase -->
    <template v-if="form === 'rj45'">
      <path class="jack-oeffnung" :class="{ gesteckt }" :d="teile.pfad" />

      <template v-if="gesteckt">
        <rect class="jack-steg" v-bind="masse(teile.steg)" rx="0.6" />
      </template>

      <template v-else>
        <rect
          v-for="(kontakt, i) in teile.kontakte"
          :key="`k${i}`"
          class="jack-kontakt"
          v-bind="masse(kontakt)"
        />
      </template>
    </template>

    <!-- SFP: Kaefig mit waagerechtem Schlitz -->
    <template v-else-if="form === 'sfp'">
      <rect class="jack-oeffnung" :class="{ gesteckt }" v-bind="masse(teile.schlitz)" rx="1" />
      <rect class="jack-teil" v-bind="masse(teile.buegel)" rx="0.8" />
    </template>

    <!-- USB -->
    <template v-else-if="form === 'usb'">
      <rect class="jack-oeffnung" :class="{ gesteckt }" v-bind="masse(kasten)" rx="2" />
      <rect class="jack-teil" v-bind="masse(teile.zunge)" rx="0.8" />
    </template>

    <!-- Stromanschluss -->
    <template v-else>
      <rect
        v-for="(stift, i) in teile.stifte"
        :key="`s${i}`"
        class="jack-oeffnung"
        v-bind="masse(stift)"
        rx="0.6"
      />
    </template>

    <!-- PoE: der Balken unter der Buchse, wie in der Legende -->
    <rect v-if="poe" class="jack-poe" v-bind="masse(poeBalken)" rx="1.3" />
  </svg>
</template>

<style scoped>
/* Farben ueber Variablen: die aufrufende Ansicht setzt sie je nach
   Zustand des Ports. Die Vorgaben hier gelten nur, falls sie fehlen. */
.jack {
  display: block;
  width: 100%;
  height: auto;
}

.jack-koerper {
  fill: var(--jack-fuellung, #f1f5f9);
  stroke: var(--jack-kante, #cbd5e1);
  stroke-width: 1.6;
}

.jack-oeffnung {
  fill: var(--jack-oeffnung, #172033);
}

.jack-oeffnung.gesteckt {
  fill: var(--jack-stecker, #94a3b8);
}

/* Gold bleibt Gold - eine Buchse wechselt ihre Farbe nicht, nur weil
   die Oberflaeche dunkel wird. Deshalb hier ein fester Wert statt
   einer Variablen aus der Palette. */
.jack-kontakt {
  fill: var(--jack-kontakt, #b08430);
}

.jack-teil {
  fill: var(--jack-teil, #94a3b8);
}

.jack-steg {
  fill: var(--jack-fuellung, #f1f5f9);
  opacity: 0.45;
}

.jack-poe {
  fill: var(--jack-poe, #f59e0b);
}
</style>

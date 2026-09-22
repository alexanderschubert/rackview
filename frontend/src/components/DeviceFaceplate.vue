<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { portForm, rj45Kontakte, rj45Oeffnung, schukoTeile, sfpTeile, stromTeile, usbTeile } from '../lib/ports.js'

const props = defineProps({
  device: { type: Object, required: true },
  // 'front' = Frontblende, 'rear' = Rueckseite (Netzteile, Luefter, Dosen)
  side: { type: String, default: 'front' },
})

const rueckseite = computed(() => props.side === 'rear')

// Zeichenraster: 1000 Einheiten breit. Links und rechts sitzen die
// Rack-Ohren, dazwischen liegt die bespielbare Blende.
const W = 1000
const OHR = 118
const LINKS = 150
const RECHTS = W - 126

const units = computed(() => {
  const value = Number(props.device?.height_units ?? 1)

  return Number.isFinite(value) && value > 0 ? Math.min(Math.round(value), 60) : 1
})

/* --- Raster ohne Verzerrung ---------------------------------------
   Die Blende wird mit preserveAspectRatio="none" auf ihren Platz
   gezogen: im Rack ist eine Hoeheneinheit 30 px hoch, aber mehrere
   hundert Pixel breit. Mit fest gewaehlter viewBox-Hoehe wird daraus
   eine Streckung von zwei bis drei - ein Kreis wird zur Ellipse, eine
   RJ45-Buchse zum Strich. Deshalb wird die tatsaechliche Groesse
   gemessen und die viewBox-Hoehe daraus gebildet. Dann ist eine
   Einheit waagerecht genauso lang wie senkrecht. */

const svgRef = ref(null)
const gemessen = ref(0)
const breitePx = ref(0)
let beobachter = null

function messen() {
  const el = svgRef.value

  if (!el) return

  const { width, height } = el.getBoundingClientRect()

  if (!(width > 0) || !(height > 0)) return

  // Grenzen, damit extreme Seitenverhaeltnisse die Blende nicht zerlegen
  const roh = (height / width) * W

  gemessen.value = Math.min(Math.max(roh, units.value * 16), units.value * 150)
  breitePx.value = width
}

onMounted(() => {
  messen()

  if (typeof ResizeObserver === 'function' && svgRef.value) {
    beobachter = new ResizeObserver(messen)
    beobachter.observe(svgRef.value)
  } else if (typeof window !== 'undefined') {
    window.addEventListener('resize', messen)
  }
})

onBeforeUnmount(() => {
  beobachter?.disconnect()
  beobachter = null

  if (typeof window !== 'undefined') window.removeEventListener('resize', messen)
})

// Bis zur ersten Messung: ein 19-Zoll-Geraet ist rund 25-mal so breit
// wie eine Hoeheneinheit hoch.
const H = computed(() => gemessen.value || units.value * 40)

/** Hoehe einer Hoeheneinheit im Raster - Bezugsgroesse fuer alle Bauteile */
const M = computed(() => H.value / units.value)

const mitte = computed(() => H.value / 2)
const typ = computed(() => props.device?.device_type || 'other')

// Rack-Ohren links und rechts, je HE zwei Schraubloecher
const schrauben = computed(() => {
  const punkte = []

  for (let u = 0; u < units.value; u += 1) {
    punkte.push((u + 0.3) * M.value, (u + 0.7) * M.value)
  }

  return punkte
})

const schraubeR = computed(() => M.value * 0.13)

/* --- Anschluesse ---------------------------------------------------
   Welche Form eine Buchse bekommt, haengt am Porttyp. Liefert das
   Backend die Portliste mit, wird sie eins zu eins gezeichnet -
   sonst tritt eine Annahme aus Portanzahl und PoE-Angabe an ihre
   Stelle. */

const echtePorts = computed(() => {
  const liste = props.device?.ports

  if (!Array.isArray(liste) || liste.length === 0) return null

  // Gleiche Reihenfolge wie in der Portuebersicht: "Port 2" vor "Port 10"
  return [...liste]
    .sort((a, b) => String(a?.name ?? '').localeCompare(String(b?.name ?? ''), 'de', { numeric: true, sensitivity: 'base' }))
    .slice(0, 96)
    .map((port) => ({
      form: portForm(port?.port_type),
      poe: Boolean(port?.poe),
      belegt: Boolean(port?.connection) || Boolean(port?.belegt) || port?.status === 'occupied',
    }))
})

const portZahl = computed(() => {
  const gepflegt = Number(props.device?.network_ports)

  if (Number.isFinite(gepflegt) && gepflegt > 0) {
    return Math.min(gepflegt, 48)
  }

  return typ.value === 'patchpanel' ? 24 : 8
})

const poeZahl = computed(() => {
  const wert = Number(props.device?.poe_ports)

  return Number.isFinite(wert) && wert > 0 ? wert : 0
})

const ports = computed(() => echtePorts.value || Array.from({ length: portZahl.value }, (_, i) => ({
  form: 'rj45',
  poe: i < poeZahl.value,
  belegt: false,
})))

/** Buchse in ihrer Zelle: Form, Groesse und alle Innenteile */
function steckplatz(port, zx, zy, zb, zh, nachOben, key) {
  // Eine Buchse bleibt innerhalb ihrer Hoeheneinheit, auch wenn das
  // Geraet mehrere hoch ist - sonst entstehen Riesenstecker.
  const maxH = Math.min(zh * 0.94, M.value * 0.68)
  const grund = { key, form: port.form, poe: port.poe, belegt: port.belegt }

  if (port.form === 'sfp') {
    const h = maxH * 0.82
    const w = Math.min(zb * 0.9, h * 1.3)
    const x = zx + (zb - w) / 2
    const y = zy + (zh - h) / 2

    return { ...grund, x, y, w, h, ...sfpTeile(x, y, w, h) }
  }

  if (port.form === 'usb') {
    const h = maxH * 0.74
    const w = Math.min(zb * 0.82, h * 0.95)
    const x = zx + (zb - w) / 2
    const y = zy + (zh - h) / 2

    return { ...grund, x, y, w, h, ...usbTeile(x, y, w, h) }
  }

  if (port.form === 'strom') {
    const h = maxH * 0.84
    const w = Math.min(zb * 0.88, h * 1.5)
    const x = zx + (zb - w) / 2
    const y = zy + (zh - h) / 2

    return { ...grund, ...kaltgeraetedose(x, y, w, h) }
  }

  // RJ45: etwas hoeher als breit, wie die echte Buchse. Die Hoehe wird
  // auch nach unten an die Breite gebunden - sonst wird aus der Buchse
  // in einem schmalen Rack ein hochkantes Rechteck.
  const w = Math.min(zb * 0.86, maxH * 0.84)
  const h = Math.min(maxH, w / 0.84)
  const x = zx + (zb - w) / 2
  const y = zy + (zh - h) / 2

  return {
    ...grund,
    x, y, w, h,
    pfad: rj45Oeffnung(x, y, w, h, nachOben),
    kontakte: rj45Kontakte(x, y, w, h, nachOben),
  }
}

/**
 * Buchsen in einem Band verteilen. Die Reihenzahl wird so gewaehlt,
 * dass die einzelne Buchse so gross wie moeglich wird - ein 48er-Switch
 * bekommt zwei Reihen, ein 8er eine.
 */
function portRaster(liste, links, rechts, oben, unten, praefix) {
  const anzahl = liste.length

  if (anzahl === 0) return []

  const bandB = Math.max(rechts - links, 1)
  const bandH = Math.max(unten - oben, 1)
  const maxReihen = Math.max(1, Math.min(4, units.value * 2))

  let beste = { reihen: 1, flaeche: 0 }

  for (let reihen = 1; reihen <= maxReihen && reihen <= anzahl; reihen += 1) {
    const proReihe = Math.ceil(anzahl / reihen)
    const zb = bandB / proReihe
    const zh = bandH / reihen
    const hMax = Math.min(zh * 0.94, M.value * 0.68)
    const b = Math.min(zb * 0.86, hMax * 0.84)
    const h = Math.min(hMax, b / 0.84)

    // Nur wechseln, wenn es spuerbar mehr bringt: bei Gleichstand
    // bleibt es bei der ruhigeren Darstellung mit weniger Reihen.
    if (b * h > beste.flaeche * 1.08) {
      beste = { reihen, flaeche: b * h }
    }
  }

  const reihen = beste.reihen
  const proReihe = Math.ceil(anzahl / reihen)
  const zh = bandH / reihen

  // Eine Zelle wird nicht breiter als noetig: acht Buchsen sollen
  // beieinander sitzen wie am echten Geraet und nicht ueber die ganze
  // Blende wandern.
  let zb = Math.min(bandB / proReihe, M.value * 0.68 * 0.84 / 0.78)

  // Groessere Felder bekommen alle sechs Buchsen eine Fuge - so sind
  // die Bloecke auch bei 48 Ports noch auseinanderzuhalten.
  const gruppe = 6
  const fugen = proReihe >= 12 ? Math.floor((proReihe - 1) / gruppe) : 0
  let fuge = fugen ? zb * 0.34 : 0
  const gesamt = proReihe * zb + fugen * fuge

  if (gesamt > bandB) {
    const faktor = bandB / gesamt

    zb *= faktor
    fuge *= faktor
  }

  const teile = []

  for (let i = 0; i < anzahl; i += 1) {
    const reihe = Math.floor(i / proReihe)
    const spalte = i % proReihe

    teile.push(steckplatz(
      liste[i],
      links + spalte * zb + (fuge ? Math.floor(spalte / gruppe) * fuge : 0),
      oben + reihe * zh,
      zb,
      zh,
      reihen === 1 ? true : reihe % 2 === 0,
      `${praefix}${i}`,
    ))
  }

  return teile
}

/* --- Steckdosen ----------------------------------------------------
   Eine Steckdosenleiste zeigt so viele Dosen, wie am Geraet
   eingetragen sind; belegte Plaetze stecken sichtbar voll. */

const dosenZahl = computed(() => {
  const gepflegt = Number(props.device?.outlet_count)

  if (Number.isFinite(gepflegt) && gepflegt > 0) return Math.min(gepflegt, 48)

  const liste = props.device?.outlets

  if (Array.isArray(liste) && liste.length) return Math.min(liste.length, 48)

  return typ.value === 'pdu' ? 8 : 6
})

/** Welche Steckplaetze sind belegt? Ohne Angabe: keiner. */
const belegtePlaetze = computed(() => {
  const liste = props.device?.outlets
  const belegt = new Set()

  if (!Array.isArray(liste)) return belegt

  for (const platz of liste) {
    // Belegt ist ein Platz auch dann, wenn nur ein freier Text
    // eingetragen ist - etwa fuer ein Geraet ausserhalb des Racks.
    if (platz?.connected_device_id || platz?.external_label) {
      belegt.add(Number(platz.position))
    }
  }

  return belegt
})

/** Schutzkontaktdose an ihrem Platz auf der Leiste */
function schukoDose(cx, cy, r, belegt, key) {
  return { key, cx, cy, r, belegt, ...schukoTeile(cx, cy, r) }
}

/** Kaltgeraetedose: Rahmen um die drei Kontakte */
function kaltgeraetedose(x, y, w, h) {
  return { x, y, w, h, ...stromTeile(x, y, w, h) }
}

/** Kaltgeraetedosen in einem Band verteilen (USV-Rueckseite) */
function dosenRaster(anzahl, links, rechts, oben, unten, praefix, belegte) {
  if (anzahl <= 0) return []

  const bandB = Math.max(rechts - links, 1)
  const bandH = Math.max(unten - oben, 1)
  const reihen = Math.max(1, Math.min(units.value, Math.ceil(anzahl / Math.max(Math.floor(bandB / (M.value * 0.8)), 1))))
  const proReihe = Math.ceil(anzahl / reihen)
  const zb = Math.min(bandB / proReihe, M.value * 1.05)
  const zh = bandH / reihen
  const teile = []

  for (let i = 0; i < anzahl; i += 1) {
    const reihe = Math.floor(i / proReihe)
    const spalte = i % proReihe
    const h = Math.min(zh * 0.82, M.value * 0.52)
    const w = Math.min(zb * 0.86, h * 1.5)

    teile.push({
      key: `${praefix}${i}`,
      belegt: belegte ? belegte.has(i + 1) : false,
      ...kaltgeraetedose(
        links + spalte * zb + (zb - w) / 2,
        oben + reihe * zh + (zh - h) / 2,
        w,
        h,
      ),
    })
  }

  return teile
}

/** Luefter mit vier Fluegeln */
function luefter(cx, cy, r) {
  const fluegel = []

  for (let i = 0; i < 4; i += 1) {
    const winkel = (Math.PI / 2) * i + Math.PI / 6

    fluegel.push({
      x1: cx + Math.cos(winkel) * r * 0.24,
      y1: cy + Math.sin(winkel) * r * 0.24,
      x2: cx + Math.cos(winkel) * r * 0.82,
      y2: cy + Math.sin(winkel) * r * 0.82,
    })
  }

  return { cx, cy, r, fluegel }
}

/** Leerer Bauteilsatz - beide Seiten fuellen dieselben Faecher */
function leer() {
  return {
    rahmen: [],
    schaechte: [],
    anzeigen: [],
    leds: [],
    schlitze: [],
    bloecke: [],
    leisten: [],
    dosen: [],
    schuko: [],
    ports: [],
    luefter: [],
    schalter: [],
  }
}

/* --- Vorderseite -------------------------------------------------- */

const front = computed(() => {
  const teile = leer()
  const m = mitte.value
  const einheit = M.value
  const oben = H.value * 0.1
  const unten = H.value * 0.9

  if (typ.value === 'switch' || typ.value === 'patchpanel') {
    teile.ports = portRaster(ports.value, LINKS, RECHTS, oben, unten, 'f')

    return teile
  }

  if (typ.value === 'pdu') {
    // Kippschalter links, danach die Dosen
    const schalterB = einheit * 0.52
    const schalterH = einheit * 0.46
    const dosenLinks = LINKS + schalterB + einheit * 0.3

    teile.schalter.push({
      x: LINKS,
      y: m - schalterH / 2,
      w: schalterB,
      h: schalterH,
      wippe: { x: LINKS + schalterB * 0.18, y: m - schalterH * 0.32, w: schalterB * 0.64, h: schalterH * 0.64 },
    })

    const anzahl = dosenZahl.value
    const bandB = RECHTS - dosenLinks
    const reihen = Math.max(1, Math.min(units.value, Math.ceil(anzahl / Math.max(Math.floor(bandB / (einheit * 0.62)), 1))))
    const proReihe = Math.ceil(anzahl / reihen)
    const zb = Math.min(bandB / proReihe, einheit * 0.98)
    const zh = (unten - oben) / reihen

    for (let i = 0; i < anzahl; i += 1) {
      const reihe = Math.floor(i / proReihe)
      const spalte = i % proReihe
      const r = Math.min(zb * 0.46, zh * 0.46, einheit * 0.42)

      teile.schuko.push(schukoDose(
        dosenLinks + spalte * zb + zb / 2,
        oben + reihe * zh + zh / 2,
        r,
        belegtePlaetze.value.has(i + 1),
        `d${i}`,
      ))
    }

    return teile
  }

  if (typ.value === 'nas' || typ.value === 'server') {
    const anzahl = typ.value === 'nas' ? 6 : 8
    const bandB = RECHTS - LINKS
    const zb = bandB / anzahl

    for (let i = 0; i < anzahl; i += 1) {
      teile.schaechte.push({
        key: `b${i}`,
        x: LINKS + i * zb,
        y: einheit * 0.12,
        w: zb * 0.88,
        h: H.value - einheit * 0.24,
        griff: {
          x: LINKS + i * zb + zb * 0.1,
          y: m - einheit * 0.03,
          w: zb * 0.68,
          h: einheit * 0.06,
        },
        led: {
          x: LINKS + i * zb + zb * 0.1,
          y: H.value - einheit * 0.3,
          w: zb * 0.16,
          h: einheit * 0.1,
        },
      })
    }

    return teile
  }

  if (typ.value === 'ups') {
    const anzeigeB = einheit * 4.2
    const anzeigeH = Math.min(H.value * 0.62, einheit * 0.66)

    teile.anzeigen.push({
      x: LINKS,
      y: m - anzeigeH / 2,
      w: anzeigeB,
      h: anzeigeH,
      balken: [
        { x: LINKS + anzeigeB * 0.08, y: m - anzeigeH * 0.22, w: anzeigeB * 0.52, h: anzeigeH * 0.16, farbe: '#4ade80' },
        { x: LINKS + anzeigeB * 0.08, y: m + anzeigeH * 0.04, w: anzeigeB * 0.32, h: anzeigeH * 0.13, farbe: '#22c55e' },
      ],
    })

    const vonX = LINKS + anzeigeB + einheit * 0.6
    const anzahl = Math.max(Math.floor((RECHTS - vonX) / (einheit * 0.5)), 4)
    const schritt = (RECHTS - vonX) / anzahl

    for (let i = 0; i < anzahl; i += 1) {
      teile.schlitze.push({
        key: `v${i}`,
        x: vonX + i * schritt,
        y: m - H.value * 0.28,
        w: schritt * 0.42,
        h: H.value * 0.56,
      })
    }

    return teile
  }

  // Router, Firewall, Access Point, Sonstiges: Anzeige, LEDs, Ports
  const anzeigeB = einheit * 3.2
  const anzeigeH = Math.min(H.value * 0.5, einheit * 0.54)

  teile.anzeigen.push({
    x: LINKS,
    y: m - anzeigeH / 2,
    w: anzeigeB,
    h: anzeigeH,
    balken: [],
  })

  for (let i = 0; i < 4; i += 1) {
    const b = anzeigeB * 0.14

    teile.leds.push({
      key: `l${i}`,
      x: LINKS + anzeigeB * 0.1 + i * anzeigeB * 0.22,
      y: m - anzeigeH * 0.16,
      w: b,
      h: anzeigeH * 0.32,
      farbe: i === 0 ? '#22c55e' : '#2f3a49',
    })
  }

  teile.ports = portRaster(ports.value, LINKS + anzeigeB + einheit * 0.5, RECHTS, oben, unten, 'f')

  return teile
})

/* --- Rueckseite ---------------------------------------------------- */

const rueck = computed(() => {
  const teile = leer()
  const m = mitte.value
  const einheit = M.value
  const innen = RECHTS - LINKS
  const r = einheit * 0.34
  const dosenH = einheit * 0.48
  const dosenB = dosenH * 1.5

  if (typ.value === 'nas' || typ.value === 'server') {
    const netzteilB = innen * 0.27

    for (let i = 0; i < 2; i += 1) {
      const x = LINKS + i * (netzteilB + einheit * 0.2)

      teile.rahmen.push({ key: `n${i}`, x, y: einheit * 0.14, w: netzteilB, h: H.value - einheit * 0.28 })
      teile.luefter.push(luefter(x + netzteilB * 0.3, m, Math.min(r, netzteilB * 0.26)))
      teile.dosen.push({ key: `nd${i}`, belegt: false, ...kaltgeraetedose(x + netzteilB - dosenB - einheit * 0.12, m - dosenH / 2, dosenB, dosenH) })
    }

    const restVon = LINKS + 2 * (netzteilB + einheit * 0.2) + einheit * 0.3

    teile.luefter.push(luefter(restVon + r, m, r), luefter(restVon + r * 3 + einheit * 0.12, m, r))

    const portsVon = restVon + r * 4 + einheit * 0.5

    teile.ports = portRaster(ports.value.slice(0, 8), portsVon, RECHTS, H.value * 0.16, H.value * 0.84, 'r')

    return teile
  }

  if (typ.value === 'switch') {
    teile.dosen.push({ key: 'rd0', belegt: false, ...kaltgeraetedose(LINKS, m - dosenH / 2, dosenB, dosenH) })

    const vonX = LINKS + dosenB + einheit * 0.6
    const bis = RECHTS - einheit * 4
    const anzahl = Math.max(Math.floor((bis - vonX) / (einheit * 0.5)), 4)
    const schritt = (bis - vonX) / anzahl

    for (let i = 0; i < anzahl; i += 1) {
      teile.schlitze.push({ key: `rs${i}`, x: vonX + i * schritt, y: m - H.value * 0.26, w: schritt * 0.42, h: H.value * 0.52 })
    }

    teile.ports = portRaster([{ form: 'rj45', poe: false, belegt: false }], bis + einheit * 0.3, bis + einheit * 1.3, H.value * 0.2, H.value * 0.8, 'r')
    teile.luefter.push(luefter(RECHTS - r, m, r))

    return teile
  }

  if (typ.value === 'patchpanel') {
    const anzahl = 12
    const schritt = innen / anzahl

    for (let i = 0; i < anzahl; i += 1) {
      const x = LINKS + i * schritt
      const w = schritt * 0.78
      const bh = H.value * 0.46

      teile.bloecke.push({
        key: `rb${i}`,
        x,
        y: m - bh / 2,
        w,
        h: bh,
        streifen: [
          { x: x + w * 0.12, y: m - bh / 2 + bh * 0.18, w: w * 0.76, h: Math.max(bh * 0.12, 0.8), farbe: i % 2 ? '#3b82f6' : '#22c55e' },
          { x: x + w * 0.12, y: m - bh / 2 + bh * 0.52, w: w * 0.76, h: Math.max(bh * 0.12, 0.8), farbe: i % 2 ? '#f97316' : '#a855f7' },
        ],
      })
    }

    teile.leisten.push({ key: 'rl0', x: LINKS, y: H.value - einheit * 0.22, w: innen, h: einheit * 0.1 })

    return teile
  }

  if (typ.value === 'ups') {
    teile.dosen.push({ key: 'rd0', belegt: false, ...kaltgeraetedose(LINKS, m - dosenH / 2, dosenB * 1.2, dosenH) })

    const vonX = LINKS + dosenB * 1.2 + einheit * 0.5

    teile.dosen.push(...dosenRaster(
      dosenZahl.value,
      vonX,
      RECHTS - (H.value > einheit * 1.5 ? r * 2.4 : 0),
      H.value * 0.1,
      H.value * 0.9,
      'ru',
      belegtePlaetze.value,
    ))

    if (H.value > einheit * 1.5) teile.luefter.push(luefter(RECHTS - r, m, r))

    return teile
  }

  if (typ.value === 'pdu') {
    // Zuleitung, Schalter und die Schiene zur Zugentlastung
    teile.dosen.push({ key: 'rd0', belegt: false, ...kaltgeraetedose(LINKS, m - dosenH / 2, dosenB, dosenH) })

    const schalterB = einheit * 0.52
    const schalterH = einheit * 0.46
    const schalterX = LINKS + dosenB + einheit * 0.4

    teile.schalter.push({
      x: schalterX,
      y: m - schalterH / 2,
      w: schalterB,
      h: schalterH,
      wippe: { x: schalterX + schalterB * 0.18, y: m - schalterH * 0.32, w: schalterB * 0.64, h: schalterH * 0.64 },
    })

    teile.leisten.push({ key: 'rl0', x: schalterX + schalterB + einheit * 0.5, y: m - einheit * 0.07, w: RECHTS - schalterX - schalterB - einheit * 0.5, h: einheit * 0.14 })

    return teile
  }

  // Router, Firewall, Access Point, Sonstiges
  teile.dosen.push({ key: 'rd0', belegt: false, ...kaltgeraetedose(LINKS, m - dosenH / 2, dosenB, dosenH) })

  const vonX = LINKS + dosenB + einheit * 0.6
  const bis = RECHTS - einheit * 3.4
  const anzahl = Math.max(Math.floor((bis - vonX) / (einheit * 0.5)), 4)
  const schritt = (bis - vonX) / anzahl

  for (let i = 0; i < anzahl; i += 1) {
    teile.schlitze.push({ key: `rs${i}`, x: vonX + i * schritt, y: m - H.value * 0.24, w: schritt * 0.42, h: H.value * 0.48 })
  }

  teile.ports = portRaster(
    [{ form: 'usb', poe: false, belegt: false }, { form: 'rj45', poe: false, belegt: false }],
    bis + einheit * 0.2,
    RECHTS - r * 2.4,
    H.value * 0.2,
    H.value * 0.8,
    'r',
  )

  teile.luefter.push(luefter(RECHTS - r, m, r))

  return teile
})

const teile = computed(() => (rueckseite.value ? rueck.value : front.value))

/* --- Beschriftung auf dem linken Ohr ------------------------------- */

const beschriftung = computed(() => String(props.device?.manufacturer || props.device?.name || '').slice(0, 12))

/* Die Schrift wird in Pixeln bemessen, nicht in Rastereinheiten: das
   Ohr ist ein fester Anteil der Breite, also in einem schmalen Rack
   auch in Pixeln schmal. Was dort nicht mehr lesbar waere, entfaellt. */
const schrift = computed(() => {
  const laenge = Math.max(beschriftung.value.length, 1)

  if (!breitePx.value) {
    return { groesse: Math.min(M.value * 0.46, 88 / (laenge * 0.6)), zeigen: true }
  }

  const proEinheit = breitePx.value / W
  const zeilePx = M.value * proEinheit
  const ohrPx = OHR * proEinheit
  const px = Math.min(zeilePx * 0.46, (ohrPx * 0.88) / (laenge * 0.6))

  return { groesse: px / proEinheit, zeigen: px >= 6.5 }
})
</script>

<template>
  <svg
    ref="svgRef"
    class="faceplate"
    :viewBox="`0 0 ${W} ${H}`"
    preserveAspectRatio="none"
    role="img"
    :aria-label="`${rueckseite ? 'Rückansicht' : 'Frontansicht'} ${device.name}`"
  >
    <defs>
      <linearGradient :id="`metall-${device.id}-${side}`" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" :stop-color="rueckseite ? '#333c49' : '#3c4553'" />
        <stop offset="45%" :stop-color="rueckseite ? '#232a36' : '#2b3341'" />
        <stop offset="100%" :stop-color="rueckseite ? '#171d27' : '#1d2430'" />
      </linearGradient>
    </defs>

    <!-- Grundplatte -->
    <rect
      x="1"
      y="1"
      :width="W - 2"
      :height="H - 2"
      rx="5"
      :fill="`url(#metall-${device.id}-${side})`"
      stroke="#0e131b"
      stroke-width="2"
    />

    <!-- Rack-Ohren mit Schraubloechern -->
    <rect x="1" y="1" :width="OHR" :height="H - 2" rx="5" fill="#232b38" />
    <rect :x="W - OHR - 1" y="1" :width="OHR" :height="H - 2" rx="5" fill="#232b38" />

    <template v-for="(y, i) in schrauben" :key="`s${i}`">
      <circle cx="30" :cy="y" :r="schraubeR" fill="#12171f" stroke="#414c5d" stroke-width="0.8" />
      <circle :cx="W - 30" :cy="y" :r="schraubeR" fill="#12171f" stroke="#414c5d" stroke-width="0.8" />
    </template>

    <!-- Gehaeuseteile, etwa Netzteile auf der Rueckseite -->
    <rect
      v-for="stueck in teile.rahmen"
      :key="stueck.key"
      :x="stueck.x"
      :y="stueck.y"
      :width="stueck.w"
      :height="stueck.h"
      rx="3"
      fill="#161d28"
      stroke="#3b4657"
      stroke-width="1.2"
    />

    <!-- Lueftungsschlitze -->
    <rect
      v-for="schlitz in teile.schlitze"
      :key="schlitz.key"
      :x="schlitz.x"
      :y="schlitz.y"
      :width="schlitz.w"
      :height="schlitz.h"
      :rx="schlitz.w / 2"
      fill="#10151e"
    />

    <!-- Laufwerksschaechte -->
    <template v-for="schacht in teile.schaechte" :key="schacht.key">
      <rect :x="schacht.x" :y="schacht.y" :width="schacht.w" :height="schacht.h" rx="2" fill="#151b25" stroke="#404b5c" stroke-width="1.2" />
      <rect :x="schacht.griff.x" :y="schacht.griff.y" :width="schacht.griff.w" :height="schacht.griff.h" rx="1" fill="#3a4454" />
      <rect :x="schacht.led.x" :y="schacht.led.y" :width="schacht.led.w" :height="schacht.led.h" rx="1" fill="#22c55e" />
    </template>

    <!-- Anzeigen -->
    <template v-for="(anzeige, i) in teile.anzeigen" :key="`an${i}`">
      <rect :x="anzeige.x" :y="anzeige.y" :width="anzeige.w" :height="anzeige.h" rx="2" fill="#0b1a12" stroke="#3f5a4a" stroke-width="1.2" />
      <rect
        v-for="(balken, j) in anzeige.balken"
        :key="`ab${i}-${j}`"
        :x="balken.x"
        :y="balken.y"
        :width="balken.w"
        :height="balken.h"
        rx="1"
        :fill="balken.farbe"
      />
    </template>

    <rect
      v-for="led in teile.leds"
      :key="led.key"
      :x="led.x"
      :y="led.y"
      :width="led.w"
      :height="led.h"
      rx="1"
      :fill="led.farbe"
    />

    <!-- Anschlussbloecke eines Patchpanels -->
    <template v-for="block in teile.bloecke" :key="block.key">
      <rect :x="block.x" :y="block.y" :width="block.w" :height="block.h" rx="2" fill="#1b2430" stroke="#46536a" stroke-width="1.2" />
      <rect
        v-for="(streifen, j) in block.streifen"
        :key="`${block.key}-${j}`"
        :x="streifen.x"
        :y="streifen.y"
        :width="streifen.w"
        :height="streifen.h"
        rx="1"
        :fill="streifen.farbe"
        opacity="0.85"
      />
    </template>

    <!-- Schienen und Leisten -->
    <rect
      v-for="leiste in teile.leisten"
      :key="leiste.key"
      :x="leiste.x"
      :y="leiste.y"
      :width="leiste.w"
      :height="leiste.h"
      rx="2"
      fill="#2a3444"
    />

    <!-- Kaltgeraetedosen -->
    <template v-for="steckdose in teile.dosen" :key="steckdose.key">
      <rect :x="steckdose.x" :y="steckdose.y" :width="steckdose.w" :height="steckdose.h" rx="2.5" :fill="steckdose.belegt ? '#4a5568' : '#0b0f16'" stroke="#6b7a90" stroke-width="1.4" />
      <rect
        v-for="(stift, j) in steckdose.stifte"
        :key="`${steckdose.key}-${j}`"
        :x="stift.x"
        :y="stift.y"
        :width="stift.w"
        :height="stift.h"
        rx="0.5"
        :fill="steckdose.belegt ? '#2b3341' : '#8d9aae'"
      />
    </template>

    <!-- Schutzkontaktdosen einer Steckdosenleiste -->
    <template v-for="dose in teile.schuko" :key="dose.key">
      <!-- Rand der Dose, darin die Vertiefung oder der steckende Stecker -->
      <circle :cx="dose.cx" :cy="dose.cy" :r="dose.r" fill="#4a5464" stroke="#78879d" stroke-width="1.1" />
      <circle :cx="dose.cx" :cy="dose.cy" :r="dose.innen" :fill="dose.belegt ? '#8a97ab' : '#1b222c'" />

      <template v-if="!dose.belegt">
        <circle
          v-for="(loch, j) in dose.loecher"
          :key="`${dose.key}-l${j}`"
          :cx="loch.cx"
          :cy="loch.cy"
          :r="loch.r"
          fill="#05080d"
        />
      </template>

      <rect
        v-for="(buegel, j) in dose.buegel"
        :key="`${dose.key}-b${j}`"
        :x="buegel.x"
        :y="buegel.y"
        :width="buegel.w"
        :height="buegel.h"
        rx="0.6"
        :fill="dose.belegt ? '#6d7b90' : '#c3cdda'"
      />

      <rect
        v-if="dose.belegt"
        :x="dose.griff.x"
        :y="dose.griff.y"
        :width="dose.griff.w"
        :height="dose.griff.h"
        rx="1.2"
        fill="#39434f"
      />
    </template>

    <!-- Anschluesse: RJ45, SFP, USB, Strom -->
    <template v-for="port in teile.ports" :key="port.key">
      <!-- RJ45: Gehaeuse, Buchsenoeffnung mit Nut, Goldkontakte -->
      <template v-if="port.form === 'rj45'">
        <rect
          :x="port.x"
          :y="port.y"
          :width="port.w"
          :height="port.h"
          rx="1.5"
          :fill="port.poe ? '#2a2519' : '#1a202b'"
          :stroke="port.poe ? '#79683c' : '#59667a'"
          stroke-width="1.1"
        />
        <path :d="port.pfad" :fill="port.belegt ? '#7d8ba1' : '#05080d'" />
        <rect
          v-for="(kontakt, j) in (port.belegt ? [] : port.kontakte)"
          :key="`${port.key}-k${j}`"
          :x="kontakt.x"
          :y="kontakt.y"
          :width="kontakt.w"
          :height="kontakt.h"
          :fill="port.poe ? '#d8b25a' : '#b99b4e'"
        />
      </template>

      <!-- SFP: Kaefig mit waagerechtem Schlitz -->
      <template v-else-if="port.form === 'sfp'">
        <rect
          :x="port.x"
          :y="port.y"
          :width="port.w"
          :height="port.h"
          rx="1.5"
          fill="#141b25"
          stroke="#94a3b8"
          stroke-width="1.2"
        />
        <rect
          :x="port.schlitz.x"
          :y="port.schlitz.y"
          :width="port.schlitz.w"
          :height="port.schlitz.h"
          rx="0.8"
          :fill="port.belegt ? '#6b7a90' : '#05080d'"
        />
        <rect
          :x="port.buegel.x"
          :y="port.buegel.y"
          :width="port.buegel.w"
          :height="port.buegel.h"
          rx="0.6"
          fill="#7e8da3"
        />
      </template>

      <!-- USB -->
      <template v-else-if="port.form === 'usb'">
        <rect :x="port.x" :y="port.y" :width="port.w" :height="port.h" rx="1.2" fill="#05080d" stroke="#59667a" stroke-width="1.1" />
        <rect :x="port.zunge.x" :y="port.zunge.y" :width="port.zunge.w" :height="port.zunge.h" rx="0.6" fill="#b6c2d4" />
      </template>

      <!-- Stromanschluss -->
      <template v-else>
        <rect :x="port.x" :y="port.y" :width="port.w" :height="port.h" rx="2" fill="#0b0f16" stroke="#6b7a90" stroke-width="1.3" />
        <rect
          v-for="(stift, j) in port.stifte"
          :key="`${port.key}-s${j}`"
          :x="stift.x"
          :y="stift.y"
          :width="stift.w"
          :height="stift.h"
          rx="0.5"
          fill="#8d9aae"
        />
      </template>
    </template>

    <!-- Kippschalter -->
    <template v-for="(schalter, i) in teile.schalter" :key="`sw${i}`">
      <rect :x="schalter.x" :y="schalter.y" :width="schalter.w" :height="schalter.h" rx="1.5" fill="#10151e" stroke="#5d6b80" stroke-width="1.1" />
      <rect :x="schalter.wippe.x" :y="schalter.wippe.y" :width="schalter.wippe.w" :height="schalter.wippe.h" rx="1" fill="#c0392b" />
    </template>

    <!-- Luefter -->
    <template v-for="(fan, i) in teile.luefter" :key="`rf${i}`">
      <circle :cx="fan.cx" :cy="fan.cy" :r="fan.r" fill="#0d121a" stroke="#4a5768" stroke-width="1.4" />
      <circle :cx="fan.cx" :cy="fan.cy" :r="fan.r * 0.62" fill="none" stroke="#333f4f" stroke-width="1" />
      <line
        v-for="(fluegel, j) in fan.fluegel"
        :key="`rff${i}-${j}`"
        :x1="fluegel.x1"
        :y1="fluegel.y1"
        :x2="fluegel.x2"
        :y2="fluegel.y2"
        stroke="#2f3b4b"
        stroke-width="1.6"
        stroke-linecap="round"
      />
      <circle :cx="fan.cx" :cy="fan.cy" :r="fan.r * 0.2" fill="#4a5768" />
    </template>

    <!-- Herstellerbeschriftung auf dem linken Ohr -->
    <text
      v-if="schrift.zeigen"
      x="76"
      :y="mitte"
      fill="#8d9aae"
      :font-size="schrift.groesse"
      font-family="system-ui, sans-serif"
      font-weight="700"
      text-anchor="middle"
      dominant-baseline="central"
    >
      {{ beschriftung }}
    </text>
  </svg>
</template>

<style scoped>
.faceplate {
  display: block;
  width: 100%;
  height: 100%;
}
</style>

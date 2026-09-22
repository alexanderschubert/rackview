// Anzeige-Logik fuer Anschluesse, gemeinsam genutzt von
// Port-Uebersicht, Port-Details und dem Geraete-Panel. Im unteren Teil
// ausserdem die Zeichnungen - Netzwerkbuchsen wie Steckdosen.

export const UPLINK_TYPES = ['sfp', 'sfp+', 'sfp28', 'qsfp', 'fiber']

export function isUplinkPort(port) {
  return UPLINK_TYPES.includes(port.port_type)
}

// "Verbunden" heisst: Es gibt einen Eintrag in port_connections oder
// der Port ist von Hand als belegt markiert.
export function portState(port) {
  if (port.status === 'faulty') return 'faulty'
  if (port.status === 'disabled') return 'disabled'
  if (port.connection || port.status === 'occupied') return 'connected'
  return 'free'
}

export function portStateLabel(port) {
  return {
    connected: 'Verbunden',
    free: 'Frei',
    faulty: 'Defekt',
    disabled: 'Deaktiviert',
  }[portState(port)]
}

export function portTypeLabel(type) {
  return {
    ethernet: 'Ethernet (RJ45)',
    sfp: 'SFP',
    'sfp+': 'SFP+',
    sfp28: 'SFP28',
    qsfp: 'QSFP',
    fiber: 'Glasfaser',
    power: 'Strom',
    console: 'Konsole',
    usb: 'USB',
    other: 'Sonstiges',
  }[type] || type || '–'
}

// Im Raster nur die Nummer: aus "Port 12" wird "12"
export function portNumber(port, index) {
  const treffer = String(port.name || '').match(/\d+/)
  return treffer ? treffer[0] : String(index + 1)
}


/* --- Zeichnung einer Buchse ---------------------------------------
   Gemeinsame Form fuer die Blende im Rack und die Port-Uebersicht:
   dieselbe Buchse soll an beiden Stellen gleich aussehen, nur in
   anderer Groesse. Jede Funktion bekommt das Rechteck, in dem sie
   zeichnen darf, und liefert reine Zahlen - Farben und Strichstaerken
   entscheidet die Ansicht. */

/** Welche Bauform hat dieser Porttyp? */
export function portForm(type) {
  if (UPLINK_TYPES.includes(type)) return 'sfp'
  if (type === 'usb') return 'usb'
  if (type === 'power') return 'strom'

  return 'rj45'
}

/**
 * Umriss der RJ45-Oeffnung: Rechteck mit der Nut fuer die Rastnase.
 * nachOben = Nut oben, sonst unten (gestapelte Buchsen sind gespiegelt).
 */
export function rj45Oeffnung(x, y, w, h, nachOben = true) {
  const z = (v) => Math.round(v * 100) / 100
  const nutB = w * 0.38
  const nutH = h * 0.3
  const l = x + w * 0.13
  const r = x + w * 0.87
  const o = y + h * 0.12
  const u = y + h * 0.88
  const m1 = x + w / 2 - nutB / 2
  const m2 = x + w / 2 + nutB / 2

  if (nachOben) {
    const k = o + nutH

    return `M${z(l)} ${z(k)}H${z(m1)}V${z(o)}H${z(m2)}V${z(k)}H${z(r)}V${z(u)}H${z(l)}Z`
  }

  const k = u - nutH

  return `M${z(l)} ${z(o)}H${z(r)}V${z(k)}H${z(m2)}V${z(u)}H${z(m1)}V${z(k)}H${z(l)}Z`
}

/**
 * Die acht Goldkontakte. Wird die Buchse klein gezeichnet, verschwimmen
 * sie zu einem Fleck - dann lieber ein einzelner Balken oder gar nichts.
 */
export function rj45Kontakte(x, y, w, h, nachOben = true) {
  const innen = w * 0.74

  if (innen < 9) return []

  const hoehe = h * 0.3
  const oben = nachOben ? y + h * 0.46 : y + h * 0.24

  if (innen < 17) {
    return [{ x: x + w * 0.24, y: oben, w: w * 0.52, h: hoehe }]
  }

  const feld = w * 0.56
  const start = x + (w - feld) / 2
  const schritt = feld / 7
  const breite = Math.max(schritt * 0.5, 0.6)

  return Array.from({ length: 8 }, (_, i) => ({
    x: start + i * schritt - breite / 2,
    y: oben,
    w: breite,
    h: hoehe,
  }))
}

/** SFP-Kaefig: waagerechter Schlitz, darunter der Buegel */
export function sfpTeile(x, y, w, h) {
  return {
    schlitz: { x: x + w * 0.11, y: y + h * 0.22, w: w * 0.78, h: h * 0.42 },
    buegel: { x: x + w * 0.2, y: y + h * 0.72, w: w * 0.6, h: Math.max(h * 0.1, 0.7) },
  }
}

/** USB: die Zunge sitzt in der oberen Haelfte */
export function usbTeile(x, y, w, h) {
  return {
    zunge: { x: x + w * 0.2, y: y + h * 0.24, w: w * 0.6, h: h * 0.22 },
  }
}

/**
 * Schutzkontaktdose: runde Vertiefung, zwei Loecher, oben und unten
 * die Buegel. "griff" ist der Steg des Steckers, der in einem
 * belegten Platz die Loecher verdeckt.
 */
export function schukoTeile(cx, cy, r) {
  return {
    innen: r * 0.84,
    loecher: [
      { cx: cx - r * 0.42, cy, r: Math.max(r * 0.15, 0.5) },
      { cx: cx + r * 0.42, cy, r: Math.max(r * 0.15, 0.5) },
    ],
    buegel: [
      { x: cx - r * 0.44, y: cy - r * 0.95, w: r * 0.88, h: Math.max(r * 0.16, 0.5) },
      { x: cx - r * 0.44, y: cy + r * 0.79, w: r * 0.88, h: Math.max(r * 0.16, 0.5) },
    ],
    griff: { x: cx - r * 0.46, y: cy - r * 0.2, w: r * 0.92, h: r * 0.4 },
  }
}

/** Kaltgeraetedose: drei Kontakte, der mittlere tiefer */
export function stromTeile(x, y, w, h) {
  const stiftB = w * 0.09
  const stiftH = h * 0.26

  return {
    stifte: [
      { x: x + w * 0.22, y: y + h * 0.28, w: stiftB, h: stiftH },
      { x: x + w * 0.455, y: y + h * 0.5, w: stiftB, h: stiftH },
      { x: x + w * 0.69, y: y + h * 0.28, w: stiftB, h: stiftH },
    ],
  }
}

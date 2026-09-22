// Gemeinsame Stammdaten zu Geraetetypen.
//
// Einzige Quelle fuer Typwerte und Bezeichnungen: Formular, Untertitel,
// Rack-Liste und Netzwerkplan lesen alle von hier. Vorher standen die
// Schluessel an mehreren Stellen und liefen auseinander ("patch_panel"
// hier, "patchpanel" in der Datenbank).

export const deviceTypes = [
  { value: 'server', label: 'Server', icon: '▣' },
  { value: 'switch', label: 'Switch', icon: '⇄' },
  { value: 'router', label: 'Router', icon: '⌁' },
  { value: 'firewall', label: 'Firewall', icon: '◈' },
  { value: 'nas', label: 'NAS', icon: '▤' },
  { value: 'patchpanel', label: 'Patchpanel', icon: '▦' },
  { value: 'pdu', label: 'Steckdosenleiste / PDU', icon: '⌸' },
  { value: 'ups', label: 'USV / UPS', icon: '⚡' },
  { value: 'ap', label: 'Access Point', icon: '📶' },
  { value: 'other', label: 'Sonstiges', icon: '▣' },
]

function findType(type) {
  return deviceTypes.find((item) => item.value === type)
}

export function getDeviceTypeLabel(type) {
  return findType(type)?.label || 'Gerät'
}

export function getDeviceIcon(type) {
  return findType(type)?.icon || '▣'
}


// --- PoE -----------------------------------------------------

export const poeTypes = [
  { value: 'poe', label: 'PoE (802.3af, 15,4 W)', short: 'PoE' },
  { value: 'poe+', label: 'PoE+ (802.3at, 30 W)', short: 'PoE+' },
  { value: 'poe++', label: 'PoE++ (802.3bt, bis 90 W)', short: 'PoE++' },
  { value: 'passive', label: 'Passiv (24 V)', short: 'Passiv' },
]

// Nur bei diesen Typen fragt das Formular nach PoE. Ein Patchpanel
// ist passiv, eine USV oder ein NAS speist kein PoE ein.
export const POE_CAPABLE_TYPES = ['switch', 'router', 'firewall', 'other']


// --- Steckplaetze --------------------------------------------

// Diese Typen verteilen Strom und haben deshalb Steckplaetze. An einer
// USV haengen Geraete oft direkt, nicht erst hinter einer Leiste.
export const OUTLET_CAPABLE_TYPES = ['pdu', 'ups']

export function hasOutlets(device) {
  return OUTLET_CAPABLE_TYPES.includes(device?.device_type)
}

export function getPoeLabel(value) {
  return poeTypes.find((item) => item.value === value)?.label || ''
}

export function getPoeShort(value) {
  return poeTypes.find((item) => item.value === value)?.short || ''
}


// --- Status --------------------------------------------------

export const deviceStatuses = [
  { value: 'active', label: 'Aktiv' },
  { value: 'planned', label: 'Geplant' },
  { value: 'maintenance', label: 'Wartung' },
  { value: 'retired', label: 'Außer Betrieb' },
]

export function getStatusLabel(value) {
  return deviceStatuses.find((item) => item.value === (value || 'active'))?.label || 'Aktiv'
}

// --- Einbauseite -------------------------------------------------
//
// Bestimmt, in welcher Ansicht ein Geraet erscheint. Zwei halbtiefe
// Geraete duerfen sich eine Hoeheneinheit teilen, wenn eines vorne und
// eines hinten sitzt - etwa Patchpanel vorne, Steckdosenleiste hinten.

export const mountSides = [
  { value: 'full', label: 'Volle Tiefe', kurz: 'Volle Tiefe' },
  { value: 'front', label: 'Nur vorne', kurz: 'Vorne' },
  { value: 'rear', label: 'Nur hinten', kurz: 'Hinten' },
]

export function getMountSide(device) {
  const wert = device?.mount_side

  return mountSides.some((seite) => seite.value === wert) ? wert : 'full'
}

export function getMountSideLabel(device) {
  return mountSides.find((seite) => seite.value === getMountSide(device))?.label || 'Volle Tiefe'
}

/** Ist das Geraet in dieser Ansicht zu sehen? */
export function isOnSide(device, ansicht) {
  const seite = getMountSide(device)

  return seite === 'full' || seite === ansicht
}

/** Stehen sich zwei Einbauseiten im Weg? Volle Tiefe kollidiert mit allem. */
export function sidesCollide(eine, andere) {
  return eine === 'full' || andere === 'full' || eine === andere
}


// --- Kauf und Garantie ---------------------------------------
//
// Gespeichert wird das Ende der Garantie, nicht ihre Dauer: So passen
// auch verlaengerte Garantien hinein, und "laeuft bald ab" ist ohne
// Rechnerei zu beantworten. Die Dauer bietet das Formular nur als
// Schnellwahl an.

/** Ab so vielen Tagen Restlaufzeit wird gewarnt */
export const WARRANTY_WARNING_DAYS = 60

/** "2026-09-21" wird zu "21.09.2026"; alles Unlesbare bleibt leer */
export function formatDate(wert) {
  if (!wert) return ''

  // Ohne Zeitanteil liest der Browser ein reines Datum als UTC und
  // zeigt in westlichen Zeitzonen den Vortag. Mit T00:00:00 ist es
  // ortszeitlich gemeint - so, wie es eingetragen wurde.
  const datum = new Date(`${String(wert).slice(0, 10)}T00:00:00`)

  return Number.isNaN(datum.getTime())
    ? ''
    : datum.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Tage bis zu diesem Datum; negativ, wenn es vorbei ist */
export function daysUntil(wert) {
  if (!wert) return null

  const ziel = new Date(`${String(wert).slice(0, 10)}T00:00:00`)

  if (Number.isNaN(ziel.getTime())) return null

  const heute = new Date()
  heute.setHours(0, 0, 0, 0)

  return Math.round((ziel - heute) / 86400000)
}

/**
 * Zustand der Garantie eines Geraets.
 *
 * zustand: 'keine' | 'aktiv' | 'bald' | 'abgelaufen'
 * text:    ganzer Satz fuer die Detailansicht
 * kurz:    knappe Fassung fuer Tabelle und Kachel
 */
export function getWarrantyInfo(device) {
  const bis = device?.warranty_until

  if (!bis) return { zustand: 'keine', tage: null, text: '–', kurz: '–' }

  const tage = daysUntil(bis)
  const datum = formatDate(bis)

  if (tage === null) return { zustand: 'keine', tage: null, text: '–', kurz: '–' }

  if (tage < 0) {
    return {
      zustand: 'abgelaufen',
      tage,
      text: `abgelaufen am ${datum}`,
      kurz: 'abgelaufen',
    }
  }

  if (tage <= WARRANTY_WARNING_DAYS) {
    const rest = tage === 0 ? 'läuft heute ab' : tage === 1 ? 'läuft morgen ab' : `läuft in ${tage} Tagen ab`
    const kurz = tage === 0 ? 'heute' : tage === 1 ? 'morgen' : `noch ${tage} Tage`

    return { zustand: 'bald', tage, text: `${rest} (${datum})`, kurz }
  }

  return { zustand: 'aktiv', tage, text: `läuft bis ${datum}`, kurz: `bis ${datum}` }
}

/** Kaufdatum plus N Jahre als "JJJJ-MM-TT" */
export function addYears(datumsText, jahre) {
  if (!datumsText) return ''

  const datum = new Date(`${String(datumsText).slice(0, 10)}T00:00:00`)

  if (Number.isNaN(datum.getTime())) return ''

  datum.setFullYear(datum.getFullYear() + jahre)

  // Bewusst von Hand zusammengesetzt: toISOString() rechnet auf UTC um
  // und liefert hierzulande den Vortag.
  const monat = String(datum.getMonth() + 1).padStart(2, '0')
  const tag = String(datum.getDate()).padStart(2, '0')

  return `${datum.getFullYear()}-${monat}-${tag}`
}

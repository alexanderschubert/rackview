// IPv4-Hilfen, gemeinsam genutzt von IP-Verwaltung und Geraeteliste.

// Sortierwert fuer alles, was keine gueltige IPv4 ist - ans Ende.
// Bewusst keine Infinity: Infinity - Infinity ergibt NaN und
// bringt die Sortierung durcheinander.
export const SORT_LAST = 2 ** 40

export function ipToNumber(ip) {
  const teile = String(ip || '').trim().split('.')

  if (teile.length !== 4) return null

  let zahl = 0

  for (const teil of teile) {
    if (!/^\d{1,3}$/.test(teil) || Number(teil) > 255) return null
    zahl = zahl * 256 + Number(teil)
  }

  return zahl
}

// Numerisch vergleichbar: "192.168.1.2" vor "192.168.1.10"
export function ipSortValue(ip) {
  const zahl = ipToNumber(ip)
  return zahl === null ? SORT_LAST : zahl
}

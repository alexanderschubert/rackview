// QR-Codes als SVG - fuer die 2FA-Einrichtung und die Geraete-Etiketten.
//
// Erzeugt wird alles im Browser; der Inhalt (etwa der 2FA-Schluessel)
// geht an keinen fremden Dienst.

import qrcode from 'qrcode-generator'

/**
 * SVG-Markup eines QR-Codes. Enthaelt nur selbst erzeugte Zahlen und ist
 * damit sicher fuer v-html.
 *
 * Nur ASCII verwenden: Umlaute vorher per encodeURIComponent kodieren
 * (Links und otpauth-Adressen sind das ohnehin).
 *
 * @param {string} text
 * @param {{ fehlerkorrektur?: 'L'|'M'|'Q'|'H', rand?: number }} optionen
 *   rand: Ruhezone in Modulen; Scanner brauchen etwas Weiss drumherum
 */
export function qrSvg(text, { fehlerkorrektur = 'M', rand = 2 } = {}) {
  const qr = qrcode(0, fehlerkorrektur) // 0 = kleinste passende Groesse
  qr.addData(String(text), 'Byte')
  qr.make()

  const anzahl = qr.getModuleCount()
  const kante = anzahl + rand * 2
  let pfad = ''

  for (let zeile = 0; zeile < anzahl; zeile++) {
    for (let spalte = 0; spalte < anzahl; spalte++) {
      if (qr.isDark(zeile, spalte)) pfad += `M${spalte + rand} ${zeile + rand}h1v1h-1z`
    }
  }

  return (
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${kante} ${kante}" ` +
    'shape-rendering="crispEdges" aria-hidden="true">' +
    `<rect width="${kante}" height="${kante}" fill="#fff"/>` +
    `<path d="${pfad}" fill="#000"/></svg>`
  )
}

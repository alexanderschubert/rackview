<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { qrSvg } from '../lib/qr.js'

const props = defineProps({
  user: { type: Object, required: true },
  // apiRequest aus App.vue: kennt den CSRF-Header und meldet ein
  // Sitzungsende an die App zurueck
  api: { type: Function, required: true },
  // 'auto' | 'light' | 'dark'
  theme: { type: String, default: 'auto' },
})

const emit = defineEmits(['user-updated', 'account-deleted', 'theme'])

const ANSICHTEN = [
  { wert: 'auto', label: 'Automatisch', hinweis: 'Folgt dem System' },
  { wert: 'light', label: 'Hell', hinweis: '' },
  { wert: 'dark', label: 'Dunkel', hinweis: '' },
]

// --- Hilfen ---------------------------------------------------

const datumFormat = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })

function datum(iso) {
  return iso ? datumFormat.format(new Date(iso)) : '–'
}

function zuletztAktiv(iso) {
  if (!iso) return '–'

  const minuten = Math.round((Date.now() - new Date(iso).getTime()) / 60000)

  if (minuten < 2) return 'gerade eben'
  if (minuten < 60) return `vor ${minuten} Min.`

  const stunden = Math.round(minuten / 60)

  return stunden < 24 ? `vor ${stunden} Std.` : `am ${datum(iso)}`
}

function initialen(name) {
  const teile = String(name || '').trim().split(/\s+/).filter(Boolean)
  return (teile.slice(0, 2).map((teil) => teil[0]).join('') || '?').toUpperCase()
}

// Gleiche Pruefung wie im Backend, spart einen Umweg ueber den Server
function passwortFehler(passwort, wiederholung) {
  if (passwort.length < 10) return 'Das Passwort muss mindestens 10 Zeichen lang sein.'
  if (passwort !== wiederholung) return 'Die beiden Passwörter stimmen nicht überein.'
  return ''
}

// --- Mein Profil ----------------------------------------------

const profil = reactive({ name: '', email: '', laedt: false, fehler: '', erfolg: '' })

function profilZuruecksetzen() {
  profil.name = props.user.name
  profil.email = props.user.email
}

watch(() => props.user, profilZuruecksetzen, { immediate: true })

const profilGeaendert = computed(() =>
  profil.name.trim() !== props.user.name ||
  profil.email.trim().toLowerCase() !== props.user.email
)

async function profilSpeichern() {
  if (profil.laedt || !profilGeaendert.value) return

  profil.laedt = true
  profil.fehler = ''
  profil.erfolg = ''

  try {
    const antwort = await props.api('/profile', {
      method: 'PUT',
      body: JSON.stringify({ name: profil.name.trim(), email: profil.email.trim() }),
    })

    emit('user-updated', antwort.user)
    profil.erfolg = 'Profil gespeichert.'
    await kontenLaden()
  } catch (error) {
    profil.fehler = error.message
  } finally {
    profil.laedt = false
  }
}

// --- Passwort aendern -----------------------------------------

const passwort = reactive({ aktuell: '', neu: '', wiederholung: '', laedt: false, fehler: '', erfolg: '' })

async function passwortAendern() {
  if (passwort.laedt) return

  passwort.erfolg = ''
  passwort.fehler = passwortFehler(passwort.neu, passwort.wiederholung)

  if (passwort.fehler) return

  passwort.laedt = true

  try {
    const antwort = await props.api('/profile/password', {
      method: 'PUT',
      body: JSON.stringify({
        current_password: passwort.aktuell,
        password: passwort.neu,
        password_confirmation: passwort.wiederholung,
      }),
    })

    passwort.aktuell = ''
    passwort.neu = ''
    passwort.wiederholung = ''
    passwort.erfolg = antwort.message
  } catch (error) {
    passwort.fehler = error.status === 429
      ? 'Zu viele Versuche. Bitte warte eine Minute.'
      : error.message
  } finally {
    passwort.laedt = false
  }
}

// --- Zwei-Faktor-Anmeldung --------------------------------------

const zweiFaktor = reactive({
  geladen: false,
  aktiv: false,
  seit: null,
  uebrig: 0,
  // Waehrend der Einrichtung: Schluessel und QR-Code
  einrichtung: null,
  code: '',
  // Frisch erzeugte Wiederherstellungscodes - nur einmal sichtbar
  codes: null,
  laedt: false,
  fehler: '',
  erfolg: '',
})

const zweiFaktorCodeFeld = ref(null)

async function zweiFaktorLaden() {
  try {
    const stand = await props.api('/profile/two-factor')

    zweiFaktor.aktiv = stand.enabled
    zweiFaktor.seit = stand.confirmed_at
    zweiFaktor.uebrig = stand.recovery_codes_left
  } catch (error) {
    zweiFaktor.fehler = error.message
  } finally {
    zweiFaktor.geladen = true
  }
}

function zweiFaktorMeldungenLeeren() {
  zweiFaktor.fehler = ''
  zweiFaktor.erfolg = ''
}

// "ABCDEFGH..." -> "ABCD EFGH ..." - leichter abzutippen
function inVierergruppen(text) {
  return String(text).match(/.{1,4}/g).join(' ')
}

async function zweiFaktorEinrichten() {
  zweiFaktorMeldungenLeeren()

  await mitBestaetigung(async () => {
    zweiFaktor.laedt = true

    try {
      const antwort = await props.api('/profile/two-factor', { method: 'POST' })

      zweiFaktor.einrichtung = { secret: antwort.secret, svg: qrSvg(antwort.uri) }
      zweiFaktor.code = ''
    } finally {
      zweiFaktor.laedt = false
    }

    await nextTick()
    zweiFaktorCodeFeld.value?.focus()
  }, (error) => {
    zweiFaktor.fehler = error.message
  })
}

async function zweiFaktorBestaetigen() {
  if (zweiFaktor.laedt) return

  zweiFaktorMeldungenLeeren()
  zweiFaktor.laedt = true

  try {
    const antwort = await props.api('/profile/two-factor/confirm', {
      method: 'POST',
      body: JSON.stringify({ code: zweiFaktor.code.replace(/\s/g, '') }),
    })

    zweiFaktor.einrichtung = null
    zweiFaktor.codes = antwort.recovery_codes
    zweiFaktor.aktiv = true
    zweiFaktor.seit = new Date().toISOString()
    zweiFaktor.uebrig = antwort.recovery_codes.length

    emit('user-updated', antwort.user)
  } catch (error) {
    zweiFaktor.fehler = error.status === 429 ? 'Zu viele Versuche. Bitte warte eine Minute.' : error.message
    zweiFaktor.code = ''
  } finally {
    zweiFaktor.laedt = false
  }
}

function zweiFaktorAbbrechen() {
  zweiFaktor.einrichtung = null
  zweiFaktor.code = ''
  zweiFaktorMeldungenLeeren()
}

async function neueWiederherstellungscodes() {
  if (!confirm('Neue Wiederherstellungscodes erzeugen? Die bisherigen werden damit ungültig.')) return

  zweiFaktorMeldungenLeeren()

  await mitBestaetigung(async () => {
    zweiFaktor.laedt = true

    try {
      const antwort = await props.api('/profile/two-factor/recovery-codes', { method: 'POST' })

      zweiFaktor.codes = antwort.recovery_codes
      zweiFaktor.uebrig = antwort.recovery_codes.length
    } finally {
      zweiFaktor.laedt = false
    }
  }, (error) => {
    zweiFaktor.fehler = error.message
  })
}

async function zweiFaktorAbschalten() {
  if (!confirm('Zwei-Faktor-Anmeldung abschalten? Danach genügt wieder das Passwort allein.')) return

  zweiFaktorMeldungenLeeren()

  await mitBestaetigung(async () => {
    zweiFaktor.laedt = true

    try {
      const antwort = await props.api('/profile/two-factor', { method: 'DELETE' })

      Object.assign(zweiFaktor, { aktiv: false, seit: null, uebrig: 0, codes: null })
      zweiFaktor.erfolg = 'Zwei-Faktor-Anmeldung abgeschaltet.'

      emit('user-updated', antwort.user)
    } finally {
      zweiFaktor.laedt = false
    }
  }, (error) => {
    zweiFaktor.fehler = error.message
  })
}

function codesAlsText() {
  return [
    'RackView – Wiederherstellungscodes',
    `Konto: ${props.user.email}`,
    `Erstellt: ${datum(new Date().toISOString())}`,
    '',
    ...zweiFaktor.codes,
    '',
    'Jeder Code gilt einmal. Sicher aufbewahren, z. B. im Passwortmanager.',
  ].join('\n')
}

function codesHerunterladen() {
  const url = URL.createObjectURL(new Blob([codesAlsText()], { type: 'text/plain;charset=utf-8' }))
  const link = document.createElement('a')

  link.href = url
  link.download = 'rackview-wiederherstellungscodes.txt'
  document.body.appendChild(link)
  link.click()
  link.remove()

  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

async function codesKopieren() {
  zweiFaktorMeldungenLeeren()

  // Die Zwischenablage gibt der Browser nur ueber HTTPS (oder localhost) frei
  try {
    await navigator.clipboard.writeText(codesAlsText())
    zweiFaktor.erfolg = 'Codes in die Zwischenablage kopiert.'
  } catch {
    zweiFaktor.fehler = window.isSecureContext
      ? 'Kopieren hat nicht geklappt – bitte als Datei speichern.'
      : 'Kopieren erlaubt der Browser nur über HTTPS – bitte als Datei speichern.'
  }
}

// --- Eigenes Konto loeschen -------------------------------------

const loeschen = reactive({ passwort: '', laedt: false, fehler: '' })

async function eigenesKontoLoeschen() {
  if (loeschen.laedt) return

  loeschen.fehler = ''

  if (!confirm('Dein Konto und alle deine Racks, Geräte, Ports und Verbindungen endgültig löschen?\n\nDas lässt sich nicht rückgängig machen.')) return

  loeschen.laedt = true

  try {
    await props.api('/profile', {
      method: 'DELETE',
      body: JSON.stringify({ password: loeschen.passwort }),
    })

    emit('account-deleted')
  } catch (error) {
    loeschen.fehler = error.status === 429
      ? 'Zu viele Versuche. Bitte warte eine Minute.'
      : error.message
  } finally {
    loeschen.laedt = false
    loeschen.passwort = ''
  }
}

// --- Passwortbestaetigung -------------------------------------
//
// Konten anlegen, aendern und loeschen verlangt der Server nur nach
// bestaetigtem Passwort (Antwort 423). Dann hier nachfragen und die
// Aktion danach automatisch wiederholen.

const bestaetigungOffen = ref(false)
const bestaetigung = reactive({ passwort: '', laedt: false, fehler: '' })
const bestaetigungFeld = ref(null)
let ausstehend = null

async function mitBestaetigung(aktion, beiFehler) {
  try {
    await aktion()
  } catch (error) {
    if (error.status !== 423) {
      beiFehler(error)
      return
    }

    ausstehend = { aktion, beiFehler }
    bestaetigung.passwort = ''
    bestaetigung.fehler = ''
    bestaetigungOffen.value = true

    await nextTick()
    bestaetigungFeld.value?.focus()
  }
}

async function bestaetigen() {
  if (bestaetigung.laedt || !ausstehend) return

  bestaetigung.laedt = true
  bestaetigung.fehler = ''

  try {
    await props.api('/profile/confirm-password', {
      method: 'POST',
      body: JSON.stringify({ password: bestaetigung.passwort }),
    })
  } catch (error) {
    bestaetigung.fehler = error.status === 429
      ? 'Zu viele Versuche. Bitte warte eine Minute.'
      : error.message
    return
  } finally {
    bestaetigung.laedt = false
  }

  const { aktion, beiFehler } = ausstehend

  ausstehend = null
  bestaetigung.passwort = ''
  bestaetigungOffen.value = false

  await mitBestaetigung(aktion, beiFehler)
}

function bestaetigungAbbrechen() {
  ausstehend = null
  bestaetigung.passwort = ''
  bestaetigungOffen.value = false
}

// --- Konten ---------------------------------------------------

const konten = ref([])
const kontenLaedt = ref(true)
const kontenFehler = ref('')
const kontenErfolg = ref('')

async function kontenLaden() {
  kontenLaedt.value = true

  try {
    konten.value = await props.api('/users')
    kontenFehler.value = ''
  } catch (error) {
    kontenFehler.value = error.message
  } finally {
    kontenLaedt.value = false
  }
}

// Die Kontoverwaltung gibt es nur fuer Admins
onMounted(() => {
  zweiFaktorLaden()
  if (props.user.is_admin) kontenLaden()
})

// null = geschlossen; konto null = neues Konto
const editor = ref(null)

function kontoNeu() {
  kontenErfolg.value = ''
  editor.value = { konto: null, name: '', email: '', admin: false, passwort: '', wiederholung: '', laedt: false, fehler: '' }
}

function kontoBearbeiten(konto) {
  kontenErfolg.value = ''
  editor.value = { konto, name: konto.name, email: konto.email, admin: konto.is_admin, zweiFaktorAus: false, passwort: '', wiederholung: '', laedt: false, fehler: '' }
}

function editorSchliessen() {
  bestaetigungAbbrechen()
  editor.value = null
}

async function kontoSpeichern() {
  const eintrag = editor.value

  if (!eintrag || eintrag.laedt) return

  const neu = !eintrag.konto

  eintrag.fehler = neu || eintrag.passwort
    ? passwortFehler(eintrag.passwort, eintrag.wiederholung)
    : ''

  if (eintrag.fehler) return

  const daten = { name: eintrag.name.trim(), email: eintrag.email.trim(), is_admin: eintrag.admin }

  if (eintrag.passwort) {
    daten.password = eintrag.passwort
    daten.password_confirmation = eintrag.wiederholung
  }

  if (eintrag.zweiFaktorAus) daten.disable_two_factor = true

  await mitBestaetigung(async () => {
    eintrag.laedt = true

    try {
      await props.api(neu ? '/users' : `/users/${eintrag.konto.id}`, {
        method: neu ? 'POST' : 'PUT',
        body: JSON.stringify(daten),
      })
    } finally {
      eintrag.laedt = false
    }

    if (editor.value === eintrag) editor.value = null

    if (neu) {
      kontenErfolg.value = `Konto für ${daten.name} angelegt.`
    } else if (daten.password) {
      kontenErfolg.value = `Konto gespeichert. ${daten.name} wurde auf allen Geräten abgemeldet.`
    } else if (daten.disable_two_factor) {
      kontenErfolg.value = `Konto gespeichert. Die Zwei-Faktor-Anmeldung von ${daten.name} ist zurückgesetzt.`
    } else {
      kontenErfolg.value = 'Konto gespeichert.'
    }

    await kontenLaden()
  }, (error) => {
    eintrag.fehler = error.message
  })
}

async function kontoLoeschen(konto) {
  if (!confirm(`Konto „${konto.name}" (${konto.email}) wirklich löschen?\n\nAlle Racks, Geräte und Verbindungen dieses Kontos werden mit gelöscht. Die Person wird sofort abgemeldet.`)) return

  kontenErfolg.value = ''
  kontenFehler.value = ''

  const eintrag = editor.value

  await mitBestaetigung(async () => {
    if (eintrag) eintrag.laedt = true

    try {
      await props.api(`/users/${konto.id}`, { method: 'DELETE' })
    } finally {
      if (eintrag) eintrag.laedt = false
    }

    if (editor.value?.konto?.id === konto.id) editor.value = null

    kontenErfolg.value = `Konto ${konto.name} gelöscht.`
    await kontenLaden()
  }, (error) => {
    if (eintrag && editor.value === eintrag) {
      eintrag.fehler = error.message
    } else {
      kontenFehler.value = error.message
    }
  })
}
</script>

<template>
  <div class="settings-view">
    <div class="settings-row">
      <section class="panel settings-card">
        <div class="settings-heading">
          <div>
            <span class="settings-kicker">Profil</span>
            <h2>Mein Profil</h2>
            <p>Dein Anzeigename und die E-Mail-Adresse, mit der du dich anmeldest.</p>
          </div>
        </div>

        <form class="settings-form" @submit.prevent="profilSpeichern">
          <label>
            Name
            <input v-model="profil.name" type="text" autocomplete="name" maxlength="255" required />
          </label>

          <label>
            E-Mail-Adresse
            <input v-model="profil.email" type="email" autocomplete="email" maxlength="255" required />
          </label>

          <div v-if="profil.fehler" class="settings-message error" role="alert">{{ profil.fehler }}</div>
          <div v-else-if="profil.erfolg" class="settings-message success" role="status">{{ profil.erfolg }}</div>

          <div class="settings-actions">
            <button
              type="button"
              class="settings-button"
              :disabled="!profilGeaendert || profil.laedt"
              @click="profilZuruecksetzen"
            >
              Verwerfen
            </button>

            <button type="submit" class="settings-button primary" :disabled="!profilGeaendert || profil.laedt">
              {{ profil.laedt ? 'Wird gespeichert …' : 'Speichern' }}
            </button>
          </div>
        </form>
      </section>

      <section class="panel settings-card">
        <div class="settings-heading">
          <div>
            <span class="settings-kicker">Sicherheit</span>
            <h2>{{ user.via_oidc ? 'Passwort' : 'Passwort ändern' }}</h2>
            <p v-if="user.via_oidc">
              Dieses Konto meldet sich über den Anmeldedienst an und hat
              hier kein Passwort. Eines wird nur gebraucht, wenn der
              Dienst einmal nicht erreichbar ist – setzen lässt es sich
              dann auf der Konsole mit
              <code>php artisan rackview:user</code>.
            </p>
            <p v-else>Mindestens 10 Zeichen. Andere Geräte, auf denen du angemeldet bist, werden danach abgemeldet.</p>
          </div>
        </div>

        <form v-if="!user.via_oidc" class="settings-form" @submit.prevent="passwortAendern">
          <!-- Fuer Passwortmanager: zu welchem Konto das Passwort gehoert -->
          <input type="text" name="username" :value="user.email" autocomplete="username" readonly hidden />

          <label>
            Aktuelles Passwort
            <input v-model="passwort.aktuell" type="password" autocomplete="current-password" required />
          </label>

          <label>
            Neues Passwort
            <input v-model="passwort.neu" type="password" autocomplete="new-password" minlength="10" required />
          </label>

          <label>
            Neues Passwort wiederholen
            <input v-model="passwort.wiederholung" type="password" autocomplete="new-password" minlength="10" required />
          </label>

          <div v-if="passwort.fehler" class="settings-message error" role="alert">{{ passwort.fehler }}</div>
          <div v-else-if="passwort.erfolg" class="settings-message success" role="status">{{ passwort.erfolg }}</div>

          <div class="settings-actions">
            <button type="submit" class="settings-button primary" :disabled="passwort.laedt">
              {{ passwort.laedt ? 'Wird geändert …' : 'Passwort ändern' }}
            </button>
          </div>
        </form>
      </section>
    </div>

    <section class="panel settings-card">
      <div class="settings-heading">
        <div>
          <span class="settings-kicker">Darstellung</span>
          <h2>Helle oder dunkle Ansicht</h2>
          <p>
            Gilt für diesen Browser. „Automatisch“ folgt der Einstellung deines
            Geräts. Umschalten geht auch jederzeit über ☾ oben rechts.
          </p>
        </div>
      </div>

      <div class="settings-theme" role="group" aria-label="Ansicht wählen">
        <button
          v-for="ansicht in ANSICHTEN"
          :key="ansicht.wert"
          type="button"
          :class="{ aktiv: theme === ansicht.wert }"
          :aria-pressed="theme === ansicht.wert"
          @click="emit('theme', ansicht.wert)"
        >
          <strong>{{ ansicht.label }}</strong>
          <small v-if="ansicht.hinweis">{{ ansicht.hinweis }}</small>
        </button>
      </div>
    </section>

    <section class="panel settings-card">
      <div class="settings-heading">
        <div>
          <span class="settings-kicker">Sicherheit</span>
          <h2>
            Zwei-Faktor-Anmeldung
            <span v-if="zweiFaktor.aktiv" class="settings-badge aktiv">Aktiv</span>
          </h2>
          <p>
            Zusätzlich zum Passwort fragt RackView bei der Anmeldung einen Code aus
            einer Authenticator-App ab – etwa Google Authenticator, Microsoft
            Authenticator, 1Password oder Aegis.
          </p>
        </div>
      </div>

      <div v-if="!zweiFaktor.geladen" class="settings-empty">Wird geladen …</div>

      <!-- Frisch erzeugte Wiederherstellungscodes: nur jetzt sichtbar -->
      <div v-else-if="zweiFaktor.codes" class="settings-codes">
        <strong>Deine Wiederherstellungscodes</strong>
        <p class="settings-hint">
          Mit einem dieser Codes kommst du in dein Konto, falls das Handy weg ist.
          Jeder gilt einmal. Sie werden nur jetzt angezeigt – speichere sie zum
          Beispiel in deinem Passwortmanager.
        </p>

        <ol class="settings-code-liste">
          <li v-for="eintrag in zweiFaktor.codes" :key="eintrag"><code>{{ eintrag }}</code></li>
        </ol>

        <div class="settings-actions">
          <button type="button" class="settings-button" @click="codesKopieren">Kopieren</button>
          <button type="button" class="settings-button" @click="codesHerunterladen">Als Datei speichern</button>
          <button type="button" class="settings-button primary" @click="zweiFaktor.codes = null">
            Ich habe sie gesichert
          </button>
        </div>
      </div>

      <form v-else-if="zweiFaktor.einrichtung" class="settings-2fa-setup" @submit.prevent="zweiFaktorBestaetigen">
        <!-- SVG aus lib/qr.js: nur selbst erzeugte Zahlen, kein fremdes HTML -->
        <div class="settings-qr" v-html="zweiFaktor.einrichtung.svg"></div>

        <div class="settings-2fa-steps">
          <ol>
            <li>In der Authenticator-App ein neues Konto hinzufügen.</li>
            <li>
              Den QR-Code scannen – oder den Schlüssel von Hand eingeben:
              <code class="settings-secret">{{ inVierergruppen(zweiFaktor.einrichtung.secret) }}</code>
            </li>
            <li>Den 6-stelligen Code aus der App hier eintragen:</li>
          </ol>

          <input
            ref="zweiFaktorCodeFeld"
            v-model="zweiFaktor.code"
            class="settings-code-input"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="7"
            placeholder="123456"
            aria-label="Code aus der App"
            required
          />

          <div class="settings-actions">
            <button type="button" class="settings-button" @click="zweiFaktorAbbrechen">Abbrechen</button>
            <button type="submit" class="settings-button primary" :disabled="zweiFaktor.laedt">
              {{ zweiFaktor.laedt ? 'Wird geprüft …' : 'Aktivieren' }}
            </button>
          </div>
        </div>
      </form>

      <div v-else-if="zweiFaktor.aktiv" class="settings-2fa-status">
        <p>
          Aktiv seit {{ datum(zweiFaktor.seit) }} ·
          <span :class="{ 'settings-warn': zweiFaktor.uebrig <= 2 }">
            {{ zweiFaktor.uebrig }} von 8 Wiederherstellungscodes übrig
          </span>
        </p>

        <div class="settings-actions">
          <button type="button" class="settings-button danger settings-actions-start" :disabled="zweiFaktor.laedt" @click="zweiFaktorAbschalten">
            Abschalten …
          </button>
          <button type="button" class="settings-button" :disabled="zweiFaktor.laedt" @click="neueWiederherstellungscodes">
            Neue Wiederherstellungscodes
          </button>
        </div>
      </div>

      <div v-else class="settings-actions">
        <button type="button" class="settings-button primary" :disabled="zweiFaktor.laedt" @click="zweiFaktorEinrichten">
          Einrichten
        </button>
      </div>

      <div v-if="zweiFaktor.fehler" class="settings-message error" role="alert">{{ zweiFaktor.fehler }}</div>
      <div v-else-if="zweiFaktor.erfolg" class="settings-message success" role="status">{{ zweiFaktor.erfolg }}</div>
    </section>

    <section v-if="user.is_admin" class="panel settings-card">
      <div class="settings-heading">
        <div>
          <span class="settings-kicker">Verwaltung · Admin</span>
          <h2>Konten</h2>
          <p>
            Jedes Konto hat seinen eigenen, getrennten Bereich. Als Admin legst du
            Konten an, setzt Passwörter zurück und löschst Konten – deren Racks
            und Geräte siehst du nicht.
          </p>
        </div>

        <button type="button" class="settings-button primary" :disabled="editor !== null" @click="kontoNeu">
          + Konto anlegen
        </button>
      </div>

      <form v-if="editor" class="settings-editor" @submit.prevent="kontoSpeichern">
        <h3>{{ editor.konto ? `${editor.konto.name} bearbeiten` : 'Neues Konto' }}</h3>

        <div class="settings-grid">
          <label>
            Name
            <input v-model="editor.name" type="text" autocomplete="off" maxlength="255" required />
          </label>

          <label>
            E-Mail-Adresse
            <input v-model="editor.email" type="email" autocomplete="off" maxlength="255" required />
          </label>

          <label>
            {{ editor.konto ? 'Neues Passwort' : 'Passwort' }}
            <input
              v-model="editor.passwort"
              type="password"
              autocomplete="new-password"
              :required="!editor.konto"
              :placeholder="editor.konto ? 'Leer lassen = unverändert' : 'Mindestens 10 Zeichen'"
            />
          </label>

          <label>
            Passwort wiederholen
            <input
              v-model="editor.wiederholung"
              type="password"
              autocomplete="new-password"
              :required="!editor.konto || !!editor.passwort"
            />
          </label>
        </div>

        <label v-if="editor.konto && editor.konto.two_factor" class="settings-check">
          <input v-model="editor.zweiFaktorAus" type="checkbox" />
          <span>
            <strong>Zwei-Faktor-Anmeldung zurücksetzen</strong>
            <small>Falls das Handy weg ist: Anmeldung dann nur mit Passwort, 2FA lässt sich neu einrichten.</small>
          </span>
        </label>

        <label class="settings-check">
          <input v-model="editor.admin" type="checkbox" />
          <span>
            <strong>Admin</strong>
            <small>Darf Konten anlegen, zurücksetzen und löschen.</small>
          </span>
        </label>

        <p class="settings-hint">
          <template v-if="editor.konto">
            Ein neues Passwort meldet die Person auf allen Geräten ab.
          </template>
          <template v-else>
            Gib das Passwort auf sicherem Weg weiter. Die Person kann es danach
            selbst unter „Passwort ändern" ersetzen.
          </template>
        </p>

        <div v-if="editor.fehler" class="settings-message error" role="alert">{{ editor.fehler }}</div>

        <div class="settings-actions">
          <button
            v-if="editor.konto"
            type="button"
            class="settings-button danger settings-actions-start"
            :disabled="editor.laedt || bestaetigungOffen"
            @click="kontoLoeschen(editor.konto)"
          >
            Konto löschen …
          </button>

          <button type="button" class="settings-button" @click="editorSchliessen">Abbrechen</button>

          <button type="submit" class="settings-button primary" :disabled="editor.laedt || bestaetigungOffen">
            {{ editor.laedt ? 'Wird gespeichert …' : editor.konto ? 'Speichern' : 'Konto anlegen' }}
          </button>
        </div>
      </form>

      <div v-if="kontenFehler" class="settings-message error" role="alert">{{ kontenFehler }}</div>
      <div v-else-if="kontenErfolg" class="settings-message success" role="status">{{ kontenErfolg }}</div>

      <div v-if="kontenLaedt && !konten.length" class="settings-empty">Konten werden geladen …</div>

      <ul v-else class="settings-accounts">
        <li
          v-for="konto in konten"
          :key="konto.id"
          :class="{ editing: editor && editor.konto && editor.konto.id === konto.id }"
        >
          <span class="settings-avatar" aria-hidden="true">{{ initialen(konto.name) }}</span>

          <span class="settings-account-info">
            <strong>
              {{ konto.name }}
              <span v-if="konto.id === user.id" class="settings-badge">Du</span>
              <span v-if="konto.is_admin" class="settings-badge admin">Admin</span>
              <span v-if="konto.two_factor" class="settings-badge aktiv">2FA</span>
            </strong>
            <small>{{ konto.email }}</small>
          </span>

          <span class="settings-account-meta">
            <small>
              {{ konto.racks_count }} {{ konto.racks_count === 1 ? 'Rack' : 'Racks' }}
              · angelegt am {{ datum(konto.created_at) }}
            </small>
            <small>Zuletzt aktiv: {{ zuletztAktiv(konto.last_active_at) }}</small>
          </span>

          <button
            v-if="konto.id !== user.id"
            type="button"
            class="settings-button"
            :disabled="editor !== null"
            @click="kontoBearbeiten(konto)"
          >
            Bearbeiten
          </button>

          <!-- Das eigene Konto wird oben unter Profil und Passwort bearbeitet -->
          <span v-else class="settings-account-self">Oben bearbeiten</span>
        </li>
      </ul>
    </section>

    <section class="panel settings-card settings-danger">
      <div class="settings-heading">
        <div>
          <span class="settings-kicker">Gefahrenzone</span>
          <h2>Konto löschen</h2>
          <p>
            Löscht dein Konto mit allen Racks, Geräten, Ports, Verbindungen und
            Fotos. Das lässt sich nicht rückgängig machen – sichere vorher bei
            Bedarf unter Export deine Daten.
          </p>
        </div>
      </div>

      <form class="settings-danger-form" @submit.prevent="eigenesKontoLoeschen">
        <input type="text" name="username" :value="user.email" autocomplete="username" readonly hidden />

        <input
          v-model="loeschen.passwort"
          type="password"
          autocomplete="current-password"
          aria-label="Dein Passwort zur Bestätigung"
          placeholder="Dein Passwort zur Bestätigung"
          required
        />

        <button type="submit" class="settings-button danger" :disabled="loeschen.laedt || !loeschen.passwort">
          {{ loeschen.laedt ? 'Wird gelöscht …' : 'Konto endgültig löschen' }}
        </button>

        <div v-if="loeschen.fehler" class="settings-message error" role="alert">{{ loeschen.fehler }}</div>
      </form>
    </section>

    <!-- Passwort bestaetigen: Konten verwalten, 2FA einrichten/abschalten -->
    <div
      v-if="bestaetigungOffen"
      class="modal-backdrop"
      @click.self="bestaetigungAbbrechen"
      @keydown.esc="bestaetigungAbbrechen"
    >
      <div class="modal modal-standard" role="dialog" aria-modal="true" aria-labelledby="bestaetigung-titel">
        <div class="modal-header">
          <div>
            <h2 id="bestaetigung-titel">Passwort bestätigen</h2>
            <p class="modal-subtitle">
              Für diese Änderung fragt RackView dein Passwort ab – danach drei Stunden lang nicht mehr.
            </p>
          </div>

          <button type="button" class="modal-close" aria-label="Schließen" @click="bestaetigungAbbrechen">×</button>
        </div>

        <form @submit.prevent="bestaetigen">
          <input type="text" name="username" :value="user.email" autocomplete="username" readonly hidden />

          <label>
            Dein Passwort
            <input
              ref="bestaetigungFeld"
              v-model="bestaetigung.passwort"
              type="password"
              autocomplete="current-password"
              required
            />
          </label>

          <div v-if="bestaetigung.fehler" class="settings-message error" role="alert">{{ bestaetigung.fehler }}</div>

          <div class="modal-footer">
            <button type="button" class="settings-button" @click="bestaetigungAbbrechen">Abbrechen</button>
            <button type="submit" class="settings-button primary" :disabled="bestaetigung.laedt">
              {{ bestaetigung.laedt ? 'Wird geprüft …' : 'Bestätigen' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.settings-view {
  display: flex;
  flex-direction: column;
  gap: var(--rv-page-gap, 24px);
  min-width: 0;
}

.settings-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--rv-page-gap, 24px);
}

.settings-card {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

/* --- Kopf je Karte ------------------------------------------ */

.settings-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.settings-kicker {
  display: block;
  margin-bottom: 7px;

  color: var(--rv-blue, var(--t-blau-53));
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.settings-heading p {
  margin: 6px 0 0;
  font-size: 13px;
  line-height: 1.5;
}

/* --- Formulare ---------------------------------------------- */

.settings-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.settings-form label,
.settings-editor label {
  display: flex;
  flex-direction: column;
  gap: 7px;

  color: var(--t-ton-40);
  font-size: 12px;
  font-weight: 700;
}

.settings-view input {
  box-sizing: border-box;
  width: 100%;
  min-height: 42px;
  padding: 10px 13px;

  border: 1px solid var(--f-ton-91-5);
  border-radius: 11px;

  color: var(--t-ton-15);
  background: var(--f-grau-100);

  font: inherit;
  font-size: 13px;
  font-weight: 500;

  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.settings-view input::placeholder {
  color: var(--t-ton-70);
}

.settings-view input:focus {
  outline: none;
  border-color: var(--f-blau-78-2);
  box-shadow: 0 0 0 4px var(--f-blau-60);
}

.settings-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
}

.settings-actions-start {
  margin-right: auto;
}

/* --- Knoepfe ------------------------------------------------ */
/* font-size mit !important: ".dashboard-layout button" setzt sonst
   per "font: inherit !important" die Schriftgroesse zurueck. */

.settings-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  min-height: 38px;
  padding: 0 14px;

  border: 1px solid var(--f-ton-91-2);
  border-radius: 10px;

  color: var(--t-ton-40);
  background: var(--f-grau-100);

  font-size: 13px !important;
  font-weight: 700;
  white-space: nowrap;
}

.settings-button:hover:not(:disabled) {
  border-color: var(--f-blau-86-2);
  color: var(--t-blau-53);
}

.settings-button.primary {
  border-color: transparent;
  color: var(--t-grau-100);
  background: var(--rv-blue, var(--f-blau-53));
  box-shadow: 0 5px 14px var(--f-blau-53-2);
}

.settings-button.primary:hover:not(:disabled) {
  color: var(--t-grau-100);
  background: var(--rv-blue-dark, var(--f-blau-48));
}

.settings-button.danger {
  border-color: var(--f-rot-89);
  color: var(--t-rot-42);
  background: var(--f-ton-98-4);
}

.settings-button.danger:hover:not(:disabled) {
  border-color: var(--f-rot-82);
  color: var(--t-rot-35);
  background: var(--f-ton-94);
}

.settings-button:disabled {
  opacity: 0.5;
  cursor: default !important;
}

/* --- Meldungen ---------------------------------------------- */

.settings-message {
  padding: 10px 12px;

  border-radius: 9px;

  font-size: 12px;
  font-weight: 600;
  line-height: 1.45;
}

.settings-message.error {
  color: var(--t-rot-42);
  background: var(--f-ton-97-3);
}

.settings-message.success {
  color: var(--t-gruen-29);
  background: var(--f-ton-97-4);
}

.settings-hint {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
}

.settings-empty {
  color: var(--t-ton-61-2);
  font-size: 13px;
}

/* --- Konto anlegen / bearbeiten ----------------------------- */

.settings-editor {
  display: flex;
  flex-direction: column;
  gap: 14px;

  padding: 18px;

  border: 1px solid var(--f-ton-92-4);
  border-radius: 14px;
  background: var(--f-ton-99);
}

.settings-editor h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 800;
}

.settings-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px 16px;
}

/* --- Kontenliste -------------------------------------------- */

.settings-accounts {
  display: flex;
  flex-direction: column;
  gap: 8px;

  margin: 0;
  padding: 0;

  list-style: none;
}

.settings-accounts li {
  display: grid;
  grid-template-columns: 40px minmax(0, 1.4fr) minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;

  padding: 12px 14px;

  border: 1px solid var(--f-ton-95);
  border-radius: 12px;
}

.settings-accounts li.editing {
  border-color: var(--f-blau-86-2);
  background: var(--f-ton-99);
}

.settings-avatar {
  display: grid;
  place-items: center;

  width: 40px;
  height: 40px;

  border-radius: 12px;
  background: var(--f-ton-96);

  color: var(--t-blau-53);
  font-size: 13px;
  font-weight: 800;
}

.settings-account-info,
.settings-account-meta {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.settings-account-info strong {
  display: flex;
  align-items: center;
  gap: 8px;

  color: var(--t-ton-15);
  font-size: 14px;
  font-weight: 750;
}

.settings-account-info small,
.settings-account-meta small {
  overflow: hidden;

  color: var(--t-ton-61-2);
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.settings-badge {
  padding: 2px 7px;

  border-radius: 6px;
  background: var(--f-ton-95-5);

  color: var(--t-blau-53);
  font-size: 10.5px;
  font-weight: 800;
}

.settings-account-self {
  color: var(--t-ton-70);
  font-size: 12px;
  white-space: nowrap;
}

@media (max-width: 1100px) {
  .settings-row {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 720px) {
  .settings-heading {
    flex-direction: column;
  }

  .settings-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .settings-accounts li {
    grid-template-columns: 40px minmax(0, 1fr);
  }

  .settings-account-meta,
  .settings-accounts li > .settings-button,
  .settings-account-self {
    grid-column: 2;
  }

  .settings-accounts li > .settings-button {
    justify-self: start;
  }
}

/* --- Admin ---------------------------------------------------- */

.settings-badge.admin {
  background: var(--f-amber-89);
  color: var(--t-amber-31);
}

.settings-check {
  display: flex !important;
  flex-direction: row !important;
  align-items: flex-start;
  gap: 10px !important;

  cursor: pointer;
}

.settings-view .settings-check input {
  width: 16px;
  min-height: 0;
  height: 16px;
  margin: 2px 0 0;
  padding: 0;
  flex-shrink: 0;
}

.settings-check span {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.settings-check strong {
  color: var(--t-ton-15);
  font-size: 13px;
}

.settings-check small {
  color: var(--t-ton-61-2);
  font-size: 12px;
  font-weight: 500;
}

/* --- Konto loeschen ------------------------------------------- */

/* Doppelte Klasse: ".dashboard-layout .panel" setzt den Rahmen global
   mit !important und gleicher Gewichtung */
.settings-view .settings-danger {
  border-color: var(--f-rot-89) !important;
}

.settings-danger .settings-kicker {
  color: var(--t-rot-42);
}

.settings-danger-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 12px;
}

.settings-danger-form input {
  flex: 1 1 240px;
  width: auto;
  max-width: 360px;
}

.settings-danger-form .settings-message {
  flex-basis: 100%;
}

/* --- Helle und dunkle Ansicht --------------------------------- */

.settings-theme {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.settings-theme button {
  display: flex;
  flex-direction: column;
  gap: 2px;

  min-width: 140px;
  padding: 12px 14px;

  border: 1px solid var(--f-ton-91);
  border-radius: 12px;
  background: var(--f-grau-100);

  text-align: left;
  cursor: pointer;
}

.settings-theme button:hover {
  border-color: var(--f-blau-86-2);
}

.settings-theme button.aktiv {
  border-color: var(--f-blau-53);
  background: var(--f-ton-95-5);
  box-shadow: 0 0 0 3px var(--f-blau-53-7);
}

.settings-theme strong {
  color: var(--t-ton-15);
  font-size: 13px !important;
  font-weight: 750;
}

.settings-theme small {
  color: var(--t-ton-61-2);
  font-size: 11.5px !important;
  font-weight: 500;
}

/* --- Zwei-Faktor-Anmeldung ------------------------------------ */

.settings-badge.aktiv {
  background: var(--f-ton-93-2);
  color: var(--t-gruen-24);
}

.settings-heading h2 .settings-badge {
  margin-left: 6px;
  vertical-align: middle;
}

.settings-2fa-setup {
  display: grid;
  grid-template-columns: 200px minmax(0, 1fr);
  align-items: start;
  gap: 24px;
}

.settings-qr {
  padding: 8px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 12px;
  background: var(--f-grau-100);
}

/* v-html-Inhalt bekommt kein scoped-Attribut, daher :deep */
.settings-qr :deep(svg) {
  display: block;
  width: 100%;
  height: auto;
}

.settings-2fa-steps {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.settings-2fa-steps ol {
  display: flex;
  flex-direction: column;
  gap: 8px;

  margin: 0;
  padding-left: 20px;

  color: var(--t-ton-40);
  font-size: 13px;
  line-height: 1.5;
}

.settings-secret {
  display: block;
  margin-top: 6px;
  padding: 8px 10px;

  border-radius: 8px;
  background: #f1f5f9;

  color: #172033;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 13px;
  letter-spacing: 0.04em;
  word-break: break-all;
}

.settings-view input.settings-code-input {
  max-width: 220px;

  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 20px;
  letter-spacing: 0.3em;
  text-align: center;
}

.settings-codes {
  display: flex;
  flex-direction: column;
  gap: 12px;

  padding: 18px;

  border: 1px solid var(--f-gruen-85);
  border-radius: 14px;
  background: var(--f-ton-97-4);
}

.settings-codes > strong {
  color: var(--t-gruen-24);
  font-size: 14px;
}

.settings-code-liste {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 8px 18px;

  margin: 0;
  padding-left: 24px;

  color: #64748b;
  font-size: 12px;
}

.settings-code-liste code {
  color: #172033;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 15px;
  letter-spacing: 0.04em;
}

.settings-2fa-status {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.settings-2fa-status p {
  margin: 0;
  font-size: 13px;
}

.settings-warn {
  color: var(--t-amber-37);
  font-weight: 700;
}

@media (max-width: 720px) {
  .settings-2fa-setup {
    grid-template-columns: minmax(0, 1fr);
  }

  .settings-qr {
    max-width: 220px;
  }
}
</style>

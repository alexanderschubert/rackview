<script setup>
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  // Gibt es ueberhaupt schon ein Konto?
  hasUsers: { type: Boolean, default: true },
  // Darf man sich hier selbst ein Konto anlegen?
  registrationOpen: { type: Boolean, default: false },
  // Passwort stimmte, jetzt fehlt der Code aus der Authenticator-App
  twoFactorPending: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
  // Anmeldung ueber einen OIDC-Anbieter, falls eingerichtet
  oidc: { type: Object, default: () => ({ enabled: false, label: '' }) },
})

const emit = defineEmits(['login', 'register', 'two-factor', 'cancel-two-factor', 'clear-error'])

const modus = ref('login')

// Was angezeigt wird: Anmelden, Registrieren oder der Hinweis auf den
// Befehl (noch kein Konto und Registrierung abgeschaltet)
const ansicht = computed(() => {
  if (props.twoFactorPending) return 'code'
  if (props.hasUsers && modus.value === 'login') return 'login'
  if (props.registrationOpen) return 'register'
  return props.hasUsers ? 'login' : 'hinweis'
})

const name = ref('')
const email = ref('')
const password = ref('')
const wiederholung = ref('')
const remember = ref(true)
const lokalerFehler = ref('')
const erstesFeld = ref(null)

// --- Zweiter Schritt: Code aus der App ---------------------------

const code = ref('')
const wiederherstellungscode = ref('')
const mitWiederherstellung = ref(false)

async function codeArtWechseln() {
  mitWiederherstellung.value = !mitWiederherstellung.value
  code.value = ''
  wiederherstellungscode.value = ''
  emit('clear-error')

  await nextTick()
  erstesFeld.value?.focus()
}

// Sechs Ziffern eingegeben (oder eingefuegt): gleich abschicken
watch(code, (wert) => {
  if (/^\d{6}$/.test(wert.replace(/\s/g, '')) && !props.busy) absenden()
})

// Neuer Anlauf nach einem Fehler: Feld leeren, Fokus zurueck
watch(() => props.error, async (fehler) => {
  if (fehler && ansicht.value === 'code') {
    code.value = ''
    await nextTick()
    erstesFeld.value?.focus()
  }
})

// Codeschritt erscheint (auch nach Neuladen): Cursor gleich ins Feld.
// "autofocus" greift nur beim ersten Laden der Seite.
watch(ansicht, async (neu) => {
  if (neu !== 'code') return
  await nextTick()
  erstesFeld.value?.focus()
}, { immediate: true })

// Zurueck zur Passworteingabe: Das Passwortfeld ist dann leer
watch(() => props.twoFactorPending, (offen) => {
  if (!offen) password.value = ''
})

async function wechsle(ziel) {
  modus.value = ziel
  lokalerFehler.value = ''
  password.value = ''
  wiederholung.value = ''
  emit('clear-error')

  await nextTick()
  erstesFeld.value?.focus()
}

function absenden() {
  if (props.busy) return

  lokalerFehler.value = ''

  if (ansicht.value === 'code') {
    emit('two-factor', mitWiederherstellung.value
      ? { recovery_code: wiederherstellungscode.value.trim() }
      : { code: code.value.replace(/\s/g, '') })
    return
  }

  if (ansicht.value === 'login') {
    emit('login', { email: email.value.trim(), password: password.value, remember: remember.value })
    return
  }

  // Gleiche Pruefung wie im Backend, spart einen Umweg ueber den Server
  if (password.value.length < 10) {
    lokalerFehler.value = 'Das Passwort muss mindestens 10 Zeichen lang sein.'
    return
  }

  if (password.value !== wiederholung.value) {
    lokalerFehler.value = 'Die beiden Passwörter stimmen nicht überein.'
    return
  }

  emit('register', {
    name: name.value.trim(),
    email: email.value.trim(),
    password: password.value,
    password_confirmation: wiederholung.value,
  })
}
</script>

<template>
  <div class="login-screen">
    <form class="login-card" @submit.prevent="absenden">
      <img src="/rackview-logo.png" alt="RackView" class="login-logo" />

      <template v-if="ansicht === 'code'">
        <h1>Bestätigungscode</h1>
        <p class="login-intro">
          <template v-if="!mitWiederherstellung">
            Gib den 6-stelligen Code aus deiner Authenticator-App ein.
          </template>
          <template v-else>
            Gib einen deiner Wiederherstellungscodes ein. Jeder gilt nur einmal.
          </template>
        </p>

        <label v-if="!mitWiederherstellung">
          Code
          <input
            ref="erstesFeld"
            v-model="code"
            class="login-code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="7"
            placeholder="123456"
            required
            autofocus
          />
        </label>

        <label v-else>
          Wiederherstellungscode
          <input
            ref="erstesFeld"
            v-model="wiederherstellungscode"
            type="text"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            placeholder="xxxxx-xxxxx"
            required
          />
        </label>

        <p v-if="error" class="login-error" role="alert">{{ error }}</p>

        <button type="submit" class="login-button" :disabled="busy">
          {{ busy ? 'Wird geprüft …' : 'Bestätigen' }}
        </button>

        <p class="login-switch">
          <button type="button" @click="codeArtWechseln">
            {{ mitWiederherstellung ? 'Code aus der App verwenden' : 'Handy nicht zur Hand? Wiederherstellungscode verwenden' }}
          </button>
        </p>

        <p class="login-switch">
          <button type="button" @click="emit('cancel-two-factor')">Zurück zur Anmeldung</button>
        </p>
      </template>

      <template v-else-if="ansicht === 'login'">
        <h1>Anmelden</h1>
        <p class="login-intro">Melde dich mit deinem RackView-Konto an.</p>

        <label>
          E-Mail-Adresse
          <input
            ref="erstesFeld"
            v-model="email"
            type="email"
            autocomplete="username"
            required
            autofocus
          />
        </label>

        <label>
          Passwort
          <input
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
          />
        </label>

        <label class="login-remember">
          <input v-model="remember" type="checkbox" />
          Angemeldet bleiben
        </label>

        <p v-if="error" class="login-error" role="alert">{{ error }}</p>

        <button type="submit" class="login-button" :disabled="busy">
          {{ busy ? 'Wird angemeldet …' : 'Anmelden' }}
        </button>

        <template v-if="oidc.enabled">
          <div class="login-oder"><span>oder</span></div>

          <!-- Bewusst ein Verweis und kein fetch: Der Anbieter schickt
               den Browser selbst zurueck, das geht nur als Seitenaufruf. -->
          <a class="login-oidc" href="/api/auth/oidc/redirect">
            {{ oidc.label || 'Mit dem Anbieter anmelden' }}
          </a>
        </template>

        <p v-if="registrationOpen" class="login-switch">
          Noch kein Konto?
          <button type="button" @click="wechsle('register')">Registrieren</button>
        </p>
      </template>

      <template v-else-if="ansicht === 'register'">
        <h1>{{ hasUsers ? 'Konto anlegen' : 'Erstes Konto anlegen' }}</h1>
        <p class="login-intro">
          <template v-if="hasUsers">
            Dein Konto bekommt einen eigenen Bereich. Andere Konten sehen
            deine Racks und Geräte nicht.
          </template>
          <template v-else>
            Dieses Konto wird Admin und übernimmt bereits vorhandene Racks.
          </template>
        </p>

        <label>
          Name
          <input
            ref="erstesFeld"
            v-model="name"
            type="text"
            autocomplete="name"
            maxlength="255"
            required
            autofocus
          />
        </label>

        <label>
          E-Mail-Adresse
          <input v-model="email" type="email" autocomplete="username" maxlength="255" required />
        </label>

        <label>
          Passwort
          <input
            v-model="password"
            type="password"
            autocomplete="new-password"
            minlength="10"
            placeholder="Mindestens 10 Zeichen"
            required
          />
        </label>

        <label>
          Passwort wiederholen
          <input v-model="wiederholung" type="password" autocomplete="new-password" minlength="10" required />
        </label>

        <p v-if="lokalerFehler || error" class="login-error" role="alert">{{ lokalerFehler || error }}</p>

        <button type="submit" class="login-button" :disabled="busy">
          {{ busy ? 'Wird angelegt …' : 'Konto anlegen' }}
        </button>

        <p v-if="hasUsers" class="login-switch">
          Schon ein Konto?
          <button type="button" @click="wechsle('login')">Anmelden</button>
        </p>
      </template>

      <!-- Noch kein Konto und Registrierung abgeschaltet: nur per Befehl -->
      <template v-else>
        <h1>Noch kein Konto</h1>
        <p class="login-intro">
          Die Registrierung ist abgeschaltet. Das erste Konto legst du auf dem
          Server an:
        </p>

        <code class="login-command">docker compose -f docker-compose.prod.yml exec backend php artisan rackview:user deine@adresse.de</code>

        <p class="login-intro">
          Danach diese Seite neu laden und anmelden.
        </p>
      </template>
    </form>
  </div>
</template>

<style scoped>
.login-screen {
  display: grid;
  place-items: center;

  min-height: 100vh;
  padding: 24px;

  background:
    radial-gradient(circle at 20% 10%, var(--f-blau-60-3), transparent 32rem),
    var(--f-grau-97);
}

.login-card {
  display: flex;
  flex-direction: column;
  gap: 14px;

  width: min(100%, 380px);
  padding: 32px 30px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 18px;

  background: var(--f-grau-100);
  box-shadow: 0 20px 50px var(--f-schatten-11-4);
}

.login-logo {
  align-self: center;
  height: 56px;
  margin-bottom: 4px;
}

.login-card h1 {
  margin: 0;

  color: var(--t-ton-11);
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -0.02em;
  text-align: center;
}

.login-intro {
  margin: 0;

  color: var(--t-ton-47-3);
  font-size: 13px;
  line-height: 1.5;
  text-align: center;
}

.login-card label {
  display: flex;
  flex-direction: column;
  gap: 6px;

  color: var(--t-ton-40);
  font-size: 12px;
  font-weight: 700;
}

.login-card input[type='text'],
.login-card input[type='email'],
.login-card input[type='password'] {
  padding: 11px 12px;

  border: 1px solid var(--f-ton-91-3);
  border-radius: 10px;

  color: var(--t-ton-11);
  font: inherit;
  font-size: 14px;
  font-weight: 500;
}

.login-card input:focus {
  border-color: var(--f-blau-77);
  outline: none;
  box-shadow: 0 0 0 3px var(--f-blau-60-4);
}

.login-card .login-remember {
  flex-direction: row;
  align-items: center;
  gap: 8px;

  font-weight: 600;
}

.login-error {
  margin: 0;
  padding: 10px 12px;

  border-radius: 9px;
  background: var(--f-ton-97-3);

  color: var(--t-rot-42);
  font-size: 12px;
  font-weight: 600;
}

.login-button {
  margin-top: 4px;
  padding: 12px;

  border: 0;
  border-radius: 11px;

  color: var(--t-grau-100);
  background: linear-gradient(135deg, var(--f-blau-61), var(--f-blau-53));

  font: inherit;
  font-size: 14px;
  font-weight: 800;

  cursor: pointer;
}

.login-button:disabled {
  opacity: 0.6;
  cursor: progress;
}

.login-command {
  display: block;
  padding: 12px;

  border-radius: 10px;
  background: #0f172a;

  color: #e2e8f0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11.5px;
  line-height: 1.5;
  word-break: break-all;
}

.login-switch {
  margin: 2px 0 0;

  color: var(--t-ton-47-3);
  font-size: 13px;
  text-align: center;
}

.login-switch button {
  padding: 0;

  border: 0;
  background: none;

  color: var(--t-blau-53);
  font: inherit;
  font-weight: 700;

  cursor: pointer;
}

.login-switch button:hover {
  text-decoration: underline;
}

/* Einmalcode: gross und mit Abstand zwischen den Ziffern */
.login-card input.login-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 22px;
  letter-spacing: 0.3em;
  text-align: center;
}

/* --- Anmeldung ueber einen Anbieter ---------------------------- */

.login-oder {
  display: flex;
  align-items: center;
  gap: 10px;

  margin: 4px 0;

  color: var(--t-ton-47-3);
  font-size: 12px;
}

.login-oder::before,
.login-oder::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--f-ton-91-2);
}

.login-oidc {
  display: block;

  padding: 12px 16px;

  border: 1px solid var(--f-ton-91-2);
  border-radius: 12px;

  color: var(--t-ton-23);
  background: var(--f-grau-100);

  font-size: 14px;
  font-weight: 700;
  text-align: center;
  text-decoration: none;
}

.login-oidc:hover {
  border-color: var(--f-blau-53);
  color: var(--t-blau-53);
}

</style>

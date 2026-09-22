<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import DeviceFaceplate from './components/DeviceFaceplate.vue'
import PortSymbol from './components/PortSymbol.vue'
import OutletSymbol from './components/OutletSymbol.vue'
import NetworkPlan from './components/NetworkPlan.vue'
import DeviceDetailsPanel from './components/DeviceDetailsPanel.vue'
import IpView from './components/IpView.vue'
import VlanView from './components/VlanView.vue'
import ConnectionsView from './components/ConnectionsView.vue'
import DevicesView from './components/DevicesView.vue'
import RacksOverview from './components/RacksOverview.vue'
import LocationsView from './components/LocationsView.vue'
import ExportView from './components/ExportView.vue'
import LabelsView from './components/LabelsView.vue'
import LoginView from './components/LoginView.vue'
import SettingsView from './components/SettingsView.vue'
import {
  addYears,
  deviceTypes,
  formatDate,
  getDeviceIcon,
  getDeviceTypeLabel,
  getMountSide,
  getPoeLabel,
  getWarrantyInfo,
  isOnSide,
  mountSides,
  OUTLET_CAPABLE_TYPES,
  POE_CAPABLE_TYPES,
  poeTypes,
  sidesCollide,
} from './lib/deviceMeta.js'
import {
  isUplinkPort,
  portNumber,
  portState,
  portStateLabel,
  portTypeLabel,
} from './lib/ports.js'

const API_BASE = '/api'

function getDeviceSubtitle(device) {
  const hersteller = [device?.manufacturer, device?.model]
    .filter(Boolean)
    .join(' ')

  return hersteller || getDeviceTypeLabel(device?.device_type)
}

function getRackHeight(rack) {
  const height = Number(rack?.height_units ?? 18)

  return Number.isFinite(height) && height > 0 ? height : 18
}

function getDeviceHeight(device) {
  const height = Number(device?.height_units ?? 1)

  return Number.isFinite(height) && height > 0 ? height : 1
}

// Welche Seite der Blende man sieht: Nur Geraete voller Tiefe zeigen
// in der Rueckansicht ihre Rueckseite. Ein nur hinten eingebautes
// Geraet (z. B. eine Steckdosenleiste) hat seine Front nach hinten.
function blendenSeite(device) {
  return getMountSide(device) === 'full' ? rackSide.value : 'front'
}

// Belegte Hoeheneinheiten zaehlen, nicht Geraetehoehen summieren:
// Ein Geraet vorne und eines hinten teilen sich dieselbe HE.
function belegteEinheiten(liste) {
  const einheiten = new Set()

  for (const device of liste || []) {
    const start = Number(device.start_unit)

    for (let u = start; u < start + getDeviceHeight(device); u += 1) {
      einheiten.add(u)
    }
  }

  return einheiten.size
}

function getUsedRackUnits(rack) {
  return belegteEinheiten(rack?.devices || [])
}

const racks = ref([])

// Standorte ausserhalb der Racks - Flur, Dachboden, Carport. Ein
// Geraet steht entweder in einem Rack oder an einem Standort.
const locations = ref([])
const selectedRack = ref(null)
const devices = ref([])
// Welche Seite des Racks zu sehen ist: 'front' oder 'rear'
const rackSide = ref('front')
const allDevices = ref([])
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const showRackForm = ref(false)
const showLocationForm = ref(false)
const showDeviceForm = ref(false)
const showDeviceDetails = ref(false)

const editingRack = ref(null)
const editingLocation = ref(null)
const editingDevice = ref(null)
const selectedDevice = ref(null)

const locationForm = ref({
  name: '',
  description: '',
})

const rackForm = ref({
  name: '',
  location: '',
  height_units: 18,
  description: '',
})

const deviceForm = ref({
  rack_id: null,
  location_id: null,
  name: '',
  manufacturer: '',
  model: '',
  device_type: 'other',
  status: 'active',
  height_units: 1,
  start_unit: 1,
  mount_side: 'full',
  serial_number: '',
  ip_address: '',
  mac_address: '',
  purchase_date: '',
  warranty_until: '',
    vlan: '',
    switch_port: '',
    uplink_port: '',
    network_ports: '',
    poe_ports: '',
    poe_type: 'poe+',
    outlet_count: '',
    notes: '',
  description: '',
})

// Für die Liste neben dem Rack: oberste Höheneinheit zuerst,
// damit die Reihenfolge der Rack-Ansicht entspricht.
const devicesTopDown = computed(() => {
  return [...devices.value].sort(
    (a, b) => Number(b.start_unit) - Number(a.start_unit)
  )
})

const usedUnits = computed(() => belegteEinheiten(devices.value))

// --- Seitenkopf ---------------------------------------------

// Status des Racks, abgeleitet aus seinen Geraeten statt eines
// festen "Online", das nichts ueber den Zustand aussagt.
const rackStatus = computed(() => {
  const liste = devices.value || []

  if (!liste.length) {
    return { tone: 'neutral', label: 'Keine Geräte' }
  }

  const nichtAktiv = liste.filter((device) => (device.status || 'active') !== 'active')

  if (!nichtAktiv.length) {
    return { tone: 'ok', label: 'Alle Geräte aktiv' }
  }

  return {
    tone: 'warn',
    label: `${nichtAktiv.length} von ${liste.length} nicht aktiv`,
  }
})

const pageSubtitle = computed(() => {
  if (!selectedRack.value) {
    return 'Deine Infrastruktur übersichtlich dokumentiert.'
  }

  const teile = [`${getRackHeight(selectedRack.value)} Höheneinheiten`]

  if (selectedRack.value.location) {
    teile.push(selectedRack.value.location)
  }

  teile.push(`${usedUnits.value} HE belegt`)

  return teile.join(' · ')
})

const rackUnits = computed(() => {
  if (!selectedRack.value) return []

  const height = Number(selectedRack.value.height_units)
  const aufDieserSeite = devices.value.filter((device) => isOnSide(device, rackSide.value))
  const aufDerAnderen = devices.value.filter((device) => !isOnSide(device, rackSide.value))

  const inEinheit = (item, unit) => {
    const start = Number(item.start_unit)

    return unit >= start && unit <= start + Number(item.height_units) - 1
  }

  return Array.from({ length: height }, (_, index) => {
    const unit = height - index
    const device = aufDieserSeite.find((item) => inEinheit(item, unit))

    return {
      unit,
      device,
      // Frei auf dieser Seite, aber auf der anderen belegt - als
      // blasser Hinweis, damit man die HE nicht doppelt verplant.
      gegenueber: device ? null : aufDerAnderen.find((item) => inEinheit(item, unit)) || null,
      isTopUnit: device
        ? unit === Number(device.start_unit) + Number(device.height_units) - 1
        : false,
    }
  })
})

function clearMessages() {
  errorMessage.value = ''
  successMessage.value = ''
}

function showError(message) {
  errorMessage.value = message
  successMessage.value = ''
}

function showSuccess(message) {
  successMessage.value = message
  errorMessage.value = ''
}

async function apiRequest(url, options = {}) {
  // Header getrennt zusammenfuehren: Vorher stand "...options" hinter
  // "headers" und hat sie komplett ersetzt, sobald ein Aufrufer eigene
  // Header mitgab - Accept und Content-Type gingen dann verloren.
  const { headers, ...rest } = options

  const response = await fetch(`${API_BASE}${url}`, {
    ...rest,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...xsrfHeader(),
      ...(headers || {}),
    },
  })

  let data = null

  try {
    data = await response.json()
  } catch {
    data = null
  }

  if (!response.ok) {
    let message = `HTTP-Fehler ${response.status}`

    if (data?.message) {
      message = data.message
    }

    if (data?.errors) {
      const validationMessages = Object.values(data.errors)
        .flat()
        .join(' ')

      if (validationMessages) {
        message = validationMessages
      }
    }

    // 401: nicht (mehr) angemeldet, 419: Sitzung bzw. CSRF-Token abgelaufen
    if ((response.status === 401 || response.status === 419) && !url.startsWith('/auth/')) {
      sessionEnded()
    }

    const fehler = new Error(message)
    fehler.status = response.status
    // Fuer Zusatzangaben des Servers, z. B. "restart" bei der 2FA-Anmeldung
    fehler.data = data
    throw fehler
  }

  return data
}

// --- Helle und dunkle Ansicht ---------------------------------
//
// Gesteuert wird das Attribut data-theme am <html>-Element, an dem die
// Farbpalette in style.css haengt. index.html setzt es schon vor dem
// ersten Bild, hier wird es nur noch geaendert.

const THEME_SCHLUESSEL = 'rackview-theme'

// 'auto' folgt der Einstellung des Systems
const themeWunsch = ref('auto')
const systemDunkel = ref(false)

const themeAktiv = computed(() =>
  themeWunsch.value === 'auto' ? (systemDunkel.value ? 'dark' : 'light') : themeWunsch.value
)

function themeAnwenden() {
  document.documentElement.dataset.theme = themeAktiv.value
}

function themeSetzen(wunsch) {
  themeWunsch.value = ['auto', 'light', 'dark'].includes(wunsch) ? wunsch : 'auto'

  try {
    localStorage.setItem(THEME_SCHLUESSEL, themeWunsch.value)
  } catch {
    // Privater Modus ohne localStorage: gilt dann nur fuer diesen Besuch
  }

  themeAnwenden()
}

function themeUmschalten() {
  themeSetzen(themeAktiv.value === 'dark' ? 'light' : 'dark')
}

onMounted(() => {
  try {
    const gespeichert = localStorage.getItem(THEME_SCHLUESSEL)
    if (['auto', 'light', 'dark'].includes(gespeichert)) themeWunsch.value = gespeichert
  } catch {
    // s. o.
  }

  const abfrage = window.matchMedia('(prefers-color-scheme: dark)')

  systemDunkel.value = abfrage.matches
  abfrage.addEventListener('change', (ereignis) => {
    systemDunkel.value = ereignis.matches
    themeAnwenden()
  })

  themeAnwenden()
})

// --- Anmeldung ---------------------------------------------

const authChecked = ref(false)
const currentUser = ref(null)
const hasUsers = ref(true)
// Darf man sich auf der Anmeldeseite selbst ein Konto anlegen?
const registrationOpen = ref(false)
// Passwort stimmte, der Code aus der Authenticator-App fehlt noch
const twoFactorPending = ref(false)
const oidcInfo = ref({ enabled: false, label: '' })
const loginBusy = ref(false)
const loginError = ref('')

// Laravel legt das CSRF-Token als Cookie XSRF-TOKEN ab; aendernde
// Anfragen muessen es als Header zuruecksenden.
function xsrfHeader() {
  const treffer = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return treffer ? { 'X-XSRF-TOKEN': decodeURIComponent(treffer[1]) } : {}
}

const userInitials = computed(() => {
  const teile = String(currentUser.value?.name || '').trim().split(/\s+/).filter(Boolean)
  return (teile.slice(0, 2).map((teil) => teil[0]).join('') || '?').toUpperCase()
})

// Alles vergessen, was zur Sitzung gehoert - nach dem Abmelden soll
// nichts Geladenes mehr im Speicher der Seite stehen.
function resetAppState() {
  racks.value = []
  selectedRack.value = null
  devices.value = []
  allDevices.value = []
  connections.value = []
  selectedDevice.value = null
  devicePorts.value = []
  selectedPort.value = null
  selectedPortDevice.value = null

  showRackForm.value = false
  showDeviceForm.value = false
  showDeviceDetails.value = false
  showConnectionForm.value = false
  showPortForm.value = false
  showPageMenu.value = false

  if (rackDrag.value) stopRackDrag()

  // Auch Zwischenspeicher leeren: Nach einem Kontowechsel darf nichts
  // aus dem vorherigen Workspace mehr in der Seite stehen.
  editingRack.value = null
  editingDevice.value = null
  editingConnection.value = null
  editingPort.value = null
  portCache.value = {}
  portOverviewDeviceId.value = null
  overviewPorts.value = []
  globalQuery.value = ''
  globalSearchOpen.value = false
  clearMessages()
}

// Eine Anfrage wurde mit 401/419 abgewiesen: zurueck zur Anmeldung
function sessionEnded() {
  if (!currentUser.value) return

  currentUser.value = null
  resetAppState()
  loginError.value = 'Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.'

  // Holt zugleich ein frisches CSRF-Cookie fuer die naechste Anmeldung
  apiRequest('/auth/status').catch(() => {})
}

// Name oder E-Mail in den Einstellungen geaendert
function onUserUpdated(user) {
  currentUser.value = user
}

// loadRacks laedt die Geraete aller Racks bereits mit
async function loadAll() {
  await loadRacks()
  await loadConnections()

  // Beim Start mit #device/12 aufgerufen (QR-Etikett)
  if (geraetAusLink) await oeffneGeraetAusLink(geraetAusLink)
}

async function checkAuth() {
  try {
    const status = await apiRequest('/auth/status')
    currentUser.value = status.authenticated ? status.user : null
    hasUsers.value = status.has_users
    registrationOpen.value = Boolean(status.registration_open)
    twoFactorPending.value = !status.authenticated && Boolean(status.two_factor_pending)
    oidcInfo.value = status.oidc || { enabled: false, label: '' }

    // Kam der Browser gerade von einem gescheiterten Anmeldeversuch
    // beim Anbieter zurueck, liegt der Grund in der Sitzung bereit.
    if (status.oidc_error) loginError.value = status.oidc_error
  } catch (error) {
    loginError.value = error.message
  } finally {
    authChecked.value = true
  }

  if (currentUser.value) await loadAll()
}

async function login(daten, erneut = false) {
  loginBusy.value = true
  loginError.value = ''

  try {
    const antwort = await apiRequest('/auth/login', {
      method: 'POST',
      body: JSON.stringify(daten),
    })

    // Konto mit 2FA: Erst nach dem Code aus der App angemeldet
    if (antwort.two_factor) {
      twoFactorPending.value = true
      return
    }

    currentUser.value = antwort.user
    await loadAll()
  } catch (error) {
    if (error.status === 419 && !erneut) {
      // CSRF-Token veraltet (Seite lange offen): Token erneuern, noch einmal
      await apiRequest('/auth/status').catch(() => {})
      // await, damit "finally" erst nach dem zweiten Versuch greift
      return await login(daten, true)
    }

    loginError.value =
      error.status === 429 ? 'Zu viele Anmeldeversuche. Bitte eine Minute warten.' : error.message
  } finally {
    loginBusy.value = false
  }
}

// Zweiter Anmeldeschritt: Code aus der App oder Wiederherstellungscode
async function verifyTwoFactor(daten, erneut = false) {
  loginBusy.value = true
  loginError.value = ''

  try {
    const antwort = await apiRequest('/auth/two-factor', {
      method: 'POST',
      body: JSON.stringify(daten),
    })

    twoFactorPending.value = false
    currentUser.value = antwort.user
    await loadAll()
  } catch (error) {
    if (error.status === 419 && !erneut) {
      await apiRequest('/auth/status').catch(() => {})
      return await verifyTwoFactor(daten, true)
    }

    // Abgelaufen oder zu viele Fehlversuche: zurueck zum Passwort
    if (error.data?.restart) twoFactorPending.value = false

    loginError.value =
      error.status === 429 ? 'Zu viele Versuche. Bitte eine Minute warten.' : error.message
  } finally {
    loginBusy.value = false
  }
}

// Zurueck zur Passworteingabe; die halbe Anmeldung auf dem Server verwerfen
async function cancelTwoFactor() {
  twoFactorPending.value = false
  loginError.value = ''
  await apiRequest('/auth/logout', { method: 'POST' }).catch(() => {})
}

// Selbst registrieren: Das neue Konto ist sofort angemeldet und startet
// mit einem leeren Workspace.
async function register(daten, erneut = false) {
  loginBusy.value = true
  loginError.value = ''

  try {
    const antwort = await apiRequest('/auth/register', {
      method: 'POST',
      body: JSON.stringify(daten),
    })

    currentUser.value = antwort.user
    hasUsers.value = true
    await loadAll()
  } catch (error) {
    if (error.status === 419 && !erneut) {
      await apiRequest('/auth/status').catch(() => {})
      return await register(daten, true)
    }

    loginError.value =
      error.status === 429 ? 'Zu viele neue Konten von dieser Adresse. Bitte später erneut versuchen.' : error.message
  } finally {
    loginBusy.value = false
  }
}

// Eigenes Konto in den Einstellungen geloescht: Der Server hat die
// Sitzung schon beendet, hier nur noch aufraeumen.
async function onAccountDeleted() {
  currentUser.value = null
  resetAppState()
  loginError.value = ''
  await checkAuth()
}

async function logout() {
  try {
    await apiRequest('/auth/logout', { method: 'POST' })
  } catch {
    // Die Sitzung ist dann ohnehin nicht mehr gueltig
  }

  currentUser.value = null
  resetAppState()
  loginError.value = ''

  // Neues CSRF-Cookie fuer die naechste Anmeldung
  await checkAuth()
}

async function loadRacks() {
  loading.value = true
  clearMessages()

  try {
    racks.value = await apiRequest('/racks')
    locations.value = await apiRequest('/locations')
    await loadAllDevices()

    if (
      selectedRack.value &&
      !racks.value.some((rack) => rack.id === selectedRack.value.id)
    ) {
      selectedRack.value = null
      devices.value = []
    }

    // Beim ersten Laden automatisch das erste Rack auswählen
    if (!selectedRack.value && racks.value.length > 0) {
      await selectRack(racks.value[0])
    }
  } catch (error) {
    showError(error.message)
  } finally {
    loading.value = false
  }
}

async function selectRack(rack) {
  selectedRack.value = rack
  await loadDevices(rack.id)
}

/**
 * Alle Geraete auf einmal - im Rack wie am Standort. Frueher fragte
 * die Seite je Rack einzeln; seit es Geraete ohne Rack gibt, waere so
 * nicht einmal die vollstaendige Liste zusammengekommen.
 */
async function loadAllDevices() {
  try {
    const alle = await apiRequest('/devices')

    allDevices.value = alle

    // Die Rack-Ansicht und die Rack-Kacheln lesen rack.devices
    for (const rack of racks.value) {
      rack.devices = alle.filter((device) => Number(device.rack_id) === Number(rack.id))
    }

    if (selectedRack.value) {
      const currentRack = racks.value.find(
        (rack) => rack.id === selectedRack.value.id
      )

      if (currentRack) {
        selectedRack.value = currentRack
        devices.value = currentRack.devices || []
      }
    }
  } catch (error) {
    showError(error.message)
  }
}

async function loadDevices(rackId) {
  try {
    const loadedDevices = await apiRequest(`/racks/${rackId}/devices`)

    // Die Rack-Ansicht zeigt das gewaehlte Rack - Geraete eines anderen
    // Racks (z. B. beim Verschieben) duerfen sie nicht ueberschreiben.
    if (!selectedRack.value || selectedRack.value.id === Number(rackId)) {
      devices.value = loadedDevices
    }

    // Die Rack-Ansicht verwendet selectedRack.devices.
    // Deshalb müssen die geladenen Geräte auch am ausgewählten Rack hängen.
    const rack = racks.value.find((item) => item.id === Number(rackId))

    if (rack) {
      rack.devices = loadedDevices
    }

    if (selectedRack.value && selectedRack.value.id === Number(rackId)) {
      selectedRack.value.devices = loadedDevices
    }

    // Auch die Gesamtliste aktuell halten: Geraete-, IP-, VLAN- und
    // Export-Seite lesen allDevices, nicht nur das gewaehlte Rack.
    allDevices.value = [
      ...allDevices.value.filter((device) => Number(device.rack_id) !== Number(rackId)),
      ...loadedDevices,
    ]
  } catch (error) {
    showError(error.message)
  }
}

// --- Port-Übersicht -----------------------------------------

const portOverviewDeviceId = ref(null)
const overviewPorts = ref([])
const overviewLoading = ref(false)
const selectedPort = ref(null)
// Zu welchem Geraet der angezeigte Port gehoert - er kann aus der
// Port-Uebersicht oder aus den Geraetedetails gewaehlt worden sein.
const selectedPortDevice = ref(null)

// Steckdosenleisten und USVs haben keine Netzwerkbuchsen, sondern
// Plaetze. Dieselbe Uebersicht zeigt beides - was das Geraet eben hat.
const overviewOutlets = ref([])
const selectedOutlet = ref(null)

const portOverviewDevice = computed(() => {
  const id = portOverviewDeviceId.value

  return (
    devices.value.find((device) => device.id === id) ||
    allDevices.value.find((device) => device.id === id) ||
    null
  )
})

// Auswahl nach Rack gruppiert, das aktuelle Rack zuerst, darin von
// oben nach unten wie im Rack
const portDeviceGroups = computed(() => {
  const aktuell = selectedRack.value?.id

  return [...racks.value]
    .sort((a, b) => (b.id === aktuell) - (a.id === aktuell))
    .map((rack) => ({
      id: rack.id,
      label: rack.id === aktuell ? `${rack.name} (aktuell)` : rack.name,
      devices: allDevices.value
        .filter((device) => Number(device.rack_id) === Number(rack.id))
        .sort((a, b) => Number(b.start_unit) - Number(a.start_unit)),
    }))
    .filter((gruppe) => gruppe.devices.length)
})

// Standard: das Geraet mit den meisten dokumentierten Ports - in der
// Regel der Switch oder das Patchpanel, also das Interessanteste.
watch(
  devices,
  (liste) => {
    if (liste.some((device) => device.id === portOverviewDeviceId.value)) return

    const kandidat = [...liste].sort(
      (a, b) => (Number(b.network_ports) || 0) - (Number(a.network_ports) || 0)
    )[0]

    portOverviewDeviceId.value = kandidat ? kandidat.id : null
  },
  { immediate: true }
)

async function refreshPortOverview() {
  const id = portOverviewDeviceId.value

  selectedPort.value = null
  selectedPortDevice.value = null
  selectedOutlet.value = null

  if (!id) {
    overviewPorts.value = []
    overviewOutlets.value = []
    return
  }

  const geraet = portOverviewDevice.value
  overviewLoading.value = true

  try {
    const ports = await apiRequest(`/devices/${id}/ports`)

    // Plaetze nur dort holen, wo es welche geben kann. Die zweite
    // Bedingung faengt Geraete ab, deren Typ nachtraeglich gewechselt
    // hat - die Plaetze sind dann noch da.
    const plaetze =
      OUTLET_CAPABLE_TYPES.includes(geraet?.device_type) || Number(geraet?.outlet_count) > 0
        ? await apiRequest(`/devices/${id}/outlets`)
        : []

    // Waehrend des Ladens koennte ein anderes Geraet gewaehlt worden sein
    if (portOverviewDeviceId.value === id) {
      overviewPorts.value = ports
      overviewOutlets.value = plaetze
    }
  } catch (error) {
    showError(error.message)
  } finally {
    overviewLoading.value = false
  }
}

watch(portOverviewDeviceId, () => {
  overviewPorts.value = []
  overviewOutlets.value = []
  refreshPortOverview()
})

const portCounts = computed(() => {
  const zaehler = { connected: 0, free: 0, faulty: 0, disabled: 0, uplink: 0, poe: 0 }

  for (const port of overviewPorts.value) {
    zaehler[portState(port)] += 1
    if (isUplinkPort(port)) zaehler.uplink += 1
    if (port.poe) zaehler.poe += 1
  }

  return zaehler
})

function selectPort(port, device = portOverviewDevice.value) {
  const abwaehlen = selectedPort.value?.id === port.id

  selectedPort.value = abwaehlen ? null : port
  selectedPortDevice.value = abwaehlen ? null : device
  selectedOutlet.value = null
}

// --- Steckplaetze in der Uebersicht ---------------------------

/** Belegt ist ein Platz durch ein Geraet oder durch freien Text */
function outletBelegt(platz) {
  return Boolean(platz?.connected_device_id || platz?.external_label)
}

/** Was steckt drin - fuer Kurzinfos am Mauszeiger */
function outletName(platz) {
  return platz?.connected_device?.name || platz?.external_label || 'frei'
}

const outletCounts = computed(() => {
  const belegt = overviewOutlets.value.filter(outletBelegt).length

  return { belegt, frei: overviewOutlets.value.length - belegt }
})

// Ohne Netzwerkbuchsen ist es keine Port-, sondern eine Platzuebersicht
const nurSteckplaetze = computed(
  () => overviewOutlets.value.length > 0 && overviewPorts.value.length === 0
)

function selectOutlet(platz) {
  const abwaehlen = selectedOutlet.value?.id === platz.id

  selectedOutlet.value = abwaehlen ? null : platz

  if (!abwaehlen) {
    selectedPort.value = null
    selectedPortDevice.value = null
  }
}

/** Das Rack, in dem ein angeschlossenes Geraet steht */
function rackName(rackId) {
  return racks.value.find((rack) => Number(rack.id) === Number(rackId))?.name || ''
}

/**
 * Von einem Steckplatz zu seiner Leiste: Das Geraetepanel mit den
 * Steckplaetzen steht im Dashboard, nicht in dieser Ansicht - also
 * erst hinwechseln und den Reiter danach setzen. Danach, weil der
 * Wechsel auf ein Geraet immer bei der Uebersicht beginnt.
 */
async function openOutletsOfDevice(device) {
  await openDeviceFromView(device)

  deviceDetailsTab.value = 'outlets'
}

// --- Navigation ----------------------------------------------
//
// Ueber den URL-Anker: #ip, #vlans ... So funktionieren der Zurueck-Knopf
// und Lesezeichen, ohne zusaetzliche Router-Bibliothek.

// Eigene Ansichten
const VIEWS = {
  dashboard: { title: 'Dashboard', subtitle: '' },
  ip: { title: 'IP-Verwaltung', subtitle: 'Alle Adressen nach Netz sortiert, doppelte Vergaben markiert.' },
  vlans: { title: 'VLANs', subtitle: 'Geräte nach VLAN gruppiert, mit dem jeweils genutzten Netz.' },
  racks: { title: 'Racks', subtitle: 'Alle Racks im Überblick.' },
  locations: { title: 'Standorte', subtitle: 'Geräte außerhalb der Racks – Access Points, Modems, Kameras.' },
  devices: { title: 'Geräte', subtitle: 'Alle Geräte – in Racks wie an Standorten.' },
  ports: { title: 'Ports', subtitle: 'Port-Belegung eines Geräts – über alle Racks wählbar.' },
  connections: { title: 'Verbindungen', subtitle: 'Alle dokumentierten Port-Verbindungen.' },
  network: { title: 'Netzwerkplan', subtitle: 'Topologie aus den Port-Verbindungen, über alle Racks.' },
  export: { title: 'Export & Import', subtitle: 'Daten als CSV, JSON oder PDF herunterladen – oder eine Sicherung wieder einlesen.' },
  labels: { title: 'Etiketten', subtitle: 'QR-Etiketten für Geräte drucken – A4-Bogen oder Etikettendrucker.' },
  settings: { title: 'Einstellungen', subtitle: 'Dein Profil, dein Passwort und die Konten für RackView.' },
}

const navGroups = [
  {
    label: 'Arbeitsbereich',
    items: [
      { key: 'dashboard', icon: '▦', label: 'Dashboard' },
      { key: 'racks', icon: '▥', label: 'Racks' },
      { key: 'locations', icon: '⌂', label: 'Standorte' },
      { key: 'devices', icon: '▣', label: 'Geräte' },
      { key: 'ports', icon: '⇄', label: 'Ports' },
      { key: 'connections', icon: '⌁', label: 'Verbindungen' },
      { key: 'network', icon: '⌬', label: 'Netzwerkplan' },
    ],
  },
  {
    label: 'Verwaltung',
    items: [
      { key: 'ip', icon: '◎', label: 'IP-Verwaltung' },
      { key: 'vlans', icon: '⧉', label: 'VLANs' },
      { key: 'export', icon: '⇅', label: 'Export & Import' },
      { key: 'labels', icon: '⌗', label: 'Etiketten' },
      { key: 'settings', icon: '⚙', label: 'Einstellungen' },
    ],
  },
]

const currentView = ref('dashboard')
const activeNav = ref('dashboard')

const isDashboard = computed(() => currentView.value === 'dashboard')

// Kopfzeile mit Rackname, Status und Rack-Aktionen
const rackContext = computed(() => ['dashboard', 'racks'].includes(currentView.value))

// Racks und Ports zeigen dieselben Panels wie das Dashboard, nur anders
// angeordnet (siehe Hauptbereich). Die Panels bleiben bewusst in App.vue:
// Ihr CSS ist scoped und wuerde in einer Kindkomponente nicht greifen.
const currentViewMeta = computed(() => VIEWS[currentView.value])

function scrollToPanel(id) {
  // Nach einem Ansichtswechsel existiert das Panel erst nach dem Rendern
  nextTick(() => {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}

function readHash() {
  const key = window.location.hash.replace('#', '')

  // Aus einem QR-Etikett aufgerufen: #device/12
  const ausEtikett = key.match(/^device\/(\d+)$/)

  if (ausEtikett) {
    currentView.value = 'dashboard'
    activeNav.value = 'dashboard'
    oeffneGeraetAusLink(Number(ausEtikett[1]))
    return
  }

  // Menue-Klick auf "Etiketten": ohne Vorauswahl beginnen
  if (key === 'labels') labelPreselect.value = []

  if (VIEWS[key]) {
    const wechsel = currentView.value !== key
    currentView.value = key
    activeNav.value = key
    if (wechsel) window.scrollTo({ top: 0 })
    return
  }

  currentView.value = 'dashboard'
  activeNav.value = 'dashboard'
}

// Ein erneuter Klick auf denselben Anker loest kein hashchange aus -
// dann trotzdem hinspringen.
// Auf schmalen Geraeten ist die Navigation zugeklappt; sonst muesste
// man an elf Eintraegen vorbeiscrollen, bevor der Inhalt beginnt.
const navOffen = ref(false)

function onNavClick(item) {
  navOffen.value = false

  if (window.location.hash === `#${item.key}`) readHash()
}

// QR-Etikett gescannt: Gerät heraussuchen und anzeigen. Direkt nach der
// Anmeldung sind die Daten noch nicht da - dann merken und danach oeffnen.
let geraetAusLink = null

async function oeffneGeraetAusLink(id) {
  if (!allDevices.value.length) {
    geraetAusLink = id
    return
  }

  geraetAusLink = null

  const device = allDevices.value.find((item) => Number(item.id) === Number(id))

  if (!device) {
    showError('Dieses Gerät gibt es nicht (mehr) in deinem Bereich.')
    return
  }

  await focusDevice(device)
  scrollToPanel('panel-device')
}

// Etiketten drucken, Gerät schon ausgewählt
const labelPreselect = ref([])

function openLabelsFor(device) {
  labelPreselect.value = [device.id]
  currentView.value = 'labels'
  activeNav.value = 'labels'

  if (window.location.hash !== '#labels') {
    history.pushState(null, '', '#labels')
  }

  window.scrollTo({ top: 0 })
}

// Aus einer Ansicht zu einem Geraet: auswaehlen und im Dashboard zeigen
// Aus dem Export: Rack waehlen, Racks-Seite zeigen, dann drucken
async function printRackFromExport(rack) {
  await selectRack(rack)

  currentView.value = 'racks'
  activeNav.value = 'racks'
  history.pushState(null, '', '#racks')

  // Seite rendern lassen; kurze Pause, damit Blenden und Bilder stehen
  await nextTick()
  await new Promise((resolve) => setTimeout(resolve, 300))

  window.print()
}

// Nach einem Import ist alles anders: Racks, Geraete, Ports und
// Verbindungen werden frisch geladen.
async function onImported(ergebnis) {
  await loadAll()

  const wort = (anzahl, einzahl, mehrzahl) => `${anzahl} ${anzahl === 1 ? einzahl : mehrzahl}`

  const teile = [wort(ergebnis.racks, 'Rack', 'Racks')]

  // Standorte nur nennen, wenn die Datei welche mitgebracht hat
  if (ergebnis.standorte) teile.push(wort(ergebnis.standorte, 'Standort', 'Standorte'))

  teile.push(
    wort(ergebnis.geraete, 'Gerät', 'Geräte'),
    wort(ergebnis.ports, 'Port', 'Ports'),
    wort(ergebnis.verbindungen, 'Verbindung', 'Verbindungen')
  )

  showSuccess(`Import abgeschlossen: ${teile.join(', ')}.`)
}

// Aus einer Seite (oder der Suche) zu einem Geraet: auswaehlen, ins
// Dashboard wechseln und zum Geraetedetails-Panel springen.
async function openDeviceFromView(device) {
  await focusDevice(device)

  currentView.value = 'dashboard'
  activeNav.value = 'dashboard'

  // pushState statt hash-Zuweisung: loest kein hashchange aus, der
  // Zurueck-Knopf fuehrt aber trotzdem zur vorherigen Seite.
  if (window.location.hash !== '#dashboard') {
    history.pushState(null, '', '#dashboard')
  }

  scrollToPanel('panel-device')
}

// --- Globale Suche -------------------------------------------

const globalQuery = ref('')
const globalSearchOpen = ref(false)
const globalSearchIndex = ref(0)
const globalSearchInput = ref(null)

const searchShortcutLabel = /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘K' : 'Strg K'

// MAC-Adressen werden ohne Trennzeichen verglichen, damit
// "74:83:c2", "74-83-C2" und "7483c2" dasselbe Geraet finden.
function normalizeMac(value) {
  return String(value || '').toLowerCase().replace(/[^0-9a-f]/g, '')
}

const globalResults = computed(() => {
  const suche = globalQuery.value.trim().toLowerCase()

  if (suche.length < 2) return []

  const sucheMac = normalizeMac(suche)
  const treffer = []

  for (const device of allDevices.value || []) {
    const felder = [
      ['Name', device.name],
      ['IP', device.ip_address],
      ['MAC', device.mac_address],
      ['Hersteller', device.manufacturer],
      ['Modell', device.model],
      ['Seriennummer', device.serial_number],
      ['VLAN', device.vlan],
    ]

    let grund = null

    for (const [label, wert] of felder) {
      if (wert && String(wert).toLowerCase().includes(suche)) {
        grund = { label, wert: String(wert) }
        break
      }
    }

    if (!grund && sucheMac.length >= 4 && normalizeMac(device.mac_address).includes(sucheMac)) {
      grund = { label: 'MAC', wert: device.mac_address }
    }

    if (grund) {
      treffer.push({
        device,
        rack: racks.value.find((rack) => rack.id === device.rack_id),
        grund,
      })
    }
  }

  return treffer.slice(0, 8)
})

watch(globalQuery, () => {
  globalSearchIndex.value = 0
  globalSearchOpen.value = true
})

// Ein Geraet anzeigen, egal in welchem Rack es steckt: erst dorthin
// wechseln, dann die Details oeffnen. Genutzt von Suche und Netzwerkplan.
async function focusDevice(device) {
  const rack = racks.value.find((item) => Number(item.id) === Number(device.rack_id))

  if (rack && selectedRack.value?.id !== rack.id) {
    await selectRack(rack)
  }

  // Das Objekt aus der frisch geladenen Liste verwenden, damit die
  // Auswahl in Rack und Geraeteliste hervorgehoben wird.
  const aktuell = devices.value.find((item) => item.id === device.id) || device

  selectDevice(aktuell)
}

async function openSearchResult(treffer) {
  globalQuery.value = ''
  globalSearchOpen.value = false
  globalSearchInput.value?.blur()

  await openDeviceFromView(treffer.device)
}

function onGlobalSearchKeydown(event) {
  const anzahl = globalResults.value.length

  if (event.key === 'ArrowDown' && anzahl) {
    event.preventDefault()
    globalSearchIndex.value = (globalSearchIndex.value + 1) % anzahl
  } else if (event.key === 'ArrowUp' && anzahl) {
    event.preventDefault()
    globalSearchIndex.value = (globalSearchIndex.value - 1 + anzahl) % anzahl
  } else if (event.key === 'Enter' && anzahl) {
    event.preventDefault()
    openSearchResult(globalResults.value[globalSearchIndex.value])
  } else if (event.key === 'Escape') {
    globalQuery.value = ''
    globalSearchOpen.value = false
    event.target.blur()
  }
}

function focusGlobalSearchOnShortcut(event) {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    globalSearchInput.value?.focus()
    globalSearchInput.value?.select()
    globalSearchOpen.value = true
  }
}

function closeSearchOnOutsideClick(event) {
  if (globalSearchOpen.value && !event.target.closest('.global-search')) {
    globalSearchOpen.value = false
  }
}

const showPageMenu = ref(false)

// PDF-Export ueber den Druckdialog des Browsers ("Als PDF sichern").
// Was dabei ausgeblendet wird, regelt der @media-print-Block im CSS.
function printRack() {
  showPageMenu.value = false
  window.print()
}

// Kein Overlay zum Schliessen: .topbar hat backdrop-filter, dadurch
// wuerde ein position:fixed-Element nur den Kopf abdecken statt
// das ganze Fenster.
function closePageMenuOnOutsideClick(event) {
  if (showPageMenu.value && !event.target.closest('.page-menu')) {
    showPageMenu.value = false
  }
}

function closePageMenuOnEscape(event) {
  if (event.key === 'Escape') {
    showPageMenu.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', closePageMenuOnOutsideClick)
  document.addEventListener('click', closeSearchOnOutsideClick)
  document.addEventListener('keydown', closePageMenuOnEscape)
  document.addEventListener('keydown', focusGlobalSearchOnShortcut)
  window.addEventListener('hashchange', readHash)
  readHash()
})

onBeforeUnmount(() => {
  document.removeEventListener('click', closePageMenuOnOutsideClick)
  document.removeEventListener('click', closeSearchOnOutsideClick)
  document.removeEventListener('keydown', closePageMenuOnEscape)
  document.removeEventListener('keydown', focusGlobalSearchOnShortcut)
  window.removeEventListener('hashchange', readHash)
})

function openNewRackForm() {
  editingRack.value = null
  rackForm.value = {
    name: '',
    location: '',
    height_units: 18,
    description: '',
  }
  showRackForm.value = true
  clearMessages()
}

function openEditRackForm(rack) {
  editingRack.value = rack
  rackForm.value = {
    name: rack.name || '',
    location: rack.location || '',
    height_units: Number(rack.height_units || 18),
    description: rack.description || '',
  }
  showRackForm.value = true
  clearMessages()
}

async function saveRack() {
  clearMessages()

  try {
    const payload = {
      ...rackForm.value,
      height_units: Number(rackForm.value.height_units),
    }

    if (editingRack.value) {
      await apiRequest(`/racks/${editingRack.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
      })
      showSuccess('Rack erfolgreich aktualisiert.')
    } else {
      await apiRequest('/racks', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      showSuccess('Rack erfolgreich angelegt.')
    }

    showRackForm.value = false
    await loadRacks()
  } catch (error) {
    showError(error.message)
  }
}

async function deleteRack(rack) {
  if (
    !confirm(
      `Rack "${rack.name}" wirklich löschen? Alle Geräte darin werden ebenfalls entfernt.`
    )
  ) {
    return false
  }

  clearMessages()

  try {
    await apiRequest(`/racks/${rack.id}`, {
      method: 'DELETE',
    })

    if (selectedRack.value?.id === rack.id) {
      selectedRack.value = null
      devices.value = []
      selectedDevice.value = null
    }

    showSuccess('Rack erfolgreich gelöscht.')
    await loadRacks()
    await loadConnections()

    return true
  } catch (error) {
    showError(error.message)
    return false
  }
}

/* --- Standorte ------------------------------------------------
   Gleiche Form wie beim Rack, nur ohne Hoeheneinheiten. */

function openNewLocationForm() {
  editingLocation.value = null
  locationForm.value = { name: '', description: '' }
  showLocationForm.value = true
  clearMessages()
}

function openEditLocationForm(ort) {
  editingLocation.value = ort
  locationForm.value = {
    name: ort.name || '',
    description: ort.description || '',
  }
  showLocationForm.value = true
  clearMessages()
}

async function saveLocation() {
  clearMessages()

  try {
    const payload = { ...locationForm.value }

    if (editingLocation.value) {
      await apiRequest(`/locations/${editingLocation.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
      })
      showSuccess('Standort erfolgreich aktualisiert.')
    } else {
      await apiRequest('/locations', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      showSuccess('Standort erfolgreich angelegt.')
    }

    showLocationForm.value = false
    await loadRacks()
  } catch (error) {
    showError(error.message)
  }
}

async function deleteLocation(ort) {
  const anzahl = allDevices.value.filter(
    (device) => Number(device.location_id) === Number(ort.id)
  ).length

  const warnung = anzahl
    ? `\n\nDie ${anzahl} ${anzahl === 1 ? 'Gerät' : 'Geräte'} dort werden mitgelöscht – samt Ports und Verbindungen.`
    : ''

  if (!confirm(`Standort "${ort.name}" wirklich löschen?${warnung}`)) return false

  clearMessages()

  try {
    await apiRequest(`/locations/${ort.id}`, { method: 'DELETE' })

    showSuccess('Standort erfolgreich gelöscht.')
    await loadRacks()
    await loadConnections()

    return true
  } catch (error) {
    showError(error.message)
    return false
  }
}

async function deleteLocationFromForm() {
  if (await deleteLocation(editingLocation.value)) {
    showLocationForm.value = false
  }
}

// Aus dem Rack-Dialog: nach erfolgreichem Loeschen den Dialog schliessen
async function deleteRackFromForm() {
  if (await deleteRack(editingRack.value)) {
    showRackForm.value = false
  }
}

// Von der Geraete-Seite: Ein Geraet gehoert immer in ein Rack. Ist keins
// gewaehlt, wird das erste vorbelegt; im Formular laesst es sich aendern.
async function openNewDeviceFromView() {
  if (!racks.value.length && !locations.value.length) {
    showError('Lege zuerst ein Rack oder einen Standort an – ein Gerät braucht beides nicht, aber eines davon.')
    return
  }

  // Ohne Rack bleibt der Standort - im Formular laesst es sich aendern
  if (!racks.value.length) {
    openNewDeviceForm(locations.value[0])
    return
  }

  if (!selectedRack.value) {
    await selectRack(racks.value[0])
  }

  openNewDeviceForm()
}

// Rack im Formular wechseln - beim Anlegen wie beim Verschieben. Passt die
// bisherige Position im neuen Rack nicht, wird die erste freie vorgeschlagen.
/** Der Wert der Auswahl: "rack:3" oder "ort:5" */
const deviceFormOrt = computed(() =>
  deviceForm.value.rack_id
    ? `rack:${deviceForm.value.rack_id}`
    : deviceForm.value.location_id
      ? `ort:${deviceForm.value.location_id}`
      : ''
)

function changeDeviceFormOrt(event) {
  const [art, roh] = String(event.target.value).split(':')
  const id = Number(roh)

  if (art === 'ort') {
    if (!locations.value.some((ort) => Number(ort.id) === id)) return

    // Am Standort gibt es keine Position - die Felder verschwinden
    deviceForm.value.location_id = id
    deviceForm.value.rack_id = null

    return
  }

  if (!racks.value.some((rack) => Number(rack.id) === id)) return

  deviceForm.value.rack_id = id
  deviceForm.value.location_id = null

  // Aus dem Standort zurueck ins Rack: eine Position wird gebraucht
  if (!Number(deviceForm.value.start_unit)) deviceForm.value.start_unit = 1
  if (!Number(deviceForm.value.height_units)) deviceForm.value.height_units = 1

  if (validateDevicePosition()) {
    deviceForm.value.start_unit = findNextFreeUnit(Number(deviceForm.value.height_units) || 1) ?? 1
  }
}

/**
 * Neues Geraet. Ohne Angabe landet es im gewaehlten Rack; mit einem
 * Standort dort - dann ohne Position und Hoehe.
 */
function openNewDeviceForm(ort = null) {
  if (!ort && !selectedRack.value) return

  setPendingImage(null)

  editingDevice.value = null

  const nextUnit = ort ? null : findNextFreeUnit(1)

  deviceForm.value = {
    rack_id: ort ? null : selectedRack.value.id,
    location_id: ort ? ort.id : null,
    name: '',
    manufacturer: '',
    model: '',
    device_type: 'other',
    status: 'active',
    height_units: 1,
    start_unit: nextUnit,
    // In der Rueckansicht angelegte Geraete sitzen meist auch hinten
    mount_side: !ort && rackSide.value === 'rear' ? 'rear' : 'full',
    serial_number: '',
    ip_address: '',
    mac_address: '',
    purchase_date: '',
    warranty_until: '',
    vlan: '',
    switch_port: '',
    uplink_port: '',
    network_ports: '',
    poe_ports: '',
    poe_type: 'poe+',
    outlet_count: '',
    notes: '',
    description: '',
  }

  showDeviceForm.value = true
  clearMessages()
}

function openEditDeviceForm(device) {
  if (!device) return

  editingDevice.value = device

  deviceForm.value = {
    rack_id: device.rack_id ?? null,
    location_id: device.location_id ?? null,
    name: device.name ?? '',
    manufacturer: device.manufacturer ?? '',
    model: device.model ?? '',
    device_type: device.device_type ?? 'other',
    status: device.status ?? 'active',
    height_units: Number(device.height_units ?? 1),
    start_unit: Number(device.start_unit ?? 1),
    mount_side: getMountSide(device),
    serial_number: device.serial_number ?? '',
    ip_address: device.ip_address ?? '',
    mac_address: device.mac_address ?? '',
    // Das Backend liefert reine Datumswerte - genau das, was ein
    // <input type="date"> erwartet.
    purchase_date: device.purchase_date ?? '',
    warranty_until: device.warranty_until ?? '',
    vlan: device.vlan ?? '',
    switch_port: device.switch_port ?? '',
    uplink_port: device.uplink_port ?? '',
    network_ports: device.network_ports ?? '',
    poe_ports: device.poe_ports ?? '',
    poe_type: device.poe_type || 'poe+',
    outlet_count: device.outlet_count ?? '',
    notes: device.notes ?? '',
    description: device.description ?? '',
  }

  showDeviceDetails.value = false
  showDeviceForm.value = true
  clearMessages()
}

/** "+2 Jahre": Garantieende aus dem Kaufdatum ausrechnen */
function setzeGarantie(jahre) {
  const ende = addYears(deviceForm.value.purchase_date, jahre)

  if (ende) deviceForm.value.warranty_until = ende
}

function findNextFreeUnit(height) {
  const rackHeight = Number(formRack.value?.height_units || 0)
  const requestedHeight = Number(height || 1)

  if (
    requestedHeight < 1 ||
    requestedHeight > rackHeight
  ) {
    return null
  }

  for (
    let start = 1;
    start <= rackHeight - requestedHeight + 1;
    start++
  ) {
    if (!hasConflict(start, requestedHeight)) {
      return start
    }
  }

  return null
}

function useNextFreePosition() {
  if (!formRack.value) return

  const height = Number(deviceForm.value.height_units || 1)
  const nextUnit = findNextFreeUnit(height)

  const rackHeight = Number(formRack.value.height_units || 0)
  const end = nextUnit + height - 1

  if (height < 1 || height > rackHeight || end > rackHeight) {
    showError(
      `Für ein Gerät mit ${height} HE ist keine passende freie Position verfügbar.`
    )
    return
  }

  if (hasConflict(nextUnit, height)) {
    showError(
      `Für ein Gerät mit ${height} HE ist keine zusammenhängende freie Position verfügbar.`
    )
    return
  }

  deviceForm.value.start_unit = nextUnit
  showSuccess(
    `Nächste freie Position: U${nextUnit} für ${height} HE.`
  )
}

watch(
  () => deviceForm.value.height_units,
  (newHeight, oldHeight) => {
    if (!showDeviceForm.value) return
    if (newHeight === oldHeight) return

    const height = Number(newHeight || 1)
    if (!Number.isInteger(height) || height < 1) return

    const currentStart = Number(deviceForm.value.start_unit || 1)

    if (!hasConflict(currentStart, height)) {
      const rackHeight = Number(formRack.value?.height_units || 0)

      if (currentStart + height - 1 <= rackHeight) {
        return
      }
    }

    const nextUnit = findNextFreeUnit(height)
    deviceForm.value.start_unit = nextUnit
  }
)

// Rack, in das das Formular gerade speichern wuerde - beim Anlegen das
// gewaehlte, beim Verschieben das Ziel. Position und Belegung werden
// immer gegen dieses Rack geprueft, nicht gegen das in der App gewaehlte.
const formRack = computed(() => {
  // Steht das Geraet an einem Standort, gibt es kein Rack - und damit
  // auch keine Position zu pruefen.
  if (!deviceForm.value.rack_id) return null

  const id = Number(deviceForm.value.rack_id)

  return racks.value.find((rack) => Number(rack.id) === id) || selectedRack.value || null
})

const formRackDevices = computed(() => {
  const id = Number(formRack.value?.id)
  return allDevices.value.filter((device) => Number(device.rack_id) === id)
})

// Belegt ein anderes Geraet diesen Bereich? Zwei halbtiefe Geraete
// stehen sich nur im Weg, wenn sie auf derselben Seite sitzen.
function findeKonflikt(startUnit, heightUnits) {
  const start = Number(startUnit)
  const end = start + Number(heightUnits) - 1
  const seite = deviceForm.value.mount_side || 'full'

  return formRackDevices.value.find((device) => {
    if (
      editingDevice.value &&
      Number(device.id) === Number(editingDevice.value.id)
    ) {
      return false
    }

    const existingStart = Number(device.start_unit)
    const existingEnd =
      existingStart + Number(device.height_units) - 1

    return start <= existingEnd && end >= existingStart &&
      sidesCollide(seite, getMountSide(device))
  }) || null
}

function hasConflict(startUnit, heightUnits) {
  return findeKonflikt(startUnit, heightUnits) !== null
}

// PoE-Ports sind ein Teil der Netzwerkports. Frueher waren beide
// Felder gesperrt, solange die Zahl davor fehlte - man sah dann nur ein
// graues Feld und keinen Grund. Jetzt sind sie bedienbar, und wenn die
// Angaben nicht zusammenpassen, sagt das Formular beim Speichern warum.
function validateDevicePoe() {
  if (!POE_CAPABLE_TYPES.includes(deviceForm.value.device_type)) return ''

  const poe = Number(deviceForm.value.poe_ports) || 0
  const netz = Number(deviceForm.value.network_ports) || 0

  if (!poe) return ''

  if (!netz) {
    return 'Trage zuerst die Anzahl der Netzwerkports ein – die PoE-Ports sind ein Teil davon.'
  }

  if (poe > netz) {
    return `Es kann nicht mehr PoE-Ports (${poe}) als Netzwerkports (${netz}) geben.`
  }

  return ''
}

function validateDevicePosition() {
  // Am Standort gibt es keine Hoeheneinheiten
  if (!deviceForm.value.rack_id) return ''

  const rackHeight = Number(formRack.value?.height_units || 0)
  const start = Number(deviceForm.value.start_unit)
  const height = Number(deviceForm.value.height_units)
  const end = start + height - 1

  if (!Number.isInteger(start) || start < 1) {
    return 'Die Start-Unit muss mindestens 1 sein.'
  }

  if (!Number.isInteger(height) || height < 1) {
    return 'Die Gerätehöhe muss mindestens 1 HE sein.'
  }

  if (end > rackHeight) {
    return `Das Gerät passt nicht ins Rack. Maximal ${rackHeight} HE verfügbar.`
  }

  const konflikt = findeKonflikt(start, height)

  if (konflikt) {
    const seite = getMountSide(konflikt)
    const wo = seite === 'full' ? 'volle Tiefe' : seite === 'front' ? 'nur vorne' : 'nur hinten'

    return `Belegt durch „${konflikt.name}" (${wo}).`
  }

  return ''
}

const imageUploading = ref(false)

// Beim Anlegen gibt es noch keine Geraete-ID: Das gewaehlte Bild wird
// vorgemerkt und nach dem Speichern hochgeladen.
const pendingImage = ref(null)
const pendingImagePreview = ref('')

function setPendingImage(datei) {
  if (pendingImagePreview.value) URL.revokeObjectURL(pendingImagePreview.value)

  pendingImage.value = datei || null
  pendingImagePreview.value = datei ? URL.createObjectURL(datei) : ''
}

function choosePendingImage(event) {
  setPendingImage(event.target.files?.[0])
  event.target.value = ''
}

async function uploadImageFile(deviceId, datei) {
  const daten = new FormData()
  daten.append('image', datei)

  // Kein Content-Type setzen - bei FormData erzeugt ihn der Browser samt Grenze
  const antwort = await fetch(`${API_BASE}/devices/${deviceId}/image`, {
    method: 'POST',
    headers: { Accept: 'application/json', ...xsrfHeader() },
    body: daten,
  })

  if (antwort.status === 401 || antwort.status === 419) sessionEnded()

  const ergebnis = await antwort.json().catch(() => null)

  if (!antwort.ok) {
    throw new Error(
      ergebnis?.errors?.image?.[0] || ergebnis?.message || `HTTP-Fehler ${antwort.status}`
    )
  }

  return ergebnis
}

// Direkt aus dem Geraetedetails-Panel
async function uploadImageForSelected(datei) {
  const device = selectedDevice.value
  if (!datei || !device) return

  clearMessages()
  imageUploading.value = true

  try {
    await uploadImageFile(device.id, datei)
    showSuccess('Foto gespeichert.')

    await loadDevices(selectedRack.value.id)
    selectedDevice.value = devices.value.find((item) => item.id === device.id) || selectedDevice.value
  } catch (error) {
    showError(error.message)
  } finally {
    imageUploading.value = false
  }
}

// Das Bild haengt am Geraet, nicht am Formular: Es wird sofort
// uebertragen und nicht erst beim Speichern des Dialogs.
// Deshalb hier kein apiRequest - FormData darf keinen
// Content-Type setzen, den Header baut der Browser selbst.
async function uploadDeviceImage(event) {
  const datei = event.target.files?.[0]
  event.target.value = ''

  if (!datei || !editingDevice.value) return

  clearMessages()
  imageUploading.value = true

  try {
    editingDevice.value = await uploadImageFile(editingDevice.value.id, datei)
    showSuccess('Foto gespeichert.')

    await loadDevices(selectedRack.value.id)
  } catch (error) {
    showError(error.message)
  } finally {
    imageUploading.value = false
  }
}

async function removeDeviceImage() {
  if (!editingDevice.value?.image_url) return
  if (!confirm('Hinterlegtes Foto entfernen?')) return

  clearMessages()

  try {
    editingDevice.value = await apiRequest(
      `/devices/${editingDevice.value.id}/image`,
      { method: 'DELETE' }
    )

    showSuccess('Foto entfernt.')

    await loadDevices(selectedRack.value.id)
  } catch (error) {
    showError(error.message)
  }
}

async function saveDevice() {
  clearMessages()

  const positionError = validateDevicePosition()

  if (positionError) {
    showError(positionError)
    return
  }

  const poeError = validateDevicePoe()

  if (poeError) {
    showError(poeError)
    return
  }

  try {
    const payload = {
      ...deviceForm.value,
      height_units: Number(deviceForm.value.height_units),
      start_unit: Number(deviceForm.value.start_unit),
    }

    // Wird der Typ z. B. von Switch auf Patchpanel geaendert, sind die
    // PoE-Felder ausgeblendet - ihre alten Werte sollen nicht mitgehen.
    if (!POE_CAPABLE_TYPES.includes(payload.device_type) || !Number(payload.poe_ports)) {
      payload.poe_ports = null
      payload.poe_type = null
    }

    // Am Standort bleibt rack_id leer; nur im Rack wird ein Rack
    // ergaenzt, falls das Formular keines nennt.
    const amStandort = Boolean(payload.location_id)
    const rackId = amStandort ? null : (Number(payload.rack_id) || selectedRack.value?.id)

    payload.rack_id = rackId
    payload.location_id = amStandort ? Number(payload.location_id) : null

    if (editingDevice.value) {
      const bisherigesRack = editingDevice.value.rack_id

      await apiRequest(`/devices/${editingDevice.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
      })

      const umgezogen =
        Number(bisherigesRack || 0) !== Number(rackId || 0) ||
        Number(editingDevice.value.location_id || 0) !== Number(payload.location_id || 0)

      if (umgezogen) {
        const ziel = amStandort
          ? locations.value.find((ort) => Number(ort.id) === Number(payload.location_id))
          : racks.value.find((rack) => Number(rack.id) === Number(rackId))

        showSuccess(`Gerät nach „${ziel ? ziel.name : 'anderswohin'}" verschoben.`)

        // Das alte Rack ist jetzt eine Einheit leerer
        if (bisherigesRack) await loadDevices(bisherigesRack)
      } else {
        showSuccess('Gerät erfolgreich aktualisiert.')
      }
    } else {
      const neu = await apiRequest('/devices', {
        method: 'POST',
        body: JSON.stringify(payload),
      })

      // Vorgemerktes Bild jetzt hochladen - das Geraet existiert erst ab hier
      let bildFehler = ''

      if (pendingImage.value && neu?.id) {
        try {
          await uploadImageFile(neu.id, pendingImage.value)
        } catch (error) {
          bildFehler = error.message
        }
      }

      setPendingImage(null)

      // showSuccess wuerde eine vorherige Fehlermeldung ueberschreiben
      if (bildFehler) {
        showError(`Gerät angelegt, aber das Foto wurde nicht gespeichert: ${bildFehler}`)
      } else {
        showSuccess('Gerät erfolgreich angelegt.')
      }
    }

    showDeviceForm.value = false

    // Nicht nur das eine Rack: Das Geraet kann auch an einen Standort
    // gewandert sein, und dann taucht es in keiner Rackliste mehr auf.
    await loadAllDevices()

    // Panel auf das frisch geladene Objekt zeigen lassen
    if (selectedDevice.value) {
      selectedDevice.value =
        allDevices.value.find((device) => device.id === selectedDevice.value.id) || selectedDevice.value
    }

    // Beim Speichern legt das Backend fehlende Ports an
    await refreshPortOverview()

    // ... und fehlende Steckplaetze, wenn sich ihre Anzahl geaendert hat
    await loadDeviceOutlets(selectedDevice.value)
  } catch (error) {
    showError(error.message)
  }
}

async function deleteDevice(device) {
  if (
    !confirm(
      `Gerät "${device.name}" wirklich löschen? Seine Ports und Verbindungen werden ebenfalls entfernt.`
    )
  ) {
    return false
  }

  clearMessages()

  try {
    await apiRequest(`/devices/${device.id}`, { method: 'DELETE' })

    // Nichts mehr anzeigen, was es nicht mehr gibt
    if (selectedDevice.value?.id === device.id) {
      selectedDevice.value = null
      devicePorts.value = []
      showDeviceDetails.value = false
    }

    if (selectedPortDevice.value?.id === device.id) {
      selectedPort.value = null
      selectedPortDevice.value = null
    }

    showSuccess('Gerät erfolgreich gelöscht.')

    // Die ganze Liste neu holen: Das Geraet kann in einem Rack oder an
    // einem Standort gestanden haben.
    await loadAllDevices()

    // Verbindungen fallen in der Datenbank per Kaskade mit weg
    await loadConnections()

    return true
  } catch (error) {
    showError(error.message)
    return false
  }
}

// Aus dem Geraete-Dialog: nach erfolgreichem Loeschen den Dialog schliessen
async function deleteDeviceFromForm() {
  if (await deleteDevice(editingDevice.value)) {
    showDeviceForm.value = false
  }
}

// Klick auf ein Geraet: nur auswaehlen, das Panel zeigt die Details.
// Der Verwaltungsdialog (Ports, Verbindungen) oeffnet sich ueber
// "Verwalten" - er deckt das ganze Dashboard ab.
// Mit welchem Reiter das Geraetepanel aufgeht. Normal die Uebersicht;
// aus der Platzuebersicht heraus direkt die Steckplaetze.
const deviceDetailsTab = ref('overview')

function selectDevice(device) {
  if (selectedDevice.value?.id !== device.id) {
    devicePorts.value = []
  }

  deviceDetailsTab.value = 'overview'

  // Nur hinten eingebaut? Dann die Rueckansicht zeigen (und umgekehrt)
  if (!isOnSide(device, rackSide.value)) {
    rackSide.value = getMountSide(device)
  }

  selectedDevice.value = device
  loadDevicePorts(device)
  loadDeviceOutlets(device)
}

// --- Drag & Drop im Rack ---------------------------------------
//
// Eigene Zeiger-Logik statt HTML5-Drag-and-Drop: Die Vorschau rastet auf
// Hoeheneinheiten ein, und auf Touchgeraeten klappt es per langem Druck.
// Ein kurzer Klick waehlt das Geraet weiterhin nur aus.

const DRAG_SCHWELLE = 5 // px Bewegung, ab der aus einem Klick ein Ziehen wird
const TOUCH_HALTEZEIT = 400 // ms langer Druck auf Touchgeraeten

const rackVisualRef = ref(null)
const rackDrag = ref(null)
const rackDragSaving = ref(false)

let rackDragTimer = null
let rackDragScrollFrame = null
let rackDragKlickSperre = 0

function heBereich(start, hoehe) {
  return hoehe > 1 ? `HE ${start}–${start + hoehe - 1}` : `HE ${start}`
}

// Geraet, das den Bereich belegt - oder null, wenn er frei ist
function belegtDurch(liste, start, hoehe, ignorieren, seite = 'full') {
  const ende = start + hoehe - 1

  return liste.find((device) => {
    if (Number(device.id) === Number(ignorieren)) return false

    const belegtAb = Number(device.start_unit)
    const belegtBis = belegtAb + getDeviceHeight(device) - 1

    return start <= belegtBis && ende >= belegtAb &&
      sidesCollide(seite, getMountSide(device))
  }) || null
}

// Freie Position moeglichst nah an der gewuenschten - fuer das Ablegen
// auf einem anderen Rack, dort ist die alte HE vielleicht belegt.
function naechsteFreiePosition(liste, rackHoehe, hoehe, wunsch, ignorieren, seite = 'full') {
  const hoechsterStart = rackHoehe - hoehe + 1

  if (hoechsterStart < 1) return null

  const ziel = Math.min(Math.max(wunsch, 1), hoechsterStart)

  for (let abstand = 0; abstand < hoechsterStart; abstand++) {
    for (const start of [ziel + abstand, ziel - abstand]) {
      if (start >= 1 && start <= hoechsterStart && !belegtDurch(liste, start, hoehe, ignorieren, seite)) {
        return start
      }
    }
  }

  return null
}

function rackZeilen() {
  return rackVisualRef.value ? [...rackVisualRef.value.querySelectorAll('.rack-unit-row')] : []
}

// Hoeheneinheit unter dem Zeiger; oberhalb/unterhalb des Racks die
// oberste bzw. unterste
function unitAtPointer(clientY) {
  const zeilen = rackZeilen()

  if (!zeilen.length) return null

  const oben = zeilen[0].getBoundingClientRect().top
  const unten = zeilen[zeilen.length - 1].getBoundingClientRect().bottom
  const zeilenHoehe = (unten - oben) / zeilen.length
  const index = Math.min(Math.max(Math.floor((clientY - oben) / zeilenHoehe), 0), zeilen.length - 1)

  return zeilen.length - index
}

function startRackDrag(event, device) {
  if (event.button !== 0 || rackDrag.value || rackDragSaving.value || !selectedRack.value) return

  const griff = unitAtPointer(event.clientY)
  const oben = Number(device.start_unit) + getDeviceHeight(device) - 1

  rackDrag.value = {
    device,
    pointerId: event.pointerId,
    pointerType: event.pointerType,
    startX: event.clientX,
    startY: event.clientY,
    x: event.clientX,
    y: event.clientY,
    aktiv: false,
    // Wo das Geraet gegriffen wurde: HE-Abstand zur Oberkante
    griffAbstand: griff === null ? 0 : Math.max(oben - griff, 0),
    geometrie: null,
    zielStart: Number(device.start_unit),
    zielRack: null,
    gueltig: true,
    blockiert: null,
    scrollTempo: 0,
  }

  // Maus: Loslassen ausserhalb des Fensters kommt trotzdem an
  try {
    event.currentTarget.setPointerCapture(event.pointerId)
  } catch {
    // aeltere Browser - dann eben ohne
  }

  window.addEventListener('pointermove', onRackDragMove)
  window.addEventListener('pointerup', onRackDragEnd)
  window.addEventListener('pointercancel', cancelRackDrag)
  window.addEventListener('keydown', onRackDragKey)

  // Touch: erst nach langem Druck ziehen, sonst wuerde jedes Wischen
  // ueber das Rack ein Geraet verschieben statt die Seite zu scrollen.
  if (event.pointerType === 'touch') {
    rackDragTimer = setTimeout(activateRackDrag, TOUCH_HALTEZEIT)
  }
}

function activateRackDrag() {
  clearTimeout(rackDragTimer)
  rackDragTimer = null

  const drag = rackDrag.value
  const container = rackVisualRef.value
  const zeilen = rackZeilen()

  if (!drag || drag.aktiv || !container || !zeilen.length) return

  // Masse einmal messen - die Zeilen aendern sich waehrend des Ziehens nicht
  const box = container.getBoundingClientRect()
  const erste = zeilen[0].getBoundingClientRect()
  const letzte = zeilen[zeilen.length - 1].getBoundingClientRect()
  const inhalt = zeilen[0].querySelector('.rack-unit-content')?.getBoundingClientRect() || erste

  drag.geometrie = {
    oben: erste.top - box.top - container.clientTop,
    links: inhalt.left - box.left - container.clientLeft,
    breite: inhalt.width,
    zeilenHoehe: (letzte.bottom - erste.top) / zeilen.length,
  }

  drag.aktiv = true
  document.body.classList.add('rack-dragging')
  window.addEventListener('touchmove', blockTouchScroll, { passive: false })

  if (drag.pointerType === 'touch') navigator.vibrate?.(15)

  updateRackDragTarget()
}

function onRackDragMove(event) {
  const drag = rackDrag.value

  if (!drag || event.pointerId !== drag.pointerId) return

  drag.x = event.clientX
  drag.y = event.clientY

  if (!drag.aktiv) {
    const weg = Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY)

    // Finger bewegt sich vor Ablauf der Haltezeit: Das ist Scrollen
    if (drag.pointerType === 'touch') {
      if (weg > 10) cancelRackDrag()
      return
    }

    if (weg < DRAG_SCHWELLE) return

    activateRackDrag()
  }

  updateRackDragTarget()
  updateRackDragScroll()
}

function updateRackDragTarget() {
  const drag = rackDrag.value

  if (!drag?.aktiv || !selectedRack.value) return

  const hoehe = getDeviceHeight(drag.device)

  // Ueber einem anderen Rack (Rack-Auswahl oder Rack-Karte)?
  const ziel = document.elementFromPoint(drag.x, drag.y)?.closest('[data-rack-drop]')
  const zielRackId = ziel ? Number(ziel.dataset.rackDrop) : null

  if (zielRackId && zielRackId !== Number(selectedRack.value.id)) {
    const rack = racks.value.find((item) => Number(item.id) === zielRackId) || null
    const start = rack
      ? naechsteFreiePosition(rack.devices || [], getRackHeight(rack), hoehe, Number(drag.device.start_unit), drag.device.id, getMountSide(drag.device))
      : null

    drag.zielRack = rack
    drag.zielStart = start
    drag.gueltig = start !== null
    drag.blockiert = null
    return
  }

  drag.zielRack = null

  // Weit neben dem Rack: Loslassen bricht ab
  const box = rackVisualRef.value?.getBoundingClientRect()
  const ueberRack = box &&
    drag.x >= box.left - 40 && drag.x <= box.right + 40 &&
    drag.y >= box.top - 60 && drag.y <= box.bottom + 60

  if (!ueberRack) {
    drag.zielStart = null
    drag.gueltig = false
    drag.blockiert = null
    return
  }

  const rackHoehe = getRackHeight(selectedRack.value)
  const oben = Math.min(Math.max(unitAtPointer(drag.y) + drag.griffAbstand, hoehe), rackHoehe)
  const start = oben - hoehe + 1

  drag.zielStart = start
  drag.blockiert = belegtDurch(devices.value, start, hoehe, drag.device.id, getMountSide(drag.device))
  drag.gueltig = !drag.blockiert
}

// Am oberen/unteren Rand automatisch scrollen - hohe Racks passen
// nicht immer ganz auf den Bildschirm.
function updateRackDragScroll() {
  const drag = rackDrag.value

  if (!drag?.aktiv) return

  const kopf = Math.max(document.querySelector('.topbar')?.getBoundingClientRect().bottom || 0, 0)
  const rand = 60
  let tempo = 0

  if (drag.y < kopf + rand) {
    tempo = -Math.ceil((kopf + rand - drag.y) / 4)
  } else if (drag.y > window.innerHeight - rand) {
    tempo = Math.ceil((drag.y - (window.innerHeight - rand)) / 4)
  }

  drag.scrollTempo = Math.max(Math.min(tempo, 20), -20)

  if (drag.scrollTempo && !rackDragScrollFrame) {
    rackDragScrollFrame = requestAnimationFrame(rackDragScrollSchritt)
  }
}

function rackDragScrollSchritt() {
  rackDragScrollFrame = null

  const drag = rackDrag.value

  if (!drag?.aktiv || !drag.scrollTempo) return

  window.scrollBy(0, drag.scrollTempo)
  updateRackDragTarget()

  rackDragScrollFrame = requestAnimationFrame(rackDragScrollSchritt)
}

function blockTouchScroll(event) {
  if (rackDrag.value?.aktiv) event.preventDefault()
}

function onRackDragKey(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    cancelRackDrag()
  }
}

function stopRackDrag() {
  clearTimeout(rackDragTimer)
  rackDragTimer = null

  if (rackDragScrollFrame) cancelAnimationFrame(rackDragScrollFrame)
  rackDragScrollFrame = null

  window.removeEventListener('pointermove', onRackDragMove)
  window.removeEventListener('pointerup', onRackDragEnd)
  window.removeEventListener('pointercancel', cancelRackDrag)
  window.removeEventListener('keydown', onRackDragKey)
  window.removeEventListener('touchmove', blockTouchScroll)
  document.body.classList.remove('rack-dragging')

  rackDrag.value = null
}

function cancelRackDrag() {
  // Der Browser schickt nach dem Loslassen noch einen Klick hinterher
  if (rackDrag.value?.aktiv) rackDragKlickSperre = Date.now()

  stopRackDrag()
}

async function onRackDragEnd(event) {
  const drag = rackDrag.value

  if (!drag || event.pointerId !== drag.pointerId) return

  const warAktiv = drag.aktiv

  stopRackDrag()

  // Kein Ziehen, nur ein Klick: den erledigt der click-Handler
  if (!warAktiv) return

  rackDragKlickSperre = Date.now()

  if (!drag.gueltig || drag.zielStart === null) return

  if (!drag.zielRack && drag.zielStart === Number(drag.device.start_unit)) return

  await moveDevice(drag.device, drag.zielRack, drag.zielStart)
}

function onRackDeviceClick(device) {
  if (Date.now() - rackDragKlickSperre < 400) return

  selectDevice(device)
}

async function moveDevice(device, zielRack, start) {
  const vonRackId = Number(device.rack_id ?? selectedRack.value?.id)
  const nachRackId = zielRack ? Number(zielRack.id) : vonRackId
  const vorher = Number(device.start_unit)

  clearMessages()
  rackDragSaving.value = true

  // Sofort an der neuen Stelle zeigen, bei einem Fehler zuruecksetzen
  if (nachRackId === vonRackId) device.start_unit = start

  try {
    await apiRequest(`/devices/${device.id}/position`, {
      method: 'PATCH',
      body: JSON.stringify({ rack_id: nachRackId, start_unit: start }),
    })

    await loadDevices(vonRackId)

    if (nachRackId !== vonRackId) {
      await loadDevices(nachRackId)
      showSuccess(`„${device.name}" nach „${zielRack.name}" verschoben (${heBereich(start, getDeviceHeight(device))}).`)
    }

    // Panel auf das frisch geladene Objekt zeigen lassen
    if (selectedDevice.value?.id === device.id) {
      selectedDevice.value = allDevices.value.find((item) => item.id === device.id) || selectedDevice.value
    }
  } catch (error) {
    device.start_unit = vorher
    showError(error.message)
  } finally {
    rackDragSaving.value = false
  }
}

// Vorschau im Rack: Position in Pixeln aus der gemessenen Zeilenhoehe
const rackDragGhost = computed(() => {
  const drag = rackDrag.value

  if (!drag?.aktiv || drag.zielRack || drag.zielStart === null || !drag.geometrie || !selectedRack.value) {
    return null
  }

  const { oben, links, breite, zeilenHoehe } = drag.geometrie
  const hoehe = getDeviceHeight(drag.device)
  const index = getRackHeight(selectedRack.value) - (drag.zielStart + hoehe - 1)

  return {
    top: `${oben + index * zeilenHoehe + 1}px`,
    left: `${links}px`,
    width: `${breite - 4}px`,
    height: `${hoehe * zeilenHoehe - 2}px`,
  }
})

const rackDragHinweis = computed(() => {
  const drag = rackDrag.value

  if (!drag?.aktiv) return ''

  const hoehe = getDeviceHeight(drag.device)

  if (drag.zielRack) {
    return drag.gueltig
      ? `→ ${drag.zielRack.name}, ${heBereich(drag.zielStart, hoehe)}`
      : `Kein Platz für ${hoehe} HE in „${drag.zielRack.name}"`
  }

  if (drag.zielStart === null) return 'Loslassen bricht ab'
  if (drag.blockiert) return `Belegt durch „${drag.blockiert.name}"`

  return heBereich(drag.zielStart, hoehe)
})

function openDeviceDetails(device) {
  selectedDevice.value = device
  showDeviceDetails.value = true
  loadDevicePorts(device)
}

function deviceLabel(device) {
  const type = deviceTypes.find(
    (item) => item.value === device.device_type
  )

  return type ? type.label : device.device_type
}

const connections = ref([])
const showConnectionForm = ref(false)
const editingConnection = ref(null)
const connectionForm = ref({
  source_device_id: '', source_port_id: '', target_device_id: '', target_port_id: '',
  status: 'active', notes: ''
})

function connectionStatusLabel(status) {
  return { active: 'Aktiv', planned: 'Geplant', faulty: 'Defekt', disconnected: 'Getrennt' }[status || 'active'] || status
}

async function loadConnections() {
  connections.value = await apiRequest('/port-connections')
}
function openNewConnectionForm(device = null) {
  // Bereits geöffnete Dialoge schließen
  showRackForm.value = false
  showDeviceForm.value = false
  showDeviceDetails.value = false
  showPortForm.value = false

  editingConnection.value = null

  connectionForm.value = {
    source_device_id: device?.id || '',
    source_port_id: '',
    target_device_id: '',
    target_port_id: '',
    connection_type: 'direct',
    status: 'active',
    notes: ''
  }

  showConnectionForm.value = true
}
function openEditConnectionForm(connection) {
  editingConnection.value = connection
  connectionForm.value = { ...connection }
  showConnectionForm.value = true
}
const portCache = ref({})
async function loadPortsForDevice(deviceId) {
  if (!deviceId) return []
  const id = Number(deviceId)
  if (!portCache.value[id]) portCache.value[id] = await apiRequest(`/devices/${id}/ports`)
  return portCache.value[id]
}
function portOptions(deviceId) {
  return (portCache.value[Number(deviceId)] || [])
}
function isPortUsed(deviceId, portId) {
  const id = Number(deviceId); const port = Number(portId)
  if (!id || !port) return false
  return connections.value.some(c => {
    if (editingConnection.value && Number(c.id) === Number(editingConnection.value.id)) return false
    return (Number(c.source_device_id) === id && Number(c.source_port_id) === port) || (Number(c.target_device_id) === id && Number(c.target_port_id) === port)
  })
}

async function saveConnection() {
  try {
    const payload = { ...connectionForm.value, source_device_id: Number(connectionForm.value.source_device_id), source_port_id: Number(connectionForm.value.source_port_id), target_device_id: Number(connectionForm.value.target_device_id), target_port_id: Number(connectionForm.value.target_port_id), cable_length: connectionForm.value.cable_length ? String(connectionForm.value.cable_length) + (String(connectionForm.value.cable_length).includes('m') ? '' : ' m') : null }
    if (payload.source_device_id === payload.target_device_id && payload.source_port_id === payload.target_port_id) throw new Error('Quellport und Zielport dürfen nicht identisch sein.')
    if (isPortUsed(payload.source_device_id, payload.source_port_id) || isPortUsed(payload.target_device_id, payload.target_port_id)) throw new Error('Mindestens einer der ausgewählten Ports ist bereits belegt.')
    if (editingConnection.value) await apiRequest(`/port-connections/${editingConnection.value.id}`, { method: 'PUT', body: JSON.stringify(payload) })
    else await apiRequest('/port-connections', { method: 'POST', body: JSON.stringify(payload) })
    showConnectionForm.value = false; await loadConnections(); await refreshPortOverview(); showSuccess('Verbindung gespeichert.')
  } catch (error) { showError(error.message) }
}
async function deleteConnection(connection) {
  if (!confirm('Verbindung wirklich löschen?')) return
  try { await apiRequest(`/port-connections/${connection.id}`, { method: 'DELETE' }); await loadConnections(); await refreshPortOverview(); showSuccess('Verbindung gelöscht.') } catch (error) { showError(error.message) }
}
function connectionsForDevice(device) {
  return connections.value.filter(c => Number(c.source_device_id) === Number(device.id) || Number(c.target_device_id) === Number(device.id))
}

// Erst die Anmeldung pruefen - geladen wird nur mit gueltiger Sitzung
onMounted(checkAuth)

const devicePorts = ref([])
const showPortForm = ref(false)
const editingPort = ref(null)
const portForm = ref({ name: '', port_type: 'ethernet', speed: '1G', poe: null, status: 'free', vlan: '', notes: '' })
const portTypes = [
  { value: 'ethernet', label: 'Ethernet' }, { value: 'sfp', label: 'SFP' },
  { value: 'sfp+', label: 'SFP+' }, { value: 'sfp28', label: 'SFP28' },
  { value: 'qsfp', label: 'QSFP' }, { value: 'fiber', label: 'Glasfaser' },
  { value: 'power', label: 'Strom' }, { value: 'console', label: 'Console' },
  { value: 'usb', label: 'USB' }, { value: 'other', label: 'Sonstige' },
]
const portStatuses = [
  { value: 'free', label: 'Frei' }, { value: 'occupied', label: 'Belegt' },
  { value: 'faulty', label: 'Defekt' }, { value: 'disabled', label: 'Deaktiviert' },
]
async function loadDevicePorts(device) {
  if (!device?.id) return

  const ports = await apiRequest(`/devices/${device.id}/ports`)

  // Bei schnellem Wechsel kann eine aeltere Antwort zuletzt eintreffen
  if (selectedDevice.value?.id === device.id) {
    devicePorts.value = ports
  }
}

// --- Steckplaetze einer Leiste oder USV -------------------------

const deviceOutlets = ref([])

async function loadDeviceOutlets(device) {
  // Andere Geraete haben keine Steckplaetze - dann gar nicht erst fragen.
  if (!device?.id || !OUTLET_CAPABLE_TYPES.includes(device.device_type)) {
    deviceOutlets.value = []
    return
  }

  try {
    const plaetze = await apiRequest(`/devices/${device.id}/outlets`)

    if (selectedDevice.value?.id === device.id) {
      deviceOutlets.value = plaetze
    }
  } catch (error) {
    showError(error.message)
  }
}

/**
 * Ein Steckplatz wird immer vollstaendig geschrieben: Der Server
 * ersetzt den Datensatz, deshalb kommen die unveraenderten Felder
 * mit. Steckt das Geraet schon woanders, antwortet er mit 409 - dann
 * wird gefragt und auf Wunsch umgesteckt.
 */
async function saveOutlet(platz, aenderung, erzwingen = false) {
  const inhalt = {
    label: platz.label ?? null,
    notes: platz.notes ?? null,
    connected_device_id: platz.connected_device_id ?? null,
    external_label: platz.external_label ?? null,
    ...aenderung,
    force: erzwingen,
  }

  try {
    deviceOutlets.value = await apiRequest(
      `/devices/${selectedDevice.value.id}/outlets/${platz.id}`,
      { method: 'PUT', body: JSON.stringify(inhalt) }
    )
  } catch (error) {
    if (error.status === 409 && !erzwingen) {
      if (confirm(`${error.message}\n\nDort ausstecken und hier anschließen?`)) {
        await saveOutlet(platz, aenderung, true)
      } else {
        // Auswahl zurueck auf den gespeicherten Stand
        await loadDeviceOutlets(selectedDevice.value)
      }

      return
    }

    showError(error.message)
    await loadDeviceOutlets(selectedDevice.value)
  }
}
function openNewPortForm() {
  editingPort.value = null
  portForm.value = { name: `Port ${devicePorts.value.length + 1}`, port_type: 'ethernet', speed: '1G', poe: null, status: 'free', vlan: '', notes: '' }
  showPortForm.value = true
}
function openEditPortForm(port) { editingPort.value = port; portForm.value = { ...port }; showPortForm.value = true }
async function savePort() {
  try {
    const url = editingPort.value ? `/devices/${selectedDevice.value.id}/ports/${editingPort.value.id}` : `/devices/${selectedDevice.value.id}/ports`
    await apiRequest(url, { method: editingPort.value ? 'PUT' : 'POST', body: JSON.stringify(portForm.value) })
    showPortForm.value = false
    await loadDevicePorts(selectedDevice.value); await refreshPortOverview()
    showSuccess('Port gespeichert.')
  } catch (error) { showError(error.message) }
}
async function deletePort(port) {
  if (!confirm(`Port "${port.name}" wirklich löschen?`)) return
  try { await apiRequest(`/devices/${selectedDevice.value.id}/ports/${port.id}`, { method: 'DELETE' }); await loadDevicePorts(selectedDevice.value); await refreshPortOverview(); showSuccess('Port gelöscht.') } catch (error) { showError(error.message) }
}

</script>

<template>
  <div v-if="!authChecked" class="auth-loading">RackView wird geladen …</div>

  <LoginView
    v-else-if="!currentUser"
    :has-users="hasUsers"
    :registration-open="registrationOpen"
    :two-factor-pending="twoFactorPending"
    :busy="loginBusy"
    :error="loginError"
    :oidc="oidcInfo"
    @login="login"
    @two-factor="verifyTwoFactor"
    @cancel-two-factor="cancelTwoFactor"
    @register="register"
    @clear-error="loginError = ''"
  />

  <div v-else class="app-shell">
    <aside class="sidebar">
      <div class="brand">
        <img src="/rackview-logo.png" alt="RackView" class="brand-logo" />

        <!-- Nur auf schmalen Geraeten sichtbar; zeigt nebenbei, wo man
             gerade ist. -->
        <button
          type="button"
          class="nav-toggle"
          :aria-expanded="navOffen ? 'true' : 'false'"
          aria-controls="hauptnavigation"
          @click="navOffen = !navOffen"
        >
          <span class="nav-toggle-icon">{{ navOffen ? '✕' : '☰' }}</span>
          <span class="nav-toggle-text">{{ navOffen ? 'Menü schließen' : currentViewMeta.title }}</span>
        </button>
      </div>
      <nav
        id="hauptnavigation"
        class="sidebar-nav"
        :class="{ offen: navOffen }"
        aria-label="Hauptnavigation"
      >
        <template v-for="gruppe in navGroups" :key="gruppe.label">
          <div class="nav-section-label">{{ gruppe.label }}</div>

          <a
            v-for="item in gruppe.items"
            :key="item.key"
            class="nav-item"
            :class="{ active: activeNav === item.key }"
            :href="`#${item.key}`"
            :aria-current="activeNav === item.key ? 'page' : undefined"
            @click="onNavClick(item)"
          >
            <i class="nav-icon">{{ item.icon }}</i>
            <span>{{ item.label }}</span>
          </a>
        </template>
      </nav>
      <div class="sidebar-user">
        <a
          class="sidebar-user-link"
          href="#settings"
          title="Profil und Einstellungen"
          @click="onNavClick({ key: 'settings' })"
        >
          <div class="avatar">{{ userInitials }}</div>
          <div><strong>{{ currentUser.name }}</strong><small>{{ currentUser.email }}</small></div>
        </a>
        <button type="button" class="sidebar-logout" title="Abmelden" aria-label="Abmelden" @click="logout">
          ⎋
        </button>
      </div>
    </aside>
    <header class="topbar">
      <div class="page-header">
        <div class="page-header-top">
          <nav class="breadcrumb" aria-label="Pfad">
            <template v-if="!rackContext">
              RackView <span>/</span> {{ currentViewMeta.title }}
            </template>
            <template v-else-if="selectedRack">
              Racks <span>/</span> {{ selectedRack.location || 'Ohne Standort' }}
            </template>
            <template v-else>
              RackView <span>/</span> Übersicht
            </template>
          </nav>

          <div class="topbar-actions">
            <button
              type="button"
              class="theme-toggle"
              :title="themeAktiv === 'dark' ? 'Zur hellen Ansicht wechseln' : 'Zur dunklen Ansicht wechseln'"
              :aria-label="themeAktiv === 'dark' ? 'Zur hellen Ansicht wechseln' : 'Zur dunklen Ansicht wechseln'"
              @click="themeUmschalten"
            >
              {{ themeAktiv === 'dark' ? '☀' : '☾' }}
            </button>

            <div class="global-search">
              <span class="global-search-icon" aria-hidden="true">⌕</span>

              <input
                ref="globalSearchInput"
                v-model="globalQuery"
                type="text"
                placeholder="Suche nach Geräten, IPs, MAC …"
                aria-label="Geräte durchsuchen"
                autocomplete="off"
                spellcheck="false"
                @focus="globalSearchOpen = true"
                @keydown="onGlobalSearchKeydown"
              />

              <kbd class="global-search-kbd">{{ searchShortcutLabel }}</kbd>

              <div
                v-if="globalSearchOpen && globalQuery.trim().length >= 2"
                class="global-search-results"
                role="listbox"
                aria-label="Suchergebnisse"
              >
                <button
                  v-for="(treffer, index) in globalResults"
                  :key="treffer.device.id"
                  type="button"
                  role="option"
                  class="global-search-result"
                  :class="{ active: index === globalSearchIndex }"
                  :aria-selected="index === globalSearchIndex"
                  @mouseenter="globalSearchIndex = index"
                  @click="openSearchResult(treffer)"
                >
                  <span class="global-search-result-icon">
                    {{ getDeviceIcon(treffer.device.device_type) }}
                  </span>

                  <span class="global-search-result-info">
                    <strong>{{ treffer.device.name }}</strong>
                    <small>
                      {{ treffer.rack ? treffer.rack.name : 'Ohne Rack' }}
                      · HE {{ treffer.device.start_unit }}
                    </small>
                  </span>

                  <span
                    v-if="treffer.grund.label !== 'Name'"
                    class="global-search-result-match"
                  >
                    {{ treffer.grund.label }}: {{ treffer.grund.wert }}
                  </span>
                </button>

                <p v-if="!globalResults.length" class="global-search-empty">
                  Keine Geräte gefunden.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="page-header-main">
          <div class="page-title">
            <h1>
              {{ !rackContext ? currentViewMeta.title : selectedRack ? selectedRack.name : 'Rack-Dokumentation' }}

              <span
                v-if="rackContext && selectedRack"
                class="status-pill"
                :class="`status-pill-${rackStatus.tone}`"
              >
                <i></i> {{ rackStatus.label }}
              </span>
            </h1>

            <p>{{ rackContext ? pageSubtitle : currentViewMeta.subtitle }}</p>
          </div>

          <div v-if="rackContext" class="page-actions">
            <template v-if="selectedRack">
              <button
                class="secondary-button"
                type="button"
                @click="openEditRackForm(selectedRack)"
              >
                ✎ Rack bearbeiten
              </button>

              <button class="secondary-button" type="button" @click="printRack">
                ⎙ Als PDF drucken
              </button>
            </template>

            <button
              v-else
              class="primary-button"
              type="button"
              @click="openNewRackForm"
            >
              + Rack hinzufügen
            </button>

            <div v-if="selectedRack" class="page-menu">
              <button
                class="secondary-button page-menu-toggle"
                type="button"
                aria-label="Weitere Aktionen"
                aria-haspopup="menu"
                :aria-expanded="showPageMenu"
                @click="showPageMenu = !showPageMenu"
              >
                ⋯
              </button>

              <div v-if="showPageMenu" class="page-menu-list" role="menu">
                <button
                  type="button"
                  role="menuitem"
                  @click="showPageMenu = false; openNewRackForm()"
                >
                  Neues Rack anlegen
                </button>

                <button
                  type="button"
                  role="menuitem"
                  class="danger"
                  @click="showPageMenu = false; deleteRack(selectedRack)"
                >
                  Rack löschen …
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>

    <div v-if="errorMessage" class="alert error-alert">
      {{ errorMessage }}
    </div>

    <div v-if="successMessage" class="alert success-alert">
      {{ successMessage }}
    </div>

    <main class="dashboard-layout">

  <IpView
    v-if="currentView === 'ip'"
    :devices="allDevices"
    :racks="racks"
    :locations="locations"
    @select-device="openDeviceFromView"
  />

  <VlanView
    v-else-if="currentView === 'vlans'"
    :devices="allDevices"
    :racks="racks"
    :locations="locations"
    @select-device="openDeviceFromView"
  />

  <DevicesView
    v-else-if="currentView === 'devices'"
    :devices="allDevices"
    :racks="racks"
    :locations="locations"
    @select-device="openDeviceFromView"
    @add="openNewDeviceFromView"
  />

  <LocationsView
    v-else-if="currentView === 'locations'"
    :locations="locations"
    :devices="allDevices"
    @select-device="openDeviceFromView"
    @add-location="openNewLocationForm"
    @edit-location="openEditLocationForm"
    @add-device="openNewDeviceForm"
  />

  <ConnectionsView
    v-else-if="currentView === 'connections'"
    :connections="connections"
    :devices="allDevices"
    @select-device="openDeviceFromView"
    @add="openNewConnectionForm()"
    @edit="openEditConnectionForm"
    @delete="deleteConnection"
  />

  <section v-else-if="currentView === 'network'" class="panel network-view-panel">
    <NetworkPlan
      :devices="allDevices"
      :connections="connections"
      :racks="racks"
      :locations="locations"
      :selected-device-id="selectedDevice ? selectedDevice.id : null"
      @select-device="openDeviceFromView"
      @add-connection="openNewConnectionForm()"
    />
  </section>

  <ExportView
    v-else-if="currentView === 'export'"
    :devices="allDevices"
    :racks="racks"
    :locations="locations"
    :connections="connections"
    :api="apiRequest"
    @print-rack="printRackFromExport"
    @imported="onImported"
  />

  <LabelsView
    v-else-if="currentView === 'labels'"
    :devices="allDevices"
    :racks="racks"
    :locations="locations"
    :preselect="labelPreselect"
  />

  <SettingsView
    v-else-if="currentView === 'settings'"
    :user="currentUser"
    :api="apiRequest"
    :theme="themeWunsch"
    @theme="themeSetzen"
    @user-updated="onUserUpdated"
    @account-deleted="onAccountDeleted"
  />

  <template v-else>
  <RacksOverview
    v-if="currentView === 'racks'"
    :racks="racks"
    :selected-rack-id="selectedRack ? selectedRack.id : null"
    :drop-rack-id="rackDrag && rackDrag.zielRack ? rackDrag.zielRack.id : null"
    :drop-ok="!!(rackDrag && rackDrag.gueltig)"
    @select="selectRack"
    @add="openNewRackForm"
  />

  <!-- Obere Reihe: Dashboard und Racks-Seite -->
  <section v-if="currentView !== 'ports'" class="dashboard-top-row">

    <!-- Rack-Ansicht -->
    <section id="panel-racks" class="panel rack-view-panel">
      <div class="panel-heading">
        <div>
          <span class="panel-kicker">Infrastruktur</span>
          <h2>Rack-Ansicht</h2>
        </div>

        <div class="panel-actions">
          <button
            v-if="selectedRack"
            class="icon-button"
            title="Rack bearbeiten"
            @click="openEditRackForm(selectedRack)"
          >
            ✎
          </button>

          <button
            class="primary-button compact"
            @click="openNewRackForm"
          >
            + Rack
          </button>
        </div>
      </div>

      <!-- Auf der Racks-Seite uebernehmen die Rack-Karten die Auswahl -->
      <div v-if="racks.length && currentView !== 'racks'" class="rack-selector">
        <button
          v-for="rack in racks"
          :key="rack.id"
          class="rack-selector-item"
          :class="{
            active: selectedRack && selectedRack.id === rack.id,
            'drop-target': rackDrag && rackDrag.zielRack && rackDrag.zielRack.id === rack.id,
            'drop-invalid': rackDrag && rackDrag.zielRack && rackDrag.zielRack.id === rack.id && !rackDrag.gueltig,
          }"
          :data-rack-drop="rack.id"
          @click="selectRack(rack)"
        >
          <span class="rack-selector-name">{{ rack.name }}</span>
          <span class="rack-selector-meta">{{ rack.height_units }} HE</span>
        </button>
      </div>

      <div v-if="selectedRack" class="rack-stage">
        <div class="rack-stage-header">
          <div>
            <strong>{{ selectedRack.name }}</strong>
            <span>{{ selectedRack.location || 'Kein Standort hinterlegt' }}</span>
          </div>

          <div class="rack-stage-actions">
            <div class="rack-side-switch" role="group" aria-label="Rackansicht">
              <button
                type="button"
                :class="{ active: rackSide === 'front' }"
                :aria-pressed="rackSide === 'front'"
                @click="rackSide = 'front'"
              >
                Vorderseite
              </button>
              <button
                type="button"
                :class="{ active: rackSide === 'rear' }"
                :aria-pressed="rackSide === 'rear'"
                @click="rackSide = 'rear'"
              >
                Rückseite
              </button>
            </div>

            <div class="rack-stage-meta">{{ getRackHeight(selectedRack) }} HE</div>
          </div>
        </div>

        <div class="rack-layout">
          <div class="rack-visual-wrapper">
            <div ref="rackVisualRef" class="rack-visual">
              <div
                v-for="row in rackUnits"
                :key="row.unit"
                class="rack-unit-row"
                :class="{ 'unit-device-start': row.isTopUnit }"
              >
                <span class="rack-unit-number">{{ row.unit }}</span>

                <div class="rack-unit-content">
                  <!-- Ein Gerät wird nur in seiner obersten Einheit gezeichnet
                       und reicht per CSS über seine gesamte Höhe nach unten. -->
                  <div
                    v-if="row.isTopUnit"
                    class="rack-device-block"
                    :class="{
                      active: selectedDevice && selectedDevice.id === row.device.id,
                      dragging: rackDrag && rackDrag.aktiv && rackDrag.device.id === row.device.id,
                    }"
                    :style="{ '--device-span': getDeviceHeight(row.device) }"
                    :title="`${row.device.name} – zum Verschieben ziehen`"
                    @pointerdown="startRackDrag($event, row.device)"
                    @click="onRackDeviceClick(row.device)"
                    @contextmenu="rackDrag && $event.preventDefault()"
                  >
                    <!-- Im Rack immer die gezeichnete Blende; das Foto des
                         echten Geraets zeigt das Geraetedetails-Panel. -->
                    <DeviceFaceplate :device="row.device" :side="blendenSeite(row.device)" />
                  </div>

                  <!-- Frei auf dieser Seite; auf der anderen steht eventuell etwas -->
                  <span v-else-if="row.gegenueber" class="free-unit-label gegenueber">
                    {{ rackSide === 'front' ? 'hinten' : 'vorne' }}: {{ row.gegenueber.name }}
                  </span>

                  <span v-else-if="!row.device" class="free-unit-label">Frei</span>
                </div>
              </div>

              <!-- Vorschau beim Ziehen: rastet auf Hoeheneinheiten ein -->
              <div
                v-if="rackDragGhost"
                class="rack-drop-ghost"
                :class="{ invalid: !rackDrag.gueltig }"
                :style="rackDragGhost"
                aria-hidden="true"
              >
                <DeviceFaceplate :device="rackDrag.device" :side="blendenSeite(rackDrag.device)" />
              </div>
            </div>
          </div>

          <div class="rack-device-list">
            <div
              v-for="device in devicesTopDown"
              :key="device.id"
              class="rack-device-row"
              :class="{ active: selectedDevice && selectedDevice.id === device.id }"
              @click="selectDevice(device)"
            >
              <span class="rack-device-row-badge">
                {{ getDeviceHeight(device) }} HE
              </span>

              <span
                v-if="getMountSide(device) !== 'full'"
                class="rack-device-row-side"
                :title="getMountSide(device) === 'front' ? 'Nur vorne eingebaut' : 'Nur hinten eingebaut'"
              >
                {{ getMountSide(device) === 'front' ? 'vorne' : 'hinten' }}
              </span>

              <span class="rack-device-row-info">
                <strong>
                  <span
                    class="rack-device-row-status"
                    :class="`status-${device.status || 'active'}`"
                  ></span>
                  {{ device.name }}
                </strong>
                <small>{{ getDeviceSubtitle(device) }}</small>
              </span>

              <span class="rack-device-row-icon">
                {{ getDeviceIcon(device.device_type) }}
              </span>
            </div>

            <p v-if="!devicesTopDown.length" class="rack-device-list-empty">
              Noch keine Geräte in diesem Rack.
            </p>
          </div>
        </div>

        <div class="rack-legend">
          <span><span class="legend-dot legend-active"></span>Aktiv</span>
          <span><span class="legend-dot legend-warning"></span>Geplant / Wartung</span>
          <span><span class="legend-dot legend-inactive"></span>Ausgemustert</span>

          <span class="rack-legend-spacer"></span>

          <span class="rack-legend-hint">Geräte zum Verschieben ziehen, auch auf ein anderes Rack</span>

          <span>
            {{ getUsedRackUnits(selectedRack) }} von
            {{ getRackHeight(selectedRack) }} HE belegt
          </span>
        </div>

        <div class="rack-summary">
          <div class="summary-item">
            <span>Geräte</span>
            <strong>{{ selectedRack.devices?.length || 0 }}</strong>
          </div>

          <div class="summary-item">
            <span>Belegt</span>
            <strong>{{ getUsedRackUnits(selectedRack) }} HE</strong>
          </div>

          <div class="summary-item">
            <span>Frei</span>
            <strong>{{ getRackHeight(selectedRack) - getUsedRackUnits(selectedRack) }} HE</strong>
          </div>
        </div>
      </div>

      <div v-else class="empty-state">
        <div class="empty-state-icon">▤</div>
        <h3>Noch kein Rack vorhanden</h3>
        <p>Lege dein erstes Rack an, um Geräte und Ports zu verwalten.</p>
        <button class="primary-button" @click="openNewRackForm">
          Erstes Rack erstellen
        </button>
      </div>
    </section>

    <!-- Gerätedetails -->
    <section id="panel-device" class="panel device-overview-panel">
      <div class="panel-heading">
        <div>
          <span class="panel-kicker">Auswahl</span>
          <h2>Gerätedetails</h2>
        </div>

        <div v-if="selectedDevice" class="device-panel-actions">
          <button class="secondary-button" type="button" @click="openDeviceDetails(selectedDevice)">
            Verwalten
          </button>

          <button
            class="secondary-button"
            type="button"
            title="QR-Etikett für dieses Gerät drucken"
            @click="openLabelsFor(selectedDevice)"
          >
            ⌗ Etikett
          </button>

          <button class="secondary-button" type="button" @click="openEditDeviceForm(selectedDevice)">
            ✎ Bearbeiten
          </button>
        </div>
      </div>

      <DeviceDetailsPanel
        v-if="selectedDevice"
        :device="selectedDevice"
        :ports="devicePorts"
        :rack="racks.find((rack) => rack.id === selectedDevice.rack_id) || null"
        :location="locations.find((ort) => ort.id === selectedDevice.location_id) || null"
        :selected-port-id="selectedPort ? selectedPort.id : null"
        :uploading="imageUploading"
        :outlets="deviceOutlets"
        :devices="allDevices"
        :start-tab="deviceDetailsTab"
        @select-port="selectPort($event, selectedDevice)"
        @upload-image="uploadImageForSelected"
        @save-outlet="saveOutlet"
      />

      <div v-else class="empty-state compact-empty">
        <div class="empty-state-icon">▣</div>
        <h3>Kein Gerät ausgewählt</h3>
        <p>Wähle ein Gerät in der Rack-Ansicht aus.</p>
      </div>
    </section>
  </section>

  <!-- Untere Reihe -->
  <!-- Untere Reihe: Dashboard und Ports-Seite -->
  <section
    v-if="currentView !== 'racks'"
    class="dashboard-bottom-row"
    :class="{ 'ports-layout': currentView === 'ports' }"
  >

    <!-- Port-Übersicht -->
    <section id="panel-ports" class="panel port-overview-panel">
      <div class="panel-heading">
        <div>
          <span class="panel-kicker">{{ nurSteckplaetze ? 'Stromversorgung' : 'Verbindungen' }}</span>
          <h2>{{ nurSteckplaetze ? 'Steckplätze' : 'Port-Übersicht' }}</h2>
        </div>

        <select
          v-if="portDeviceGroups.length"
          v-model="portOverviewDeviceId"
          class="port-device-select"
          aria-label="Gerät für die Anschlussübersicht"
        >
          <optgroup v-for="gruppe in portDeviceGroups" :key="gruppe.id" :label="gruppe.label">
            <option v-for="device in gruppe.devices" :key="device.id" :value="device.id">
              {{ device.name }}
            </option>
          </optgroup>
        </select>
      </div>

      <p v-if="overviewLoading" class="muted-empty">Anschlüsse werden geladen …</p>

      <template v-else-if="overviewPorts.length || overviewOutlets.length">
        <template v-if="overviewPorts.length">
          <h3 v-if="overviewOutlets.length" class="port-gruppen-titel erste">Netzwerkports</h3>

          <div class="port-grid">
            <button
              v-for="(port, index) in overviewPorts"
              :key="port.id"
              type="button"
              class="port-cell"
              :class="[
                `port-${portState(port)}`,
                {
                  uplink: isUplinkPort(port),
                  poe: !!port.poe,
                  active: selectedPort && selectedPort.id === port.id,
                },
              ]"
              :title="`${port.name} – ${portStateLabel(port)}${port.poe ? ' · ' + getPoeLabel(port.poe) : ''}`"
              @click="selectPort(port)"
            >
              <span class="port-cell-number">{{ portNumber(port, index) }}</span>
              <PortSymbol class="port-cell-jack" :port="port" :zustand="portState(port)" />
            </button>
          </div>

          <div class="port-legend">
            <span><i class="port-legend-dot port-connected"></i>Verbunden ({{ portCounts.connected }})</span>
            <span><i class="port-legend-dot port-free"></i>Frei ({{ portCounts.free }})</span>
            <span v-if="portCounts.faulty"><i class="port-legend-dot port-faulty"></i>Defekt ({{ portCounts.faulty }})</span>
            <span v-if="portCounts.disabled"><i class="port-legend-dot port-disabled"></i>Deaktiviert ({{ portCounts.disabled }})</span>
            <span v-if="portCounts.poe"><i class="port-legend-dot poe"></i>PoE ({{ portCounts.poe }})</span>
            <span v-if="portCounts.uplink"><i class="port-legend-dot uplink"></i>SFP / Uplink ({{ portCounts.uplink }})</span>
          </div>
        </template>

        <!-- Steckplaetze einer Leiste oder USV. Hat das Geraet beides,
             stehen sie unter den Buchsen und bekommen eine Ueberschrift. -->
        <template v-if="overviewOutlets.length">
          <h3 v-if="overviewPorts.length" class="port-gruppen-titel">Steckplätze</h3>

          <div class="port-grid">
            <button
              v-for="platz in overviewOutlets"
              :key="`platz-${platz.id}`"
              type="button"
              class="port-cell outlet-cell"
              :class="{
                belegt: outletBelegt(platz),
                active: selectedOutlet && selectedOutlet.id === platz.id,
              }"
              :title="`Platz ${platz.position} – ${outletBelegt(platz) ? outletName(platz) : 'frei'}`"
              @click="selectOutlet(platz)"
            >
              <span class="port-cell-number">{{ platz.position }}</span>
              <OutletSymbol class="port-cell-jack" :belegt="outletBelegt(platz)" />
            </button>
          </div>

          <div class="port-legend">
            <span><i class="port-legend-dot belegt"></i>Belegt ({{ outletCounts.belegt }})</span>
            <span><i class="port-legend-dot"></i>Frei ({{ outletCounts.frei }})</span>
          </div>
        </template>
      </template>

      <div v-else class="empty-state compact-empty">
        <div class="empty-state-icon">⇄</div>
        <h3>Nichts erfasst</h3>

        <p v-if="portOverviewDevice">
          Für „{{ portOverviewDevice.name }}" sind weder Ports noch Steckplätze
          angelegt. Beides richtest du in den Gerätedetails ein.
        </p>
        <p v-else>In diesem Rack sind noch keine Geräte.</p>

        <button
          v-if="portOverviewDevice"
          class="secondary-button"
          type="button"
          @click="openDeviceDetails(portOverviewDevice)"
        >
          Gerät öffnen
        </button>
      </div>
    </section>

    <!-- Port-Details -->
    <section class="panel port-details-panel">
      <div class="panel-heading">
        <div>
          <span class="panel-kicker">Anschluss</span>
          <h2>{{ selectedOutlet ? 'Platz-Details' : 'Port-Details' }}</h2>
        </div>
      </div>

      <div v-if="selectedPort" class="port-detail-content">
        <div class="port-detail-header">
          <div class="port-large-icon">
            <PortSymbol class="port-large-jack" :port="selectedPort" :zustand="portState(selectedPort)" />
          </div>

          <div class="port-detail-title">
            <h3>{{ selectedPort.name }}</h3>
            <p>{{ selectedPortDevice ? selectedPortDevice.name : '' }}</p>
          </div>

          <span class="port-state-badge" :class="`port-${portState(selectedPort)}`">
            {{ portStateLabel(selectedPort) }}
          </span>
        </div>

        <dl class="detail-list">
          <div>
            <dt>Typ</dt>
            <dd>{{ portTypeLabel(selectedPort.port_type) }}</dd>
          </div>

          <div>
            <dt>Geschwindigkeit</dt>
            <dd>{{ selectedPort.speed || '–' }}</dd>
          </div>

          <div>
            <dt>PoE</dt>
            <dd>{{ selectedPort.poe ? getPoeLabel(selectedPort.poe) : 'Kein PoE' }}</dd>
          </div>

          <div>
            <dt>VLAN</dt>
            <dd>{{ selectedPort.vlan || '–' }}</dd>
          </div>

          <div>
            <dt>Verbunden mit</dt>
            <dd>
              <template v-if="selectedPort.connection?.peer_device">
                {{ selectedPort.connection.peer_device.name }}
                <span v-if="selectedPort.connection.peer_port" class="port-peer-port">
                  · {{ selectedPort.connection.peer_port.name }}
                </span>
              </template>
              <template v-else>–</template>
            </dd>
          </div>

          <div v-if="selectedPort.notes">
            <dt>Notizen</dt>
            <dd>{{ selectedPort.notes }}</dd>
          </div>
        </dl>
      </div>

      <div v-else-if="selectedOutlet" class="port-detail-content">
        <div class="port-detail-header">
          <div class="port-large-icon">
            <OutletSymbol class="port-large-jack" :belegt="outletBelegt(selectedOutlet)" />
          </div>

          <div class="port-detail-title">
            <h3>Platz {{ selectedOutlet.position }}</h3>
            <p>{{ portOverviewDevice ? portOverviewDevice.name : '' }}</p>
          </div>

          <span
            class="port-state-badge"
            :class="outletBelegt(selectedOutlet) ? 'port-connected' : 'port-free'"
          >
            {{ outletBelegt(selectedOutlet) ? 'Belegt' : 'Frei' }}
          </span>
        </div>

        <dl class="detail-list">
          <div>
            <dt>Angeschlossen</dt>
            <dd>
              <!-- Ein Geraet aus dem eigenen Bereich fuehrt zu sich selbst -->
              <template v-if="selectedOutlet.connected_device">
                <button
                  type="button"
                  class="port-peer-link"
                  @click="openDeviceFromView(selectedOutlet.connected_device)"
                >
                  {{ selectedOutlet.connected_device.name }}
                </button>
                <span class="port-peer-port">
                  · {{ getDeviceTypeLabel(selectedOutlet.connected_device.device_type) }}
                </span>
              </template>

              <!-- Oder freier Text fuer alles, was nicht im Rack steht -->
              <template v-else-if="selectedOutlet.external_label">
                {{ selectedOutlet.external_label }}
                <span class="port-peer-port">· außerhalb des Racks</span>
              </template>

              <template v-else>–</template>
            </dd>
          </div>

          <div v-if="selectedOutlet.connected_device && rackName(selectedOutlet.connected_device.rack_id)">
            <dt>Steht in</dt>
            <dd>{{ rackName(selectedOutlet.connected_device.rack_id) }}</dd>
          </div>

          <div v-if="selectedOutlet.label">
            <dt>Beschriftung</dt>
            <dd>{{ selectedOutlet.label }}</dd>
          </div>

          <div v-if="selectedOutlet.notes">
            <dt>Notizen</dt>
            <dd>{{ selectedOutlet.notes }}</dd>
          </div>
        </dl>

        <button
          v-if="portOverviewDevice"
          type="button"
          class="secondary-button port-detail-aktion"
          @click="openOutletsOfDevice(portOverviewDevice)"
        >
          Steckplätze bearbeiten
        </button>
      </div>

      <div v-else class="empty-state compact-empty">
        <div class="empty-state-icon">⇄</div>
        <h3>{{ nurSteckplaetze ? 'Kein Platz ausgewählt' : 'Kein Port ausgewählt' }}</h3>
        <p>
          {{ nurSteckplaetze
            ? 'Wähle einen Steckplatz aus der Übersicht.'
            : 'Wähle einen Port aus der Übersicht.' }}
        </p>
      </div>
    </section>

    <!-- Netzwerkplan -->
    <section v-if="isDashboard" id="panel-network" class="panel network-plan-panel">
      <div class="panel-heading">
        <div>
          <span class="panel-kicker">Topologie</span>
          <h2>Netzwerkplan</h2>
        </div>

        <span v-if="connections.length" class="network-plan-count">
          {{ connections.length }}
          {{ connections.length === 1 ? 'Verbindung' : 'Verbindungen' }}
        </span>
      </div>

      <NetworkPlan
        :devices="allDevices"
        :connections="connections"
        :racks="racks"
        :locations="locations"
        :selected-device-id="selectedDevice ? selectedDevice.id : null"
        @select-device="focusDevice"
        @add-connection="openNewConnectionForm()"
      />
    </section>
  </section>
  </template>
</main>

    <!-- Schild am Zeiger: Ziel-HE, Ziel-Rack oder warum es nicht passt -->
    <div
      v-if="rackDrag && rackDrag.aktiv"
      class="rack-drag-badge"
      :class="{ invalid: !rackDrag.gueltig }"
      :style="{ left: `${rackDrag.x + 16}px`, top: `${rackDrag.y + 16}px` }"
      aria-live="polite"
    >
      <strong>{{ rackDrag.device.name }}</strong>
      <span>{{ rackDragHinweis }}</span>
    </div>

    <div v-if="showRackForm" class="modal-backdrop" @click.self="showRackForm = false">
      <div class="modal modal-standard">
        <div class="modal-header">
          <div>
            <span class="eyebrow">INFRASTRUKTUR</span>
            <h2>
              {{ editingRack ? 'Rack bearbeiten' : 'Neues Rack' }}
            </h2>
          </div>

          <button
            type="button"
            class="modal-close"
            aria-label="Fenster schließen"
            @click="showRackForm = false"
          >
            ×
          </button>
        </div>

        <form @submit.prevent="saveRack">
          <label>
            Name
            <input v-model="rackForm.name" required />
          </label>

          <label>
            Standort
            <input v-model="rackForm.location" />
          </label>

          <label>
            Höhe in HE
            <input
              v-model.number="rackForm.height_units"
              type="number"
              min="1"
              max="100"
              required
            />
          </label>

          <label>
            Beschreibung
            <textarea v-model="rackForm.description" rows="3" />
          </label>

          <div class="modal-footer">
            <button
              v-if="editingRack"
              type="button"
              class="danger-button modal-footer-start"
              @click="deleteRackFromForm"
            >
              Rack löschen …
            </button>

            <button
              type="button"
              class="secondary-button"
              @click="showRackForm = false"
            >
              Abbrechen
            </button>

            <button type="submit" class="primary-button">
              Speichern
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showLocationForm" class="modal-backdrop" @click.self="showLocationForm = false">
      <div class="modal modal-standard">
        <div class="modal-header">
          <div>
            <span class="eyebrow">INFRASTRUKTUR</span>
            <h2>
              {{ editingLocation ? 'Standort bearbeiten' : 'Neuer Standort' }}
            </h2>
          </div>

          <button
            type="button"
            class="modal-close"
            aria-label="Fenster schließen"
            @click="showLocationForm = false"
          >
            ×
          </button>
        </div>

        <form @submit.prevent="saveLocation">
          <label>
            Name
            <input v-model="locationForm.name" required placeholder="z. B. Dachboden" />
          </label>

          <label>
            Beschreibung
            <textarea
              v-model="locationForm.description"
              rows="3"
              placeholder="z. B. rechts neben dem Kaminzug, PoE vom Switch im Keller"
            />
          </label>

          <div class="modal-footer">
            <button
              v-if="editingLocation"
              type="button"
              class="danger-button modal-footer-start"
              @click="deleteLocationFromForm"
            >
              Standort löschen …
            </button>

            <button
              type="button"
              class="secondary-button"
              @click="showLocationForm = false"
            >
              Abbrechen
            </button>

            <button type="submit" class="primary-button">
              Speichern
            </button>
          </div>
        </form>
      </div>
    </div>

    <div
      v-if="showConnectionForm"
      class="modal-backdrop"
      @click.self="showConnectionForm = false"
    >
      <div class="modal modal-standard">
        <div class="modal-header">
          <div>
            <span class="eyebrow">NETZWERK</span>
            <h2>
              {{ editingConnection ? 'Verbindung bearbeiten' : 'Neue Verbindung' }}
            </h2>
            <p class="modal-subtitle">
              Verbinde zwei Geräte über ihre Ports.
            </p>
          </div>

          <button
            type="button"
            class="modal-close"
            aria-label="Fenster schließen"
            @click="showConnectionForm = false"
          >
            ×
          </button>
        </div>
        <form @submit.prevent="saveConnection">
          <label>Quellgerät<select v-model="connectionForm.source_device_id" required><option value="" disabled>Bitte wählen</option><option v-for="device in allDevices" :key="device.id" :value="device.id">{{ device.name }}<template v-if="device.rack"> · {{ device.rack.name }}</template></option></select></label>
          <label>Quellport<select v-model="connectionForm.source_port_id" required @focus="loadPortsForDevice(connectionForm.source_device_id)"><option value="" disabled>Port wählen</option><option v-for="port in portOptions(connectionForm.source_device_id)" :key="port.id" :value="port.id">{{ port.name }} · {{ port.status }}</option></select></label>
          <label>Zielgerät<select v-model="connectionForm.target_device_id" required><option value="" disabled>Bitte wählen</option><option v-for="device in allDevices" :key="device.id" :value="device.id">{{ device.name }}<template v-if="device.rack"> · {{ device.rack.name }}</template></option></select></label>
          <label>Zielport<select v-model="connectionForm.target_port_id" required @focus="loadPortsForDevice(connectionForm.target_device_id)"><option value="" disabled>Port wählen</option><option v-for="port in portOptions(connectionForm.target_device_id)" :key="port.id" :value="port.id">{{ port.name }} · {{ port.status }}</option></select></label>
          <label>Status<select v-model="connectionForm.status"><option value="active">Aktiv</option><option value="planned">Geplant</option><option value="faulty">Defekt</option><option value="disconnected">Getrennt</option></select></label>
          <label>Notizen<textarea v-model="connectionForm.notes" rows="3" /></label>
          <div class="modal-footer"><button type="button" class="secondary-button" @click="showConnectionForm = false">Abbrechen</button><button class="primary-button" type="submit">Speichern</button></div>
        </form>
      </div>
    </div>

    <!-- Geräte-Detailfenster -->
<div
  v-if="showDeviceDetails && selectedDevice"
  class="modal-backdrop"
  @click.self="showDeviceDetails = false"
>
  <div class="modal device-details-modal">
    <div class="modal-header">
      <div>
        <h2>{{ selectedDevice.name }}</h2>

        <p class="device-detail-position">
          {{ deviceLabel(selectedDevice) }}
          · {{ selectedDevice.height_units }} HE
        </p>
      </div>

      <button
        type="button"
        class="modal-close"
        @click="showDeviceDetails = false"
      >
        ×
      </button>
    </div>

    <div class="device-details-content">
      <section class="device-detail-section">
        <h3>Allgemeine Informationen</h3>

        <div class="device-detail-grid">
          <div>
            <span class="detail-label">Gerätename</span>
            <strong>{{ selectedDevice.name || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Gerätetyp</span>
            <strong>{{ deviceLabel(selectedDevice) || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Status</span>
            <strong>{{ selectedDevice.status || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Hersteller</span>
            <strong>{{ selectedDevice.manufacturer || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Modell</span>
            <strong>{{ selectedDevice.model || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Seriennummer</span>
            <strong>{{ selectedDevice.serial_number || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Gekauft am</span>
            <strong>{{ formatDate(selectedDevice.purchase_date) || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Garantie</span>
            <strong :class="`garantie-${getWarrantyInfo(selectedDevice).zustand}`">
              {{ getWarrantyInfo(selectedDevice).text }}
            </strong>
          </div>
        </div>
      </section>

      <section class="device-detail-section">
        <div class="section-heading-row">
          <h3>Netzwerkverbindungen</h3>
          <button type="button" class="secondary-button" @click="openNewConnectionForm(selectedDevice)">+ Verbindung</button>
        </div>
        <div v-if="connectionsForDevice(selectedDevice).length" class="connection-list">
          <div v-for="connection in connectionsForDevice(selectedDevice)" :key="connection.id" class="connection-item">
            <div><strong>{{ connection.source_device?.name || connection.source_device_id }}:{{ connection.source_port?.name || ('Port #' + connection.source_port_id) }}</strong> → <strong>{{ connection.target_device?.name || connection.target_device_id }}:{{ connection.target_port?.name || ('Port #' + connection.target_port_id) }}</strong><br><small>{{ connectionStatusLabel(connection.status) }}</small></div>
            <div><button type="button" class="small-button" @click="openEditConnectionForm(connection)">Bearbeiten</button> <button type="button" class="danger-button" @click="deleteConnection(connection)">Löschen</button></div>
          </div>
        </div>
        <p v-else class="muted-text">Noch keine Verbindungen hinterlegt.</p>
      </section>

      <section class="device-detail-section">
        <div class="section-heading-row">
          <h3>Ports</h3>
          <button type="button" class="secondary-button" @click="openNewPortForm">+ Port</button>
        </div>
        <div v-if="devicePorts.length" class="connection-list">
          <div v-for="port in devicePorts" :key="port.id" class="connection-item">
            <div><strong>{{ port.name }}</strong> · {{ port.port_type }} · {{ port.speed || '–' }}<br><small>Status: {{ portStatuses.find(s => s.value === port.status)?.label || port.status }}{{ port.vlan ? ' · VLAN ' + port.vlan : '' }}{{ port.notes ? ' · ' + port.notes : '' }}</small></div>
            <div><button type="button" class="small-button" @click="openEditPortForm(port)">Bearbeiten</button> <button type="button" class="danger-button" @click="deletePort(port)">Löschen</button></div>
          </div>
        </div>
        <p v-else class="muted-text">Noch keine Ports hinterlegt.</p>
        <div
          v-if="showPortForm"
          class="modal-backdrop"
          @click.self="showPortForm = false"
        >
          <div class="modal modal-standard modal-port">
            <div class="modal-header">
              <div>
                <span class="eyebrow">PORT</span>
                <h2>{{ editingPort ? 'Port bearbeiten' : 'Port hinzufügen' }}</h2>
                <p class="modal-subtitle">
                  {{ selectedDevice?.name || 'Gerät' }}
                </p>
              </div>

              <button
                type="button"
                class="modal-close"
                @click="showPortForm = false"
              >
                ×
              </button>
            </div>

            <form @submit.prevent="savePort">
              <div class="modal-section">
                <div class="modal-section-title">
                  <span class="modal-section-icon">⇄</span>
                  <div>
                    <h3>Portinformationen</h3>
                    <p>Technische Angaben zum Anschluss</p>
                  </div>
                </div>

                <div class="form-grid">
                  <label>
                    Portname
                    <input
                      v-model="portForm.name"
                      required
                      placeholder="z. B. LAN 1"
                    />
                  </label>

                  <label>
                    Porttyp
                    <select v-model="portForm.port_type">
                      <option
                        v-for="type in portTypes"
                        :key="type.value"
                        :value="type.value"
                      >
                        {{ type.label }}
                      </option>
                    </select>
                  </label>

                  <label>
                    Geschwindigkeit
                    <input
                      v-model="portForm.speed"
                      placeholder="z. B. 1G, 2.5G, 10G"
                    />
                  </label>

                  <label>
                    PoE
                    <select v-model="portForm.poe">
                      <option :value="null">Kein PoE</option>
                      <option v-for="typ in poeTypes" :key="typ.value" :value="typ.value">
                        {{ typ.label }}
                      </option>
                    </select>
                  </label>

                  <label>
                    Status
                    <select v-model="portForm.status">
                      <option
                        v-for="status in portStatuses"
                        :key="status.value"
                        :value="status.value"
                      >
                        {{ status.label }}
                      </option>
                    </select>
                  </label>

                  <label class="form-group-full">
                    VLAN / Netzwerk
                    <input
                      v-model="portForm.vlan"
                      placeholder="z. B. VLAN 40 oder Management"
                    />
                  </label>

                  <label class="form-group-full">
                    Notizen
                    <textarea
                      v-model="portForm.notes"
                      rows="4"
                      placeholder="Weitere Informationen zum Port"
                    ></textarea>
                  </label>
                </div>
              </div>

              <div class="modal-footer">
                <button
                  type="button"
                  class="secondary-button"
                  @click="showPortForm = false"
                >
                  Abbrechen
                </button>

                <button
                  type="submit"
                  class="primary-button"
                >
                  Port speichern
                </button>
              </div>
            </form>
          </div>
        </div>
      </section>

      <section class="device-detail-section">
        <h3>{{ selectedDevice.rack_id ? 'Rack-Position' : 'Standort' }}</h3>

        <div class="device-detail-grid">
          <template v-if="selectedDevice.rack_id">
            <div>
              <span class="detail-label">Start-Unit</span>
              <strong>{{ selectedDevice.start_unit || '–' }}</strong>
            </div>

            <div>
              <span class="detail-label">Höhe</span>
              <strong>{{ selectedDevice.height_units || '–' }} HE</strong>
            </div>
          </template>

          <div v-else>
            <span class="detail-label">Steht an</span>
            <strong>
              {{ locations.find((ort) => ort.id === selectedDevice.location_id)?.name || '–' }}
            </strong>
          </div>
        </div>
      </section>

      <section class="device-detail-section">
        <h3>Netzwerk</h3>

        <div class="device-detail-grid">
          <div>
            <span class="detail-label">IP-Adresse</span>
            <strong>{{ selectedDevice.ip_address || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">MAC-Adresse</span>
            <strong>{{ selectedDevice.mac_address || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">VLAN</span>
            <strong>{{ selectedDevice.vlan || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Switch-Port</span>
            <strong>{{ selectedDevice.switch_port || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Uplink-Port</span>
            <strong>{{ selectedDevice.uplink_port || '–' }}</strong>
          </div>

          <div>
            <span class="detail-label">Netzwerkports</span>
            <strong>{{ selectedDevice.network_ports || '–' }}</strong>
          </div>
        </div>
      </section>

      <section
        v-if="selectedDevice.notes"
        class="device-detail-section"
      >
        <h3>Netzwerknotizen</h3>

        <p class="device-detail-notes">
          {{ selectedDevice.notes }}
        </p>
      </section>

      <section
        v-if="selectedDevice.description"
        class="device-detail-section"
      >
        <h3>Beschreibung</h3>

        <p class="device-detail-notes">
          {{ selectedDevice.description }}
        </p>
      </section>
    </div>

    <div class="modal-footer">
      <button
        type="button"
        class="secondary-button"
        @click="showDeviceDetails = false"
      >
        Schließen
      </button>

      <button
        type="button"
        class="primary-button"
        @click="
          showDeviceDetails = false;
          openEditDeviceForm(selectedDevice);
        "
      >
        Gerät bearbeiten
      </button>
    </div>
  </div>
</div>

<div v-if="showDeviceForm" class="modal-backdrop" @click.self="showDeviceForm = false">
      <div class="modal modal-large">
        <div class="modal-header">
          <div>
            <span class="eyebrow">INFRASTRUKTUR</span>
            <h2>
              {{ editingDevice ? 'Gerät bearbeiten' : 'Neues Gerät' }}
            </h2>
          </div>

          <button
              type="button"
              class="modal-close"
              aria-label="Fenster schließen"
              @click="showDeviceForm = false"
            >
            ×
          </button>
        </div>

        <form @submit.prevent="saveDevice">
          <!-- Beim Anlegen das Ziel-Rack, beim Bearbeiten zum Verschieben -->
          <div v-if="racks.length + locations.length > 1" class="form-grid device-rack-choice">
            <div class="form-group form-group-full">
              <label for="device-rack">Wo steht das Gerät?</label>

              <!-- Ein Wert fuer beides: "rack:3" oder "ort:5". So ist
                   die Auswahl eindeutig, auch wenn Rack 3 und Standort 3
                   dieselbe Nummer tragen. -->
              <select id="device-rack" :value="deviceFormOrt" @change="changeDeviceFormOrt">
                <optgroup v-if="racks.length" label="Racks">
                  <option v-for="rack in racks" :key="`r${rack.id}`" :value="`rack:${rack.id}`">
                    {{ rack.name }}{{ rack.location ? ` · ${rack.location}` : '' }}
                  </option>
                </optgroup>

                <optgroup v-if="locations.length" label="Standorte">
                  <option v-for="ort in locations" :key="`o${ort.id}`" :value="`ort:${ort.id}`">
                    {{ ort.name }}
                  </option>
                </optgroup>
              </select>

              <small class="form-hint">
                <template v-if="!deviceForm.rack_id">
                  Außerhalb des Racks gibt es keine Höheneinheiten – Position
                  und Einbauseite entfallen.
                </template>
                <template v-else-if="editingDevice && Number(deviceForm.rack_id) !== Number(editingDevice.rack_id)">
                  Das Gerät wird beim Speichern dorthin verschoben –
                  mit seinen Ports und Verbindungen.
                </template>
                <template v-else>
                  Freie Position und Belegung richten sich nach diesem Rack.
                </template>
              </small>
            </div>
          </div>

          <!-- Netzwerkdokumentation -->
          <div
            data-network-fields
            class="network-documentation"
            style="
              margin-top: 20px;
              padding-top: 18px;
              border-top: 1px solid var(--border-color, #333);
            "
          >
            <h3 style="margin-bottom: 14px;">
              Netzwerkdokumentation
            </h3>

            <div class="form-grid">
              <div class="form-group">
                <label for="device-vlan">VLAN</label>
                <input
                  id="device-vlan"
                  v-model="deviceForm.vlan"
                  type="text"
                  placeholder="z. B. 40 oder Management"
                />
              </div>

              <div class="form-group">
                <label for="device-switch-port">Switch-Port</label>
                <input
                  id="device-switch-port"
                  v-model="deviceForm.switch_port"
                  type="text"
                  placeholder="z. B. USW-Aggregation Port 3"
                />
              </div>

              <div class="form-group">
                <label for="device-uplink-port">Uplink-Port</label>
                <input
                  id="device-uplink-port"
                  v-model="deviceForm.uplink_port"
                  type="text"
                  placeholder="z. B. UDM Pro SFP+ 1"
                />
              </div>

              <div class="form-group">
                <label for="device-network-ports">
                  Anzahl Netzwerkports
                </label>
                <input
                  id="device-network-ports"
                  v-model="deviceForm.network_ports"
                  type="number"
                  min="0"
                  max="1000"
                  placeholder="z. B. 4"
                />
                <small class="form-hint">
                  Fehlende Ports werden beim Speichern als „Port 1", „Port 2" …
                  angelegt. Vorhandene bleiben unverändert.
                </small>
              </div>

              <template v-if="POE_CAPABLE_TYPES.includes(deviceForm.device_type)">
                <div class="form-group">
                  <label for="device-poe-ports">davon PoE-Ports</label>
                  <input
                    id="device-poe-ports"
                    v-model="deviceForm.poe_ports"
                    type="number"
                    min="0"
                    :max="Number(deviceForm.network_ports) || 1000"
                    placeholder="z. B. 16"
                  />
                  <small class="form-hint">
                    Höchstens so viele, wie es Netzwerkports gibt. Gilt für die
                    ersten Ports; einzelne Ports lassen sich danach im Port
                    selbst abweichend einstellen.
                  </small>
                </div>

                <div class="form-group">
                  <label for="device-poe-type">PoE-Standard</label>
                  <select id="device-poe-type" v-model="deviceForm.poe_type">
                    <option v-for="typ in poeTypes" :key="typ.value" :value="typ.value">
                      {{ typ.label }}
                    </option>
                  </select>
                  <small class="form-hint">
                    Gilt für die PoE-Ports. Ohne PoE-Ports wird kein Standard
                    gespeichert.
                  </small>
                </div>
              </template>

              <template v-if="OUTLET_CAPABLE_TYPES.includes(deviceForm.device_type)">
                <div class="form-group">
                  <label for="device-outlets">Steckplätze</label>
                  <input
                    id="device-outlets"
                    v-model="deviceForm.outlet_count"
                    type="number"
                    min="0"
                    max="100"
                    placeholder="z. B. 8"
                  />
                  <small class="form-hint">
                    Wie viele Geräte angeschlossen werden können. Wer wo
                    steckt, wird danach in den Gerätedetails eingetragen.
                  </small>
                </div>
              </template>

              <div class="form-group form-group-full">
                <label for="device-notes">
                  Netzwerknotizen
                </label>
                <textarea
                  id="device-notes"
                  v-model="deviceForm.notes"
                  rows="4"
                  placeholder="z. B. Port 1-2 LACP, Port 3 Management, Port 4 PoE"
                ></textarea>
              </div>
            </div>
          </div>

<div class="form-grid">
            <label>
              Name
              <input v-model="deviceForm.name" required />
            </label>

            <label>
              Gerätetyp
              <select v-model="deviceForm.device_type">
                <option
                  v-for="type in deviceTypes"
                  :key="type.value"
                  :value="type.value"
                >
                  {{ type.label }}
                </option>
              </select>
            </label>

            <label>
              Status
              <select v-model="deviceForm.status">
                <option value="active">Aktiv</option>
                <option value="planned">Geplant</option>
                <option value="maintenance">Wartung</option>
                <option value="retired">Außer Betrieb</option>
              </select>
            </label>

            <label>
              Hersteller
              <input v-model="deviceForm.manufacturer" />
            </label>

            <label>
              Modell
              <input v-model="deviceForm.model" />
            </label>

            <!-- Position, Höhe und Seite gibt es nur im Rack -->
            <template v-if="deviceForm.rack_id">
              <div class="device-position-actions">
                <button
                  type="button"
                  class="secondary-button"
                  @click="useNextFreePosition"
                >
                  ↻ Nächste freie Position
                </button>

                <small>
                  Sucht einen freien Bereich passend zur Gerätehöhe.
                </small>
              </div>

              <label>
                Start-Unit
                <input
                  v-model.number="deviceForm.start_unit"
                  type="number"
                  min="1"
                  :max="formRack?.height_units"
                  required
                />
              </label>

              <label>
                Höhe in HE
                <input
                  v-model.number="deviceForm.height_units"
                  type="number"
                  min="1"
                  :max="formRack?.height_units"
                  required
                />
              </label>

              <label>
                Einbauseite
                <select v-model="deviceForm.mount_side">
                  <option v-for="seite in mountSides" :key="seite.value" :value="seite.value">
                    {{ seite.label }}
                  </option>
                </select>
                <small class="feld-hinweis">
                  Halbtiefe Geräte teilen sich eine HE: eines vorne, eines hinten.
                </small>
              </label>
            </template>

            <label>
              Seriennummer
              <input v-model="deviceForm.serial_number" />
            </label>

            <label>
              IP-Adresse
              <input v-model="deviceForm.ip_address" />
            </label>

            <label>
              MAC-Adresse
              <input v-model="deviceForm.mac_address" />
            </label>

            <label>
              Kaufdatum
              <input v-model="deviceForm.purchase_date" type="date" />
            </label>

            <label>
              Garantie bis
              <input v-model="deviceForm.warranty_until" type="date" />

              <span class="garantie-schnellwahl">
                <button
                  v-for="jahre in [1, 2, 3, 5]"
                  :key="jahre"
                  type="button"
                  :disabled="!deviceForm.purchase_date"
                  :title="deviceForm.purchase_date
                    ? `${jahre} Jahre ab Kaufdatum`
                    : 'Trage zuerst das Kaufdatum ein'"
                  @click="setzeGarantie(jahre)"
                >
                  +{{ jahre }} J
                </button>

                <button
                  v-if="deviceForm.warranty_until"
                  type="button"
                  class="loeschen"
                  title="Garantieangabe entfernen"
                  @click="deviceForm.warranty_until = ''"
                >
                  leeren
                </button>
              </span>

              <small class="feld-hinweis">
                Die Knöpfe rechnen ab dem Kaufdatum. Ein abweichendes Ende –
                etwa bei verlängerter Garantie – kannst du direkt eintragen.
              </small>
            </label>
          </div>

          <label>
            Beschreibung
            <textarea v-model="deviceForm.description" rows="3" />
          </label>

          <div v-if="deviceForm.rack_id" class="position-preview">
            <strong>Position:</strong>
            U{{ deviceForm.start_unit }}–U{{
              Number(deviceForm.start_unit) +
              Number(deviceForm.height_units) -
              1
            }}
            von U{{ formRack?.height_units }}

            <span
              v-if="validateDevicePosition()"
              class="position-invalid"
            >
              {{ validateDevicePosition() }}
            </span>

            <span v-else class="position-valid">
              Position verfügbar
            </span>
          </div>

          <!-- Geraetefoto -->
          <div class="modal-section device-image-section">
            <div class="modal-section-title">
              <h3>Gerätefoto</h3>
              <p>
                Ein Foto des echten Geräts für die Gerätedetails. Im Rack
                erscheint weiterhin die gezeichnete Blende.
              </p>
            </div>

            <div class="device-image-editor">
              <div class="device-photo-preview">
                <img
                  v-if="editingDevice ? editingDevice.image_url : pendingImagePreview"
                  :src="editingDevice ? editingDevice.image_url : pendingImagePreview"
                  alt="Foto des Geräts"
                />
                <span v-else class="device-photo-empty">Kein Foto</span>
              </div>

              <!-- Anlegen: Foto vormerken, es wird nach dem Speichern hochgeladen -->
              <div v-if="!editingDevice" class="device-image-actions">
                <label class="secondary-button device-image-upload">
                  {{ pendingImage ? 'Anderes Foto wählen' : 'Foto auswählen' }}
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/avif"
                    @change="choosePendingImage"
                  />
                </label>

                <button v-if="pendingImage" type="button" class="secondary-button" @click="setPendingImage(null)">
                  Auswahl entfernen
                </button>

                <small>Wird beim Speichern mit hochgeladen. JPEG, PNG, WebP oder AVIF, höchstens 4 MB.</small>
              </div>

              <!-- Bearbeiten: sofort hochladen -->
              <div v-else class="device-image-actions">
                <label class="secondary-button device-image-upload">
                  {{ imageUploading ? 'Wird übertragen …' : editingDevice.image_url ? 'Foto ersetzen' : 'Foto auswählen' }}
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/avif"
                    :disabled="imageUploading"
                    @change="uploadDeviceImage"
                  />
                </label>

                <button
                  v-if="editingDevice.image_url"
                  type="button"
                  class="secondary-button"
                  @click="removeDeviceImage"
                >
                  Foto entfernen
                </button>

                <small>JPEG, PNG, WebP oder AVIF, höchstens 4 MB.</small>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button
              v-if="editingDevice"
              type="button"
              class="danger-button modal-footer-start"
              @click="deleteDeviceFromForm"
            >
              Gerät löschen …
            </button>

            <button
              type="button"
              class="secondary-button"
              @click="showDeviceForm = false"
            >
              Abbrechen
            </button>

            <button
              type="submit"
              class="primary-button"
              :disabled="!!validateDevicePosition()"
            >
              Speichern
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* =========================================================
   RackView Design v7
   CSS-only Layout
   Script und Template bleiben unverändert
   ========================================================= */

:root {
  font-family:
    Inter,
    ui-sans-serif,
    system-ui,
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    sans-serif;

  color: var(--t-ton-15);
  background: var(--f-grau-97);

  --rv-blue: #2563eb;
  --rv-blue-dark: #1d4ed8;
  --rv-blue-soft: #eff6ff;
  --rv-text: #172033;
  --rv-muted: #7b879c;
  --rv-border: #e5eaf2;
  --rv-panel: #ffffff;
  --rv-bg: #f5f7fb;
  --rv-shadow: 0 8px 28px rgba(25, 45, 80, 0.055);
}

* {
  box-sizing: border-box;
}

html {
  min-height: 100%;
  scroll-behavior: smooth;
}

body {
  min-width: 320px;
  min-height: 100vh;
  margin: 0;
  background:
    radial-gradient(
      circle at 85% 0%,
      var(--f-schleier-93),
      transparent 32rem
    ),
    var(--rv-bg);
  color: var(--rv-text);
}

/* =========================================================
   Hauptlayout
   ========================================================= */

.app-shell {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 248px minmax(0, 1fr);
  grid-template-rows: auto 1fr;
  gap: 0 28px;
  padding: 18px 28px 32px 18px;
}

/* =========================================================
   Sidebar
   ========================================================= */

.sidebar {
  position: fixed;
  z-index: 30;
  inset: 18px auto 18px 18px;

  width: 248px;
  min-height: calc(100vh - 36px);

  display: flex;
  flex-direction: column;

  padding: 25px 14px 16px;

  border: 1px solid var(--f-ton-92);
  border-radius: 24px;

  background:
    linear-gradient(
      180deg,
      var(--f-schleier-100),
      var(--f-schleier-99)
    );

  box-shadow: 0 14px 45px var(--f-schatten-24);
}

.brand {
  padding: 0 13px 25px;
  border-bottom: 1px solid var(--f-ton-95);
}

.brand-logo {
  display: block;
  width: 174px;
  height: auto;
  object-fit: contain;
  object-position: left center;
}

.sidebar-nav {
  flex: 1;
  padding-top: 20px;
}

.nav-section-label {
  padding: 15px 14px 9px;

  color: var(--t-ton-68);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.13em;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 13px;

  min-height: 42px;
  margin: 4px 0;
  padding: 10px 14px;

  border-radius: 11px;

  color: var(--t-ton-47);
  text-decoration: none;

  font-size: 13px;
  font-weight: 650;

  transition:
    background 0.18s ease,
    color 0.18s ease,
    transform 0.18s ease;
}

.nav-item:hover {
  color: var(--rv-blue);
  background: var(--f-ton-97);
  transform: translateX(2px);
}

.nav-item.active {
  color: var(--t-grau-100);
  background: linear-gradient(135deg, var(--f-blau-61), var(--f-blau-53));
  box-shadow: 0 7px 16px var(--f-blau-53-2);
}

.nav-item span {
  flex: 1;
}

.sidebar-user {
  display: flex;
  align-items: center;
  gap: 10px;

  padding: 16px 8px 4px;

  border-top: 1px solid var(--f-ton-95);
  color: var(--t-ton-18);
}

.sidebar-user strong,
.sidebar-user small {
  display: block;
}

.sidebar-user strong {
  font-size: 13px;
  font-weight: 750;
}

.sidebar-user small {
  margin-top: 3px;
  color: var(--t-ton-61);
  font-size: 11px;
}

.sidebar-user > span {
  margin-left: auto;
  color: var(--t-ton-61-2);
  font-size: 22px;
}

.avatar {
  display: grid;
  place-items: center;

  width: 37px;
  height: 37px;

  border-radius: 50%;

  color: var(--t-blau-48);
  background: var(--f-ton-93);

  font-size: 12px;
  font-weight: 800;
}

/* =========================================================
   Topbar
   ========================================================= */

.topbar {
  grid-column: 2;
  grid-row: 1;

  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 28px;

  min-width: 0;
  padding: 12px 4px 28px;
}

.breadcrumb {
  margin-bottom: 10px;

  color: var(--t-ton-58);
  font-size: 12px;
  font-weight: 550;
}

.breadcrumb span {
  padding: 0 8px;
  color: var(--t-ton-79);
}

.topbar h1 {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;

  margin: 0;

  color: var(--t-ton-15);
  font-size: clamp(24px, 2.4vw, 34px);
  font-weight: 800;
  letter-spacing: -0.045em;
  line-height: 1.15;
}

.topbar p {
  margin: 9px 0 0;

  color: var(--t-ton-58-2);
  font-size: 13px;
}

.topbar-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 9px;
}

.global-search {
  display: flex;
  align-items: center;
  gap: 9px;

  width: 265px;
  min-width: 200px;
  padding: 11px 13px;

  border: 1px solid var(--f-ton-91);
  border-radius: 12px;

  background: var(--f-grau-100);
  color: var(--t-ton-66);

  box-shadow: 0 3px 12px var(--f-blau-25);
}

.global-search input {
  width: 100%;
  min-width: 0;

  border: 0;
  outline: 0;

  color: var(--t-ton-23);
  background: transparent;

  font: inherit;
  font-size: 12px;
}

.icon-button {
  display: grid;
  place-items: center;

  width: 41px;
  height: 41px;

  border: 1px solid var(--f-ton-91);
  border-radius: 12px;

  color: var(--t-ton-47-2);
  background: var(--f-grau-100);

  cursor: pointer;
  font-size: 17px;

  transition:
    border-color 0.18s ease,
    background 0.18s ease;
}

.icon-button:hover {
  border-color: var(--f-blau-86);
  background: var(--f-ton-97-2);
}

/* =========================================================
   Meldungen
   ========================================================= */

.alert {
  grid-column: 2;
  margin: 0 0 16px;
  padding: 12px 15px;
  border-radius: 12px;
  font-size: 13px;
}

.error-alert {
  color: var(--t-rot-35);
  border: 1px solid var(--f-rot-89);
  background: var(--f-ton-97-3);
}

.success-alert {
  color: var(--t-gruen-24);
  border: 1px solid var(--f-gruen-85);
  background: var(--f-ton-97-4);
}

/* =========================================================
   Hauptbereich
   ========================================================= */

/*
  Linke Spalte:
  Rackliste + Rackdetails

  Rechte Spalte:
  Gerätedetails / ergänzende Inhalte
*/

/* Der bestehende Geräteteil wird visuell als rechter Detailbereich
   dargestellt, ohne den Vue-Template-Code zu verändern. */

/* =========================================================
   Panels
   ========================================================= */

.panel {
  min-width: 0;

  border: 1px solid var(--rv-border);
  border-radius: 18px;

  background: var(--f-schleier-100-2);
  box-shadow: var(--rv-shadow);
}

.panel-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;

  padding: 22px 24px;
}

.eyebrow {
  display: block;
  margin-bottom: 7px;

  color: var(--t-ton-67);
  font-size: 10px;
  font-weight: 850;
  letter-spacing: 0.13em;
}

.panel h2,
.panel h3 {
  color: var(--t-ton-18-2);
  letter-spacing: -0.025em;
}

.panel h2 {
  margin: 0;
  font-size: 20px;
  font-weight: 800;
}

.panel h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 800;
}

.muted-text {
  color: var(--t-ton-60);
}

/* =========================================================
   Rackliste
   ========================================================= */

/* =========================================================
   Rackdetails und Statistik
   ========================================================= */

/* =========================================================
   Buttons
   ========================================================= */

.primary-button,
.secondary-button,
.danger-button,
.small-button {
  border: 1px solid transparent;
  border-radius: 10px;

  font-family: inherit;
  font-size: 12px;
  font-weight: 750;

  cursor: pointer;

  transition:
    background 0.18s ease,
    border-color 0.18s ease,
    transform 0.18s ease;
}

.primary-button {
  padding: 11px 14px;
  color: var(--t-grau-100);
  background: linear-gradient(135deg, var(--f-blau-61), var(--f-blau-53));
  box-shadow: 0 5px 13px var(--f-blau-53-3);
}

.primary-button:hover {
  background: linear-gradient(135deg, var(--f-blau-53), var(--f-blau-48));
  transform: translateY(-1px);
}

.secondary-button {
  padding: 9px 12px;
  color: var(--t-ton-40);
  border-color: var(--f-ton-91-2);
  background: var(--f-grau-100);
}

.secondary-button:hover {
  border-color: var(--f-blau-86-2);
  color: var(--t-blau-53);
  background: var(--f-ton-98);
}

.danger-button {
  padding: 9px 12px;
  color: var(--t-rot-40);
  border-color: var(--f-ton-88);
  background: var(--f-ton-98-2);
}

.danger-button:hover {
  border-color: var(--f-rot-79);
  background: var(--f-ton-97-5);
}

.small-button {
  padding: 6px 9px;
  color: var(--t-blau-40);
  border-color: var(--f-ton-90);
  background: var(--f-grau-100);
}

/* =========================================================
   Rackgrafik
   ========================================================= */

.rack-visual {
  margin: 0 24px;
  padding: 10px;

  border: 1px solid #dfe6f0;
  border-radius: 13px;

  background:
    linear-gradient(90deg, #f1f5fa 0, #ffffff 8%, #ffffff 92%, #f1f5fa 100%);
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.7);
}

.rack-unit-row {
  display: flex;
  min-height: 30px;

  border-bottom: 1px solid #edf1f6;

  transition:
    background 0.15s ease,
    outline-color 0.15s ease;
}

.rack-unit-row:last-child {
  border-bottom: 0;
}

.rack-unit-row:hover {
  background: #f8fbff;
}

.rack-unit-number {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 48px;
  flex: 0 0 48px;

  color: #a0adbf;
  border-right: 1px solid #e5ebf3;

  font-size: 10px;
  font-weight: 750;
}

.rack-unit-content {
  position: relative;
  flex: 1;
  min-width: 0;
  align-self: stretch;
}

/* --- Geräte über mehrere Höheneinheiten ----------------------
   Der Block wird nur in der obersten Einheit gezeichnet und
   reicht von dort über die darunterliegenden Zeilen. Dafür
   braucht die Zeile eine feste Höhe. */

.rack-unit-row {
  position: relative;
  height: 30px;
}

.rack-unit-row.unit-device-start {
  z-index: 2;
}

.rack-device-block {
  position: absolute;
  top: 2px;
  right: 6px;
  left: 0;

  height: calc(var(--device-span, 1) * 30px - 4px);
  margin: 0;

  cursor: pointer;
}

.rack-device-block:hover {
  filter: brightness(1.06);
}

.free-unit-label {
  display: block;
  padding: 7px 12px;

  color: #b5bfce;
  font-size: 10px;
  font-weight: 600;
}

/* Keine eigene Position hier: Der Block bleibt absolut (siehe oben),
   sonst zentriert ".rack-unit-content" ihn und Geraete ueber mehrere
   HE ragen halb in die Einheit darueber. */
.rack-device-block {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;

  padding: 5px 10px;

  border: 1px solid #1d4ed8;
  border-radius: 6px;

  color: #ffffff;
  background: #2563eb;

  box-shadow: 0 2px 5px rgba(37, 99, 235, 0.16);
  overflow: hidden;
}

.rack-legend {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;

  padding: 14px 24px 20px;

  color: var(--t-ton-61-2);
  font-size: 11px;
}

.rack-legend-spacer {
  flex: 1;
}

.legend-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  margin-right: 5px;
  border-radius: 50%;
}

.legend-active {
  background: var(--f-blau-53);
}

.legend-warning {
  background: var(--f-amber-50);
}

.legend-inactive {
  background: var(--f-ton-65);
}

/* =========================================================
   Geräteliste
   ========================================================= */

.status-active {
  color: var(--t-gruen-24);
  background: var(--f-ton-93-2);
}

.status-planned {
  color: var(--t-blau-48-2);
  background: var(--f-ton-93-3);
}

.status-maintenance {
  color: var(--t-amber-31);
  background: var(--f-amber-89);
}

.status-retired {
  color: var(--t-ton-27);
  background: var(--f-grau-91);
}

.status-faulty {
  color: var(--t-rot-35);
  background: var(--f-ton-94);
}

/* =========================================================
   Verbindungen
   ========================================================= */

.connection-list {
  padding: 0 24px 24px;
}

.connection-item {
  display: flex;
  align-items: center;
  gap: 14px;

  padding: 13px 0;
  border-bottom: 1px solid var(--f-ton-95);
}

.connection-item:last-child {
  border-bottom: 0;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.form-group-full {
  grid-column: 1 / -1;
}

/* Schnellwahl unter dem Garantiefeld */
.garantie-schnellwahl {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;

  margin-top: 6px;
}

.garantie-schnellwahl button {
  padding: 4px 9px;

  border: 1px solid var(--f-ton-84);
  border-radius: 999px;

  color: var(--t-ton-47-3);
  background: var(--f-ton-96-2);

  font-size: 11px;
  font-weight: 750;
  cursor: pointer;
}

.garantie-schnellwahl button:hover:not(:disabled) {
  border-color: var(--f-blau-60-2);
  color: var(--rv-blue);
}

.garantie-schnellwahl button:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.garantie-schnellwahl .loeschen {
  margin-left: auto;
}

/* Zustand der Garantie: nur abgelaufen und bald faerben. Die
   Kachelklasse steht mit im Selektor, sonst gewinnt die Grundfarbe
   von ".device-detail-grid strong". */
.garantie-abgelaufen,
.device-detail-grid strong.garantie-abgelaufen {
  color: var(--t-rot-42);
}

.garantie-bald,
.device-detail-grid strong.garantie-bald {
  color: var(--t-amber-37);
}

.device-details-content {
  padding: 0 24px 24px;
}

.device-detail-section {
  padding: 20px 0;
  border-bottom: 1px solid var(--f-ton-95);
}

.device-detail-section:last-child {
  border-bottom: 0;
}

.device-detail-section h3 {
  margin-bottom: 15px;
}

.device-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 15px;
}

.device-detail-grid > div {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.detail-label {
  color: var(--t-ton-61-2);
  font-size: 11px;
}

.device-detail-grid strong {
  color: var(--t-ton-25);
  font-size: 13px;
}

.section-heading-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.device-detail-notes {
  margin: 0;
  color: var(--t-ton-47-3);
  font-size: 13px;
  line-height: 1.7;
  white-space: pre-wrap;
}

/* =========================================================
   Leere Zustände
   ========================================================= */

.empty-state {
  padding: 36px 24px;
  color: var(--t-ton-61-2);
  font-size: 13px;
  text-align: center;
}

/* =========================================================
   Responsive
   ========================================================= */

@media (max-width: 1280px) {
  .app-shell {
    grid-template-columns: 220px minmax(0, 1fr);
    gap: 0 20px;
    padding-right: 20px;
  }

  .sidebar {
    width: 220px;
  }

  .topbar {
    gap: 18px;
  }

  .global-search {
    width: 220px;
  }
}

@media (max-width: 900px) {
  .app-shell {
    display: block;
    padding: 14px;
  }

  .sidebar {
    position: relative;
    inset: auto;

    width: 100%;
    min-height: auto;
    margin-bottom: 18px;
  }

  .sidebar-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .nav-section-label {
    display: none;
  }

  .nav-item {
    flex: 0 0 auto;
  }

  /* Frueher stand hier display: none. Damit verschwand auf schmalen
     Geraeten auch der Abmelde-Knopf - und einen zweiten gibt es
     nicht. Die Leiste wird jetzt nur flacher. */
  .sidebar-user {
    margin-top: 10px;
    padding: 12px 6px 2px;
  }

  /* Etwas groesser, damit der Knopf mit dem Finger zu treffen ist.
     Zwei Klassen, weil die Grundregel weiter unten im Blatt steht und
     bei gleicher Staerke sonst gewinnt. */
  .sidebar-user .sidebar-logout {
    width: 38px;
    height: 38px;

    font-size: 16px;
  }

  .topbar {
    display: flex;
    flex-wrap: wrap;
    padding: 10px 0 20px;
  }

  .topbar-actions {
    width: 100%;
    justify-content: flex-start;
  }

  .alert {
    margin-bottom: 14px;
  }

}

@media (max-width: 620px) {
  .panel-heading {
    padding: 18px;
  }

  .rack-visual {
    margin: 0 12px;
  }

  .rack-legend {
    padding: 12px 18px 18px;
  }

  .form-grid,
  .device-detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .connection-item {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .global-search {
    flex: 1;
    min-width: 150px;
  }

  .modal-backdrop {
    padding: 12px;
  }
}

/* =========================================================
   Rack-Ansicht: Frontblenden und Geräteliste daneben
   Steht bewusst am Ende des Blocks und überschreibt damit
   die älteren Rack-Regeln weiter oben.
   ========================================================= */

.rack-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(220px, 300px);
  gap: 20px;
  align-items: start;

  padding: 0 24px;
}

@media (max-width: 1100px) {
  .rack-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}

/* --- Rack-Gehäuse: dunkel, damit die Blenden wirken --- */

.rack-visual {
  width: 100%;
  margin: 0;
  padding: 9px;

  border: 8px solid #2a3342;
  border-radius: 12px;

  background: #11161f;
  box-shadow:
    0 16px 34px rgba(15, 23, 42, 0.22),
    inset 0 0 0 1px rgba(255, 255, 255, 0.04);
}

.rack-unit-row {
  border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  background: transparent;
}

.rack-unit-row:hover {
  background: rgba(255, 255, 255, 0.04);
}

.rack-unit-number {
  width: 34px;
  flex: 0 0 34px;

  color: #5b6779;
  border-right: 1px solid rgba(255, 255, 255, 0.07);
}

.free-unit-label {
  color: #3d4757;
}

/* --- Geräteblock ist nur noch Rahmen für die Blende --- */

.rack-device-block {
  top: 1px;
  right: 4px;

  display: block;
  height: calc(var(--device-span, 1) * 30px - 2px);
  padding: 0;

  border: 0;
  border-radius: 4px;

  background: transparent;
  box-shadow: none;
  overflow: hidden;
}

.rack-device-block:hover {
  filter: brightness(1.15);
}

.rack-device-block.active {
  outline: 2px solid #3b82f6;
  outline-offset: 1px;
}

/* --- Drag & Drop ---------------------------------------------- */

.rack-visual {
  position: relative;
}

.rack-device-block {
  cursor: grab;

  /* Langer Druck auf Touchgeraeten: kein Textmarkieren, kein Menue */
  -webkit-touch-callout: none;
  -webkit-user-select: none;
  user-select: none;
}

/* Der alte Platz bleibt schwach sichtbar, bis das Geraet abgelegt ist */
.rack-device-block.dragging {
  opacity: 0.3;
  filter: grayscale(0.6);
}

.rack-drop-ghost {
  position: absolute;
  z-index: 5;

  overflow: hidden;
  border-radius: 4px;

  outline: 2px dashed #4ade80;
  outline-offset: 1px;
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.45);

  opacity: 0.92;
  pointer-events: none;
}

.rack-drop-ghost.invalid {
  outline-color: #f87171;
  opacity: 0.55;
}

.rack-drag-badge {
  position: fixed;
  z-index: 1100;

  display: flex;
  flex-direction: column;
  gap: 2px;

  max-width: 260px;
  padding: 8px 11px;

  border: 1px solid var(--f-gruen-85);
  border-radius: 10px;
  background: var(--f-ton-97-4);
  box-shadow: 0 10px 24px var(--f-schatten-11);

  color: var(--t-gruen-24);
  font-size: 12px;
  font-weight: 700;

  pointer-events: none;
}

.rack-drag-badge strong {
  overflow: hidden;

  color: var(--t-ton-11);
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rack-drag-badge.invalid {
  border-color: var(--f-rot-89);
  background: var(--f-ton-97-3);
  color: var(--t-rot-42);
}

.rack-selector-item.drop-target {
  border-color: var(--f-gruen-58) !important;
  background: var(--f-ton-97-4) !important;
  box-shadow: 0 0 0 3px var(--f-gruen-58-2) !important;
}

.rack-selector-item.drop-invalid {
  border-color: var(--f-rot-71) !important;
  background: var(--f-ton-97-3) !important;
  box-shadow: 0 0 0 3px var(--f-rot-71-2) !important;
}

.rack-legend-hint {
  color: #a0adbf;
  font-style: italic;
}

/* --- Geräteliste rechts neben dem Rack --- */

.rack-device-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.rack-device-row {
  display: flex;
  align-items: center;
  gap: 10px;

  padding: 8px 10px;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 10px;

  background: var(--f-grau-100);
  cursor: pointer;

  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.rack-device-row:hover {
  border-color: var(--f-blau-85);
  box-shadow: 0 2px 8px var(--f-schatten-11-2);
}

.rack-device-row.active {
  border-color: var(--f-blau-53);
  box-shadow: 0 0 0 2px var(--f-blau-53-4);
}

.rack-device-row-badge {
  flex: 0 0 auto;
  padding: 3px 7px;

  border-radius: 6px;
  background: var(--f-ton-96);

  color: var(--t-ton-37);
  font-size: 10px;
  font-weight: 800;
}

.rack-device-row-info {
  display: flex;
  flex-direction: column;
  gap: 2px;

  flex: 1;
  min-width: 0;
}

.rack-device-row-info strong {
  display: flex;
  align-items: center;
  gap: 6px;
  overflow: hidden;

  color: var(--t-ton-11);
  font-size: 12px;
  font-weight: 750;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.rack-device-row-info small {
  overflow: hidden;

  color: var(--t-ton-54);
  font-size: 10px;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.rack-device-row-status {
  width: 7px;
  height: 7px;
  flex: 0 0 7px;

  border-radius: 50%;
  background: var(--f-ton-65-2);
}

.rack-device-row-status.status-active {
  background: var(--f-gruen-45);
}

.rack-device-row-status.status-planned {
  background: var(--f-amber-50);
}

.rack-device-row-status.status-maintenance {
  background: var(--f-blau-66);
}

.rack-device-row-status.status-retired {
  background: var(--f-ton-65-2);
}

.rack-device-row-icon {
  color: var(--t-ton-65);
  font-size: 13px;
}

.rack-device-list-empty {
  padding: 14px;

  border: 1px dashed var(--f-ton-91-3);
  border-radius: 10px;

  color: var(--t-ton-65);
  font-size: 11px;
  text-align: center;
}

/* --- Geraetefoto: Verwaltung im Dialog --- */


.device-image-editor {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap: 18px;
  align-items: center;
}

@media (max-width: 640px) {
  .device-image-editor {
    grid-template-columns: minmax(0, 1fr);
  }
}



.device-image-actions {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 9px;
}

.device-image-actions small {
  color: var(--t-ton-61-2);
  font-size: 10px;
  line-height: 1.5;
}

/* Das Dateifeld selbst bleibt verborgen, das Label ist die Schaltflaeche. */
.device-image-upload {
  display: inline-flex;
  align-items: center;
  cursor: pointer;
}

.device-image-upload input[type="file"] {
  position: absolute;
  width: 1px;
  height: 1px;

  opacity: 0;
  pointer-events: none;
}

/* =========================================================
   Seitenkopf: Pfad, Rack-Titel mit Status, Aktionen
   ========================================================= */

.page-header {
  display: flex;
  flex-direction: column;
  gap: 16px;

  flex: 1;
  min-width: 0;
}

.page-header-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}

.page-header-top .breadcrumb {
  margin-bottom: 0;
}

.page-header-main {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px 24px;
}

.page-title {
  min-width: 0;
}

.page-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.page-actions .secondary-button {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}

/* --- Status des Racks --- */

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;

  padding: 6px 11px;
  border-radius: 999px;

  font-size: 11px;
  font-weight: 750;
  letter-spacing: 0;
}

.status-pill i {
  width: 7px;
  height: 7px;
  border-radius: 50%;
}

.status-pill-ok {
  color: var(--t-gruen-30);
  background: var(--f-ton-92-2);
}

.status-pill-ok i {
  background: var(--f-gruen-36);
}

.status-pill-warn {
  color: var(--t-amber-33);
  background: var(--f-amber-89);
}

.status-pill-warn i {
  background: var(--f-amber-50);
}

.status-pill-neutral {
  color: var(--t-ton-47-3);
  background: var(--f-ton-95-2);
}

.status-pill-neutral i {
  background: var(--f-ton-65-2);
}

/* --- Menue "Weitere Aktionen" --- */

.page-menu {
  position: relative;
}

.page-menu-toggle {
  justify-content: center;
  width: 40px;

  font-size: 16px;
  line-height: 1;
}

.page-menu-list {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 20;

  display: flex;
  flex-direction: column;
  min-width: 200px;
  padding: 6px;

  border: 1px solid var(--f-ton-91);
  border-radius: 12px;

  background: var(--f-grau-100);
  box-shadow: 0 12px 30px var(--f-schatten-11-3);
}

.page-menu-list button {
  padding: 9px 11px;

  border: 0;
  border-radius: 8px;

  color: var(--t-ton-23);
  background: transparent;

  font: inherit;
  font-size: 12px;
  font-weight: 650;
  text-align: left;

  cursor: pointer;
}

.page-menu-list button:hover {
  color: var(--t-blau-53);
  background: var(--f-ton-98);
}

.page-menu-list button.danger {
  color: var(--t-rot-51);
}

.page-menu-list button.danger:hover {
  color: var(--t-rot-42);
  background: var(--f-ton-97-3);
}

@media (max-width: 900px) {
  .page-header-top {
    flex-direction: column;
    align-items: flex-start;
  }
}

/* =========================================================
   Globale Suche mit Ergebnisliste
   ========================================================= */

.global-search {
  position: relative;
  width: 300px;
}

.global-search:focus-within {
  border-color: var(--f-blau-86-2);
  box-shadow: 0 0 0 3px var(--f-blau-60);
}

.global-search-icon {
  flex-shrink: 0;
}

.global-search-kbd {
  flex-shrink: 0;
  padding: 2px 6px;

  border: 1px solid var(--f-ton-91);
  border-radius: 6px;

  color: var(--t-ton-61-2);
  background: var(--f-grau-97-2);

  font-family: inherit;
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
}

.global-search-results {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 20;

  display: flex;
  flex-direction: column;
  width: 400px;
  max-width: calc(100vw - 32px);
  max-height: 60vh;
  padding: 6px;
  overflow-y: auto;

  border: 1px solid var(--f-ton-91);
  border-radius: 12px;

  background: var(--f-grau-100);
  box-shadow: 0 12px 30px var(--f-schatten-11-3);
}

.global-search-result {
  display: flex;
  align-items: center;
  gap: 10px;

  padding: 8px 10px;

  border: 0;
  border-radius: 8px;

  color: var(--t-ton-23);
  background: transparent;

  font: inherit;
  text-align: left;

  cursor: pointer;
}

.global-search-result.active {
  background: var(--f-ton-98);
}

.global-search-result-icon {
  display: grid;
  place-items: center;

  width: 28px;
  height: 28px;
  flex: 0 0 28px;

  border-radius: 7px;
  background: var(--f-ton-96);

  color: var(--t-ton-40);
  font-size: 13px;
}

.global-search-result-info {
  display: flex;
  flex-direction: column;
  gap: 2px;

  flex: 1;
  min-width: 0;
}

.global-search-result-info strong {
  overflow: hidden;

  color: var(--t-ton-11);
  font-size: 12px;
  font-weight: 750;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.global-search-result-info small {
  color: var(--t-ton-54);
  font-size: 10px;
}

/* Zeigt, warum ein Geraet gefunden wurde, wenn es nicht der Name war */
.global-search-result-match {
  flex-shrink: 0;
  max-width: 45%;
  padding: 3px 7px;
  overflow: hidden;

  border-radius: 6px;
  background: var(--f-ton-96);

  color: var(--t-ton-37);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 10px;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.global-search-empty {
  margin: 0;
  padding: 14px 10px;

  color: var(--t-ton-61-2);
  font-size: 12px;
  text-align: center;
}

@media (max-width: 900px) {
  .global-search {
    width: 100%;
  }

  .global-search-kbd {
    display: none;
  }
}

/* =========================================================
   Port-Übersicht: Raster wie auf der Gerätefront
   ========================================================= */

.port-device-select {
  max-width: 220px;
  padding: 8px 10px;

  border: 1px solid var(--f-ton-91);
  border-radius: 10px;

  color: var(--t-ton-23);
  background: var(--f-grau-100);

  font: inherit;
  font-size: 12px;
  font-weight: 650;
}

.port-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 8px 6px;

  padding: 4px 24px 0;
}

@media (max-width: 1300px) {
  .port-grid {
    grid-template-columns: repeat(8, minmax(0, 1fr));
  }
}

.port-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;

  padding: 0;

  border: 0;
  background: transparent;

  font: inherit;
  cursor: pointer;
}

.port-cell-number {
  color: var(--t-ton-54);
  font-size: 10px;
  font-weight: 750;
}

/* Die Buchse wird gezeichnet (PortSymbol) - RJ45 mit Nut und
   Goldkontakten, SFP als Kaefig, dazu USB und Strom. Hier stehen nur
   die Farben: der Zustand faerbt Rahmen und Fuellung, die Bauform
   kommt aus dem Porttyp. Die Toene --f-ton-15/35 sind in heller und
   dunkler Ansicht dieselben - eine Buchsenoeffnung bleibt ein Loch. */
.port-cell-jack {
  width: 100%;
  max-width: 34px;
  aspect-ratio: 34 / 40;

  border-radius: 6px;

  --jack-kante: var(--f-ton-84);
  --jack-fuellung: var(--f-ton-96-2);
  --jack-oeffnung: var(--f-ton-11);
  --jack-stecker: var(--f-ton-65-2);
  --jack-teil: var(--f-ton-65-2);
  --jack-poe: var(--f-amber-50);

  transition:
    transform 0.12s ease,
    box-shadow 0.12s ease;
}

.port-cell:hover .port-cell-jack {
  transform: translateY(-1px);
}

.port-cell.active .port-cell-jack {
  box-shadow: 0 0 0 3px var(--f-blau-53-5);
}

.port-cell.port-connected .port-cell-jack {
  --jack-kante: var(--f-gruen-45);
  --jack-fuellung: var(--f-ton-93-2);
}

.port-cell.port-faulty .port-cell-jack {
  --jack-kante: var(--f-rot-60);
  --jack-fuellung: var(--f-ton-94);
  --jack-stecker: var(--t-rot-42);
}

.port-cell.port-disabled .port-cell-jack {
  --jack-kante: var(--f-ton-35);
  --jack-fuellung: var(--f-ton-65-2);
  --jack-kontakt: var(--f-ton-84);
  --jack-teil: var(--f-ton-84);
}

/* SFP/Uplink: blaue Kennung, der Zustand bleibt an der Fuellung sichtbar */
.port-cell.uplink .port-cell-jack {
  --jack-kante: var(--f-blau-60-2);
}

/* --- Steckplaetze im selben Raster --- */

.port-cell.outlet-cell .port-cell-jack {
  --jack-kante: var(--f-ton-84);
  --jack-fuellung: var(--f-ton-96-2);
  --jack-teil: var(--f-ton-65-2);
}

/* Belegt heisst: da steckt ein Stecker drin - gleiche Lesart wie bei
   den Buchsen, nur dass der Rahmen die Farbe traegt. */
.port-cell.outlet-cell.belegt .port-cell-jack {
  --jack-kante: var(--f-gruen-45);
  --jack-fuellung: var(--f-ton-93-2);
}

.port-gruppen-titel {
  margin: 24px 0 2px;
  padding: 0 24px;

  color: var(--t-ton-47-3);
  font-size: 12px;
  font-weight: 800;
}

.port-gruppen-titel.erste {
  margin-top: 4px;
}

.port-legend-dot.belegt {
  border-color: var(--f-gruen-45);
  background: var(--f-gruen-45);
}

/* Name des angeschlossenen Geraets - fuehrt zu ihm hin */
.port-peer-link {
  padding: 0;

  border: 0;
  background: none;

  color: var(--rv-blue);
  font: inherit;
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}

.port-peer-link:hover {
  text-decoration: underline;
}

.port-detail-aktion {
  margin: 18px 24px 4px;
}

/* --- Legende --- */

.port-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;

  padding: 16px 24px 4px;

  color: var(--t-ton-47-3);
  font-size: 11px;
}

.port-legend span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.port-legend-dot {
  width: 10px;
  height: 10px;

  border: 2px solid var(--f-ton-84);
  border-radius: 3px;

  background: var(--f-ton-96-2);
}

.port-legend-dot.port-connected {
  border-color: var(--f-gruen-45);
  background: var(--f-ton-93-2);
}

.port-legend-dot.port-faulty {
  border-color: var(--f-rot-60);
  background: var(--f-ton-94);
}

.port-legend-dot.port-disabled {
  border-color: var(--f-ton-35);
  background: var(--f-ton-65-2);
}

.port-legend-dot.uplink {
  border-color: var(--f-blau-60-2);
  background: var(--f-grau-100);
}

/* --- Port-Details --- */

.port-detail-title {
  flex: 1;
  min-width: 0;
}

.port-state-badge {
  flex-shrink: 0;
  padding: 4px 10px;

  border-radius: 999px;

  color: var(--t-ton-47-3);
  background: var(--f-ton-95-2);

  font-size: 11px;
  font-weight: 750;
}

.port-state-badge.port-connected {
  color: var(--t-gruen-30);
  background: var(--f-ton-92-2);
}

.port-state-badge.port-faulty {
  color: var(--t-rot-42);
  background: var(--f-ton-94);
}

.port-state-badge.port-disabled {
  color: var(--t-ton-27-2);
  background: var(--f-ton-91-4);
}

.port-peer-port {
  color: var(--t-ton-54);
}

/* Zaehler im Kopf des Netzwerkplans */
.network-plan-count {
  padding: 4px 10px;

  border-radius: 999px;
  background: var(--f-ton-96);

  color: var(--t-ton-37);
  font-size: 11px;
  font-weight: 750;
}

/* --- PoE: orangefarbener Balken unter der Buchse ---
   In der Kachel zeichnet ihn PortSymbol selbst; die Legende malt ihn
   weiter als Unterkante, damit beide gleich aussehen. */

.port-legend-dot.poe {
  border-bottom: 4px solid var(--f-amber-50);
}

/* Erklaerender Text unter Formularfeldern */
.form-hint {
  display: block;
  margin-top: 5px;

  color: var(--t-ton-61-2);
  font-size: 10.5px;
  line-height: 1.45;
}

/* Knoepfe im Kopf des Geraetedetails-Panels */
.device-panel-actions {
  display: flex;
  gap: 6px;
}

/* --- Navigation --- */

.nav-icon {
  width: 18px;
  flex-shrink: 0;

  font-style: normal;
  text-align: center;
}

/* Sprungziele nicht unter der fixierten Kopfzeile verstecken */
#panel-racks,
#panel-device,
#panel-ports,
#panel-network {
  scroll-margin-top: 150px;
}

/* Netzwerkplan als eigene Seite: volle Breite, etwas mehr Luft */
.network-view-panel {
  padding: 24px 0 20px;
}

/* Loeschen links im Dialogfuss, Abbrechen/Speichern rechts */
.modal-footer-start {
  margin-right: auto;
}

/* Rack-Auswahl oben im Geraeteformular vom Rest absetzen */
.device-rack-choice {
  margin-bottom: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--f-ton-95);
}


/* --- Geraetefoto im Formular: Fotorahmen statt Blendenstreifen --- */

.device-photo-preview {
  display: grid;
  place-items: center;

  aspect-ratio: 4 / 3;
  overflow: hidden;

  border: 1px solid var(--f-ton-93-4);
  border-radius: 10px;

  background: var(--f-grau-97-2);
}

.device-photo-preview img {
  display: block;
  width: 100%;
  height: 100%;

  object-fit: contain;
}

.device-photo-empty {
  color: var(--t-ton-66);
  font-size: 12px;
  font-weight: 600;
}


/* --- Anmeldung --- */

.auth-loading {
  display: grid;
  place-items: center;

  min-height: 100vh;

  color: var(--t-ton-61-2);
  font-size: 13px;
}

/* Der Menue-Knopf gehoert der schmalen Ansicht; am Schreibtisch
   steht die Navigation ohnehin offen. */
.nav-toggle {
  display: none;
}

/* Umschalter für helle und dunkle Ansicht */
.theme-toggle {
  display: grid;
  place-items: center;

  width: 38px;
  height: 38px;
  flex-shrink: 0;

  border: 1px solid var(--f-ton-91);
  border-radius: 11px;

  color: var(--t-ton-47-3);
  background: var(--f-grau-100);

  font-size: 16px;
  line-height: 1;
  cursor: pointer;
}

.theme-toggle:hover {
  border-color: var(--f-blau-86-2);
  color: var(--t-blau-53);
}

/* Avatar und Name fuehren zu den Einstellungen */
.sidebar-user-link {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 10px;
  min-width: 0;

  margin: -6px 0 -6px -6px;
  padding: 6px;

  border-radius: 10px;
  color: inherit;
  text-decoration: none;
}

.sidebar-user-link > div:last-child {
  min-width: 0;
}

.sidebar-user-link:hover {
  background: var(--f-ton-97-6);
}

.sidebar-logout {
  display: grid;
  place-items: center;

  width: 30px;
  height: 30px;
  flex-shrink: 0;

  border: 1px solid var(--f-ton-91);
  border-radius: 9px;

  color: var(--t-ton-47-2);
  background: var(--f-grau-100);

  font-size: 14px;
  cursor: pointer;
}

.sidebar-logout:hover {
  border-color: var(--f-rot-89);
  color: var(--t-rot-42);
  background: var(--f-ton-97-3);
}

.sidebar-user small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}


/* =========================================================
   Schmale Geraete: Menue hinter einem Knopf
   =========================================================
   Dieselbe Schwelle wie beim Layoutwechsel weiter unten (850px).
   Zugeklappt besteht die Leiste nur aus Logo, Menue-Knopf und
   Konto - sonst scrollt man auf dem Telefon an elf Eintraegen
   vorbei, bevor der Inhalt beginnt.
   ========================================================= */

@media (max-width: 850px) {
  .brand {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  /* Am Schreibtisch darf das Logo gross sein, auf dem Telefon nimmt
     es sonst ein Drittel der ersten Bildschirmhoehe ein. */
  .brand-logo {
    width: auto;
    height: 42px;
  }

  .brand .nav-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    min-height: 38px;
    padding: 8px 12px;

    border: 1px solid var(--f-ton-91);
    border-radius: 11px;

    color: var(--t-ton-23);
    background: var(--f-grau-100);

    font-size: 13px !important;
    font-weight: 700;
    cursor: pointer;
  }

  .brand .nav-toggle:hover {
    border-color: var(--f-blau-86-2);
  }

  .brand .nav-toggle-icon {
    font-size: 15px;
    line-height: 1;
  }

  .sidebar-nav {
    display: none;
  }

  .sidebar-nav.offen {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 6px;

    margin-top: 12px;
  }

  /* Aufgeklappt geben die Gruppen wieder Orientierung */
  .sidebar-nav.offen .nav-section-label {
    display: block;
    grid-column: 1 / -1;

    margin-top: 6px;
  }
}

</style>

<style>

/* =========================================================
   RACKVIEW DASHBOARD – FINAL LAYOUT OVERRIDES
   ========================================================= */

:root {
  --rv-sidebar-width: 252px;
  --rv-page-gap: 24px;
  --rv-border: #e5e7eb;
  --rv-text: #172033;
  --rv-muted: #7b8495;
  --rv-blue: #2563eb;
  --rv-blue-dark: #1d4ed8;
  --rv-panel-bg: #ffffff;
  --rv-page-bg: #f5f7fb;
}

/* Grundlayout */
html,
body,
#app {
  min-height: 100%;
  margin: 0;
  padding: 0;
}

body {
  background: var(--rv-page-bg) !important;
  color: var(--rv-text) !important;
  overflow-x: hidden;
}

/* Äußere Anwendung */
.app-shell {
  position: relative !important;
  display: block !important;
  min-height: 100vh !important;
  width: 100% !important;
  max-width: none !important;
  margin: 0 !important;
  padding: 0 !important;
  background: var(--rv-page-bg) !important;
  color: var(--rv-text) !important;
}

/* Sidebar darf niemals über dem Inhalt liegen */
.app-shell > .sidebar,
.sidebar {
  position: fixed !important;
  z-index: 1000 !important;
  top: 16px !important;
  left: 16px !important;
  bottom: 16px !important;
  width: var(--rv-sidebar-width) !important;
  min-width: var(--rv-sidebar-width) !important;
  max-width: var(--rv-sidebar-width) !important;
  height: auto !important;
  box-sizing: border-box !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  border: 1px solid var(--f-ton-92-3) !important;
  border-radius: 22px !important;
  background: var(--f-schleier-100) !important;
  box-shadow: 0 12px 40px var(--f-schatten-11-4) !important;
}

/* Hauptbereich rechts neben der Sidebar */
.app-shell > .topbar,
.topbar {
  position: sticky !important;
  top: 0 !important;
  z-index: 900 !important;
  display: flex !important;
  box-sizing: border-box !important;
  width: auto !important;
  min-height: 82px !important;
  margin: 0 0 0 calc(var(--rv-sidebar-width) + 48px) !important;
  padding: 20px 32px !important;
  background: var(--f-schleier-97) !important;
  color: var(--rv-text) !important;
  border-bottom: 1px solid var(--f-schleier-91) !important;
  backdrop-filter: blur(18px) !important;
}

/* Alte Layoutklassen neutralisieren */

/* Dashboard-Hauptstruktur */
.dashboard-layout {
  position: relative !important;
  box-sizing: border-box !important;
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) !important;
  gap: var(--rv-page-gap) !important;
  width: auto !important;
  min-width: 0 !important;
  margin: 0 0 0 calc(var(--rv-sidebar-width) + 48px) !important;
  padding: 28px 32px 40px !important;
  color: var(--rv-text) !important;
}

/* Obere Reihe: zwei große Panels */
.dashboard-top-row {
  display: grid !important;
  grid-template-columns: minmax(0, 1.35fr) minmax(380px, 0.85fr) !important;
  align-items: stretch !important;
  gap: var(--rv-page-gap) !important;
  min-width: 0 !important;
}

/* Untere Reihe: drei Panels */
.dashboard-bottom-row {
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) !important;
  align-items: stretch !important;
  gap: var(--rv-page-gap) !important;
  min-width: 0 !important;
}

/* Alle Dashboard-Panels */
.dashboard-layout .panel {
  position: relative !important;
  box-sizing: border-box !important;
  min-width: 0 !important;
  overflow: hidden !important;
  padding: 24px !important;
  border: 1px solid var(--rv-border) !important;
  border-radius: 22px !important;
  background: var(--rv-panel-bg) !important;
  color: var(--rv-text) !important;
  box-shadow: 0 8px 28px var(--f-schatten-11-5) !important;
  opacity: 1 !important;
  visibility: visible !important;
}

/* Obere Panels etwas höher */
.dashboard-top-row > .panel {
  min-height: 510px !important;
}

/* Untere Panels */
.dashboard-bottom-row > .panel {
  min-height: 390px !important;
}

/* Überschriften */
.dashboard-layout .panel-heading {
  display: flex !important;
  align-items: flex-start !important;
  justify-content: space-between !important;
  gap: 16px !important;
  margin-bottom: 22px !important;
  color: var(--rv-text) !important;
}

.dashboard-layout .panel-kicker {
  display: block !important;
  margin-bottom: 7px !important;
  color: var(--rv-blue) !important;
  font-size: 11px !important;
  font-weight: 800 !important;
  letter-spacing: 0.12em !important;
  text-transform: uppercase !important;
}

.dashboard-layout h2,
.dashboard-layout h3,
.dashboard-layout strong,
.dashboard-layout span,
.dashboard-layout p,
.dashboard-layout dt,
.dashboard-layout dd {
  color: inherit;
}

.dashboard-layout h2 {
  margin: 0 !important;
  color: var(--rv-text) !important;
  font-size: 23px !important;
  font-weight: 800 !important;
  letter-spacing: -0.035em !important;
}

.dashboard-layout h3 {
  color: var(--rv-text) !important;
}

.dashboard-layout p {
  color: var(--rv-muted) !important;
}

/* Buttons */
.dashboard-layout button {
  font: inherit !important;
  cursor: pointer !important;
}

.dashboard-layout .primary-button {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 8px !important;
  min-height: 40px !important;
  padding: 0 16px !important;
  border: 0 !important;
  border-radius: 11px !important;
  background: var(--rv-blue) !important;
  color: var(--t-grau-100) !important;
  font-weight: 700 !important;
  box-shadow: 0 5px 14px var(--f-blau-53-2) !important;
}

.dashboard-layout .primary-button:hover {
  background: var(--rv-blue-dark) !important;
}

.dashboard-layout .icon-button {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 38px !important;
  height: 38px !important;
  border: 1px solid var(--rv-border) !important;
  border-radius: 11px !important;
  background: var(--f-grau-100) !important;
  color: var(--rv-text) !important;
}

/* Rack-Auswahl */
.rack-selector {
  display: flex !important;
  flex-wrap: wrap !important;
  gap: 8px !important;
  margin-bottom: 22px !important;
}

.rack-selector-item {
  display: flex !important;
  align-items: center !important;
  gap: 9px !important;
  padding: 9px 12px !important;
  border: 1px solid var(--rv-border) !important;
  border-radius: 10px !important;
  background: var(--f-grau-100) !important;
  color: var(--rv-text) !important;
}

.rack-selector-item.active {
  border-color: var(--rv-blue) !important;
  background: var(--f-ton-97-7) !important;
  color: var(--rv-blue) !important;
}

.rack-selector-name {
  font-size: 13px !important;
  font-weight: 700 !important;
}

.rack-selector-meta {
  color: var(--rv-muted) !important;
  font-size: 12px !important;
}

/* Rack-Bühne */
.rack-stage {
  min-width: 0 !important;
}

.rack-stage-header {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 16px !important;
  margin-bottom: 18px !important;
}

.rack-stage-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.rack-side-switch {
  display: inline-flex;
  padding: 3px;

  border: 1px solid var(--f-ton-91-2);
  border-radius: 10px;
  background: var(--f-ton-97-8);
}

.rack-side-switch button {
  padding: 6px 12px;

  border: 0;
  border-radius: 8px;
  background: transparent;

  color: var(--t-ton-47-3);
  font: inherit;
  font-size: 12px;
  font-weight: 700;

  cursor: pointer;
}

.rack-side-switch button.active {
  background: var(--f-grau-100);
  color: var(--t-blau-53);
  box-shadow: 0 1px 3px var(--f-schatten-11-3);
}

.rack-device-row-side {
  padding: 2px 7px;

  border-radius: 6px;
  background: var(--f-ton-95-3);

  color: var(--t-ton-47-3);
  font-size: 10px;
  font-weight: 750;
  text-transform: uppercase;
}

/* Hinweis auf ein Geraet der anderen Rackseite */
.free-unit-label.gegenueber {
  color: #55637a;
  font-style: italic;
}

.feld-hinweis {
  color: var(--t-ton-61-2);
  font-size: 11px;
  font-weight: 500;
}

.rack-stage-header strong {
  display: block !important;
  font-size: 17px !important;
  font-weight: 800 !important;
}

.rack-stage-header span {
  display: block !important;
  margin-top: 4px !important;
  color: var(--rv-muted) !important;
  font-size: 12px !important;
}

.rack-stage-meta {
  padding: 8px 12px !important;
  border-radius: 10px !important;
  background: var(--f-ton-96-2) !important;
  color: var(--rv-text) !important;
  font-size: 12px !important;
  font-weight: 800 !important;
}

/* Rack-Grafik */
.rack-visual-wrapper {
  display: flex !important;
  justify-content: center !important;
  width: 100% !important;
  padding: 16px 0 !important;
}

.rack-summary {
  display: grid !important;
  grid-template-columns: repeat(3, 1fr) !important;
  gap: 10px !important;
  margin-top: 20px !important;
}

.summary-item {
  padding: 13px !important;
  border-radius: 12px !important;
  background: var(--f-grau-98) !important;
}

.summary-item span {
  display: block !important;
  margin-bottom: 5px !important;
  color: var(--rv-muted) !important;
  font-size: 11px !important;
}

.summary-item strong {
  color: var(--rv-text) !important;
  font-size: 20px !important;
}

/* Gerätedetails */
.port-detail-header {
  display: flex !important;
  align-items: center !important;
  gap: 14px !important;
  margin-bottom: 24px !important;
}

.port-large-icon {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  flex: 0 0 auto !important;
  width: 58px !important;
  height: 58px !important;
  border-radius: 17px !important;
  background: var(--f-ton-97-7) !important;
  color: var(--rv-blue) !important;
  font-size: 27px !important;
}

/* Dieselbe gezeichnete Buchse wie im Raster, nur groesser */
.port-large-jack {
  width: 30px !important;

  --jack-kante: var(--f-ton-84);
  --jack-fuellung: var(--f-ton-96-2);
  --jack-oeffnung: var(--f-ton-11);
  --jack-stecker: var(--f-ton-65-2);
  --jack-teil: var(--f-ton-65-2);
  --jack-poe: var(--f-amber-50);
}

.port-detail-header h3 {
  margin: 0 !important;
  font-size: 19px !important;
  font-weight: 800 !important;
}

.port-detail-header p {
  margin: 5px 0 0 !important;
  font-size: 13px !important;
}

.status-badge {
  display: inline-flex !important;
  align-items: center !important;
  padding: 6px 9px !important;
  border-radius: 8px !important;
  background: var(--f-ton-96-3) !important;
  color: var(--t-gruen-24-2) !important;
  font-size: 11px !important;
  font-weight: 800 !important;
}

.port-overview-list {
  display: flex !important;
  flex-direction: column !important;
  gap: 7px !important;
}

.port-overview-item {
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  width: 100% !important;
  box-sizing: border-box !important;
  padding: 10px 12px !important;
  border: 1px solid var(--rv-border) !important;
  border-radius: 10px !important;
  background: var(--f-grau-100) !important;
  color: var(--rv-text) !important;
  text-align: left !important;
}

.port-overview-item:hover {
  border-color: var(--f-blau-78) !important;
  background: var(--f-ton-97-7) !important;
}

/* Port-Statistik */
.port-stat-list {
  display: grid !important;
  grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
  gap: 8px !important;
  margin-bottom: 18px !important;
}

.port-stat {
  padding: 12px !important;
  border-radius: 12px !important;
  background: var(--f-grau-98) !important;
}

.port-stat-label {
  display: block !important;
  margin-bottom: 5px !important;
  color: var(--rv-muted) !important;
  font-size: 11px !important;
}

.port-stat strong {
  font-size: 22px !important;
  color: var(--rv-text) !important;
}

.port-progress {
  height: 8px !important;
  margin-bottom: 20px !important;
  overflow: hidden !important;
  border-radius: 999px !important;
  background: var(--f-grau-91) !important;
}

.port-progress-value {
  height: 100% !important;
  border-radius: inherit !important;
  background: var(--rv-blue) !important;
}

.port-overview-item span:not(.port-dot) {
  font-size: 12px !important;
}

.port-overview-item strong {
  margin-left: auto !important;
  color: var(--rv-muted) !important;
  font-size: 11px !important;
}

.port-dot {
  width: 8px !important;
  height: 8px !important;
  flex: 0 0 8px !important;
  border-radius: 50% !important;
  background: var(--f-ton-84) !important;
}

.port-dot.connected {
  background: var(--f-gruen-39) !important;
}

/* Port-Details */
.detail-list {
  display: flex !important;
  flex-direction: column !important;
  gap: 0 !important;
  margin: 0 !important;
}

.detail-list > div {
  display: flex !important;
  justify-content: space-between !important;
  gap: 16px !important;
  padding: 14px 0 !important;
  border-bottom: 1px solid var(--rv-border) !important;
}

.detail-list dt {
  color: var(--rv-muted) !important;
  font-size: 12px !important;
}

.detail-list dd {
  margin: 0 !important;
  color: var(--rv-text) !important;
  font-size: 12px !important;
  font-weight: 700 !important;
  text-align: right !important;
}

/* Netzwerkplan */

/* Leere Zustände */
.empty-state {
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  justify-content: center !important;
  min-height: 300px !important;
  padding: 30px !important;
  text-align: center !important;
}

.compact-empty {
  min-height: 260px !important;
}

.empty-state-icon {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 56px !important;
  height: 56px !important;
  margin-bottom: 14px !important;
  border-radius: 17px !important;
  background: var(--f-ton-97-7) !important;
  color: var(--rv-blue) !important;
  font-size: 27px !important;
}

.empty-state h3 {
  margin: 0 0 8px !important;
  color: var(--rv-text) !important;
  font-size: 17px !important;
}

.empty-state p {
  max-width: 300px !important;
  margin: 0 0 18px !important;
  color: var(--rv-muted) !important;
  font-size: 13px !important;
  line-height: 1.5 !important;
}

.muted-empty {
  color: var(--rv-muted) !important;
  font-size: 12px !important;
}

/* Mobile / kleinere Displays */
@media (max-width: 1280px) {
  :root {
    --rv-sidebar-width: 220px;
  }

  .app-shell > .topbar,
  .topbar {
    padding-left: 24px !important;
    padding-right: 24px !important;
  }

  .dashboard-layout {
    padding: 24px !important;
  }

  .dashboard-top-row {
    grid-template-columns: minmax(0, 1fr) !important;
  }

  .dashboard-bottom-row {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
  }
}

@media (max-width: 850px) {
  :root {
    --rv-sidebar-width: 0px;
  }

  .app-shell > .sidebar,
  .sidebar {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    bottom: auto !important;
    width: calc(100% - 24px) !important;
    max-width: none !important;
    min-width: 0 !important;
    margin: 12px !important;
  }

  .app-shell > .topbar,
  .topbar,
  .dashboard-layout {
    margin-left: 0 !important;
  }

  .app-shell > .topbar,
  .topbar {
    position: relative !important;
  }

  .dashboard-layout {
    padding: 16px !important;
  }

  .dashboard-bottom-row {
    grid-template-columns: minmax(0, 1fr) !important;
  }
}

/* SAFE MODAL BACKDROP OVERRIDE */

</style>

<style>
/* =========================================================
   RackView – einheitliches Modal-System
   ========================================================= */

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1000;

  display: flex;
  align-items: center;
  justify-content: center;

  padding: 24px;

  background: var(--f-schatten-11-6);

  animation: modalBackdropIn 0.18s ease-out;
}

.modal,
.modal-standard,
.modal-large {
  width: min(100%, 680px);
  max-height: calc(100vh - 48px);

  display: flex;
  flex-direction: column;

  overflow: hidden;

  border: 1px solid var(--f-ton-91-4);
  border-radius: 22px;

  background: var(--f-grau-100);
  box-shadow:
    0 30px 90px var(--f-schatten-11-7),
    0 8px 30px var(--f-schatten-11-4);

  animation: modalCardIn 0.22s ease-out;
}

.modal-large {
  width: min(100%, 900px);
}

.modal-port {
  width: min(100%, 650px);
}

.modal-header {
  flex: 0 0 auto;

  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;

  padding: 26px 28px 22px;

  border-bottom: 1px solid var(--f-ton-95);
  background: linear-gradient(180deg, var(--f-grau-100) 0%, var(--f-grau-99) 100%);
}

.modal-header h2 {
  margin: 5px 0 0;

  color: var(--t-ton-16);
  font-size: 23px;
  line-height: 1.2;
  font-weight: 800;
  letter-spacing: -0.6px;
}

.modal-subtitle {
  margin: 8px 0 0;
  color: var(--t-ton-60);
  font-size: 12px;
}

.eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;

  color: var(--t-blau-60);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 1.4px;
}

.modal-close {
  flex: 0 0 auto;

  display: grid;
  place-items: center;

  width: 36px;
  height: 36px;

  border: 1px solid var(--f-ton-91-4);
  border-radius: 11px;

  color: var(--t-ton-52);
  background: var(--f-grau-100);

  cursor: pointer;
  font-size: 22px;
  line-height: 1;

  transition:
    background 0.15s ease,
    border-color 0.15s ease,
    color 0.15s ease,
    transform 0.15s ease;
}

.modal-close:hover {
  color: var(--t-blau-48-2);
  border-color: var(--f-blau-87);
  background: var(--f-ton-97-7);
  transform: scale(1.04);
}

.modal > form,
.modal-standard > form,
.modal-large > form {
  min-height: 0;
  overflow-y: auto;
}

.modal form {
  padding: 0;
}

.modal-section {
  padding: 24px 28px;
}

.modal-section + .modal-section {
  border-top: 1px solid var(--f-ton-95);
}

.modal-section-title {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.modal-section-icon {
  display: grid;
  place-items: center;

  width: 38px;
  height: 38px;

  border-radius: 12px;
  color: var(--t-blau-51);
  background: var(--f-ton-96-4);

  font-size: 18px;
}

.modal-section-title h3 {
  margin: 0;
  color: var(--t-ton-23-2);
  font-size: 14px;
  font-weight: 800;
}

.modal-section-title p {
  margin: 4px 0 0;
  color: var(--t-ton-60);
  font-size: 11px;
}

.modal .form-grid {
  gap: 18px;
}

.modal label {
  gap: 8px;
  color: var(--t-ton-40);
  font-size: 12px;
  font-weight: 700;
}

.modal input,
.modal select,
.modal textarea {
  box-sizing: border-box;

  min-height: 42px;
  padding: 11px 13px;

  border: 1px solid var(--f-ton-91-5);
  border-radius: 11px;

  color: var(--t-ton-23-2);
  background: var(--f-grau-100);

  font: inherit;
  font-size: 13px;

  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    background 0.15s ease;
}

.modal textarea {
  resize: vertical;
  min-height: 96px;
}

.modal input::placeholder,
.modal textarea::placeholder {
  color: var(--t-ton-70);
}

.modal input:focus,
.modal select:focus,
.modal textarea:focus {
  outline: none;
  border-color: var(--f-blau-78-2);
  background: var(--f-grau-100);
  box-shadow: 0 0 0 4px var(--f-blau-60);
}

.modal-footer {
  flex: 0 0 auto;

  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;

  padding: 18px 28px;

  border-top: 1px solid var(--f-ton-95);
  background: var(--f-grau-99);
}

.modal-footer .secondary-button {
  margin-right: auto;
}

.modal-footer .primary-button,
.modal-footer .secondary-button {
  min-height: 40px;
  padding: 10px 16px;
  border-radius: 10px;
}

.modal .position-preview {
  margin: 20px 0 0;
}

.modal .device-position-actions {
  align-self: end;
  justify-content: flex-end;
}

@keyframes modalBackdropIn {
  from {
    opacity: 0;
  }

  to {
    opacity: 1;
  }
}

@keyframes modalCardIn {
  from {
    opacity: 0;
    transform: translateY(12px) scale(0.985);
  }

  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@media (max-width: 720px) {
  .modal-backdrop {
    align-items: flex-end;
    padding: 10px;
  }

  .modal,
.modal-standard,
.modal-large {
    width: 100%;
    max-height: calc(100vh - 20px);
    border-radius: 20px;
  }

  .modal-header {
    padding: 21px 20px 18px;
  }

  .modal-header h2 {
    font-size: 20px;
  }

  .modal-section {
    padding: 20px;
  }

  .modal .form-grid {
    grid-template-columns: 1fr;
  }

  .modal-footer {
    padding: 15px 20px;
  }

  .modal-footer .secondary-button {
    margin-right: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .modal-backdrop,
.modal {
    animation: none;
  }
}

/* =================================================
   Einheitliche Modal-Fenster
   ================================================= */

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--f-grau-98);
  overflow-y: auto;
  isolation: isolate;
}

/* Der gesamte Dashboard-Inhalt bleibt vollständig hinter dem Modal */
.modal-backdrop::before {
  content: "";
  position: fixed;
  inset: 0;
  z-index: -1;
  background: var(--f-grau-98);
}

/* Modal selbst bleibt sichtbar über dem vollständig deckenden Hintergrund */
.modal-backdrop > .modal {
  position: relative;
  z-index: 1;
}

.modal {
  position: relative;
  width: min(100%, 560px);
  max-height: calc(100vh - 48px);
  overflow-y: auto;
  margin: auto;
  padding: 28px;
  border: 1px solid var(--f-schleier-65);
  border-radius: 24px;
  background: var(--panel-bg, var(--f-grau-100));
  box-shadow:
    0 24px 80px var(--f-schatten-11-8),
    0 8px 24px var(--f-schatten-11-3);
  color: var(--text-primary, var(--t-ton-11));
}

.modal-standard {
  width: min(100%, 560px);
}

.modal-large,
.device-details-modal {
  width: min(100%, 780px);
}

.modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 24px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--border-color, var(--f-grau-91));
}

.modal-header h2 {
  margin: 4px 0 0;
  font-size: 24px;
  line-height: 1.2;
  letter-spacing: -0.04em;
}

.modal-subtitle {
  margin: 8px 0 0;
  color: var(--text-secondary, var(--t-ton-47-3));
  font-size: 14px;
}

.modal-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: 36px;
  height: 36px;
  padding: 0;
  border: 0;
  border-radius: 12px;
  background: var(--surface-muted, var(--f-ton-96-2));
  color: var(--text-secondary, var(--t-ton-47-3));
  font-size: 24px;
  line-height: 1;
  cursor: pointer;
  transition:
    background 0.2s ease,
    color 0.2s ease,
    transform 0.2s ease;
}

.modal-close:hover {
  background: var(--border-color, var(--f-ton-91-4));
  color: var(--text-primary, var(--t-ton-11));
  transform: scale(1.04);
}

.modal form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.modal form label {
  display: flex;
  flex-direction: column;
  gap: 7px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-secondary, var(--t-ton-35));
}

.modal form input,
.modal form select,
.modal form textarea {
  width: 100%;
  min-height: 44px;
  padding: 11px 13px;
  border: 1px solid var(--border-color, var(--f-ton-90-2));
  border-radius: 12px;
  background: var(--input-bg, var(--f-grau-100));
  color: var(--text-primary, var(--t-ton-11));
  font: inherit;
  font-size: 14px;
  outline: none;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease;
  box-sizing: border-box;
}

.modal form textarea {
  min-height: 92px;
  resize: vertical;
}

.modal form input:focus,
.modal form select:focus,
.modal form textarea:focus {
  border-color: var(--accent-color, var(--f-ton-47));
  box-shadow: 0 0 0 4px var(--f-schleier-47);
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
  padding-top: 20px;
  border-top: 1px solid var(--border-color, var(--f-grau-91));
}

.modal-footer button {
  min-height: 42px;
}

@media (max-width: 640px) {
  .modal-backdrop {
    align-items: flex-start;
    padding: 12px;
  }

  .modal,
.modal-standard,
.modal-large,
.device-details-modal {
    width: 100%;
    max-height: calc(100vh - 24px);
    padding: 20px;
    border-radius: 20px;
  }

  .modal-header h2 {
    font-size: 21px;
  }

  .modal-footer {
    flex-direction: column-reverse;
    align-items: stretch;
  }

  .modal-footer button {
    width: 100%;
  }
}

/* =========================================================
   Druckansicht fuer "Als PDF drucken"
   Steht am Dateiende und nutzt !important, weil das globale
   Layout Abstaende und Positionen ebenfalls mit !important setzt.
   ========================================================= */

@media print {
  @page {
    size: A4 landscape;
    margin: 12mm;
  }

  html,
  body {
    background: #ffffff !important;
  }

  /* Navigation und Bedienelemente gehoeren nicht aufs Papier */
  .sidebar,
  .topbar-actions,
  .page-actions,
  .rack-selector,
  .racks-overview,
  .alert,
  .modal-backdrop,
  .panel-heading button,
  .icon-button {
    display: none !important;
  }

  .topbar,
  .dashboard-layout {
    position: static !important;
    margin: 0 !important;
    padding: 0 0 12px !important;
    border: 0 !important;
    background: none !important;
    backdrop-filter: none !important;
  }

  .panel {
    break-inside: avoid;
    box-shadow: none !important;
  }

  /* Dunkles Rack und farbige Status auch im Druck erhalten */
  .rack-visual,
  .rack-device-block,
  .rack-device-row-status,
  .status-pill {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
}

/* =========================================================
   Ports-Seite: Raster und Details nebeneinander
   Steht am Dateiende und nutzt !important, weil die Reihe weiter
   oben ebenfalls mit !important drei Spalten festlegt.
   ========================================================= */

.dashboard-bottom-row.ports-layout {
  grid-template-columns: minmax(0, 1.4fr) minmax(320px, 1fr) !important;
}

@media (max-width: 850px) {
  .dashboard-bottom-row.ports-layout {
    grid-template-columns: minmax(0, 1fr) !important;
  }

  /* Die Kopfzeile ist auf dem Telefon der groesste Posten, bevor der
     Inhalt beginnt. Sie wird flacher, und der Pfad entfaellt - wo man
     ist, steht schon im Menue-Knopf und in der Ueberschrift darunter. */
  .app-shell > .topbar,
  .topbar {
    min-height: 0 !important;
    padding: 12px 16px !important;
  }

  .topbar .breadcrumb {
    display: none !important;
  }
}


/* Waehrend ein Geraet im Rack gezogen wird: ueberall die Greifhand,
   nichts wird markiert (Klasse setzt App.vue am body) */
body.rack-dragging,
body.rack-dragging * {
  cursor: grabbing !important;
  -webkit-user-select: none !important;
  user-select: none !important;
}
</style>

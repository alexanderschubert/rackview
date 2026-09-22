#!/usr/bin/env bash
# =============================================================
# RackView – Start des Ein-Container-Images
# =============================================================
# Ablauf:
#   1. Datenpfad /config vorbereiten
#   2. Anwendungsschluessel besorgen (oder einmalig erzeugen)
#   3. Datenbank: eingebaut oder extern
#   4. .env schreiben, Migrationen, Caches
#   5. Prozesse an Supervisor uebergeben
#
# Alles, was ueberleben muss, liegt unter /config:
#   /config/db            Datenverzeichnis von PostgreSQL
#   /config/uploads       hochgeladene Geraetebilder
#   /config/backups       naechtliche Datenbanksicherungen
#   /config/app.key       Anwendungsschluessel von Laravel
#   /config/db-passwort   Passwort der eingebauten Datenbank
# =============================================================
set -euo pipefail

CONFIG=/config
APP=/var/www/html
PGBIN="${PGBIN:-/usr/lib/postgresql/17/bin}"
# Kommt normalerweise aus dem Dockerfile, hier noch einmal als
# Rueckfallwert, damit das Skript auch allein lauffaehig ist.
PGDATA="${PGDATA:-${CONFIG}/db}"

log()  { echo "[rackview] $*"; }
warn() { echo "[rackview] ACHTUNG: $*" >&2; }
fehler() { echo "[rackview] FEHLER: $*" >&2; exit 1; }

# -------------------------------------------------------------
# 0. Zeitzone
#    Betrifft die Uhrzeit der Sicherung und die Logs. Laravel
#    selbst rechnet weiterhin in UTC.
# -------------------------------------------------------------
if [ -n "${TZ:-}" ] && [ -f "/usr/share/zoneinfo/${TZ}" ]; then
  ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime
  echo "${TZ}" > /etc/timezone
  log "Zeitzone: ${TZ}"
fi

# -------------------------------------------------------------
# 1. Datenpfad vorbereiten
# -------------------------------------------------------------
mkdir -p "${CONFIG}/uploads" "${CONFIG}/backups"

if [ ! -w "${CONFIG}" ]; then
  fehler "${CONFIG} ist nicht beschreibbar. Ist der Datenpfad richtig eingebunden?"
fi

# Laravel legt Bilder unter storage/app/public ab. Statt sie im
# Container zu lassen, zeigt der Pfad in den Datenpfad.
rm -rf "${APP}/storage/app/public"
ln -sfn "${CONFIG}/uploads" "${APP}/storage/app/public"
chown -R www-data:www-data "${CONFIG}/uploads"

# -------------------------------------------------------------
# 2. Anwendungsschluessel
#    Er verschluesselt unter anderem die 2FA-Geheimnisse. Aendert
#    er sich, sind alle bestehenden Sitzungen und 2FA-Einrichtungen
#    ungueltig - deshalb wird er dauerhaft abgelegt.
# -------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
  if [ -s "${CONFIG}/app.key" ]; then
    APP_KEY="$(cat "${CONFIG}/app.key")"
  else
    APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    printf '%s\n' "${APP_KEY}" > "${CONFIG}/app.key"
    chmod 600 "${CONFIG}/app.key"
    log "Neuen Anwendungsschluessel erzeugt und in ${CONFIG}/app.key abgelegt."
    warn "Dieser Pfad muss dauerhaft eingebunden bleiben. Geht die Datei"
    warn "verloren, sind alle Sitzungen und alle 2FA-Einrichtungen ungueltig."
  fi
fi
export APP_KEY

# -------------------------------------------------------------
# 3. Datenbank
# -------------------------------------------------------------
# Ohne DB_HOST laeuft PostgreSQL im selben Container. Mit DB_HOST
# wird eine vorhandene Datenbank benutzt (z.B. der db-Container
# aus docker-compose.prod.yml).
INTERNE_DB=0
if [ -z "${DB_HOST:-}" ] || [ "${DB_HOST}" = "internal" ] || [ "${DB_HOST}" = "intern" ]; then
  INTERNE_DB=1
  DB_HOST=127.0.0.1
  DB_PORT="${DB_PORT:-5432}"
  DB_DATABASE="${DB_DATABASE:-rackview}"
  DB_USERNAME="${DB_USERNAME:-rackview}"
else
  DB_PORT="${DB_PORT:-5432}"
  for var in DB_DATABASE DB_USERNAME DB_PASSWORD; do
    if [ -z "${!var:-}" ]; then
      fehler "${var} fehlt. Bei externer Datenbank muessen DB_DATABASE, DB_USERNAME und DB_PASSWORD gesetzt sein."
    fi
  done
fi

# Als Datenbankbenutzer laufen lassen. setpriv statt su: die
# Argumente bleiben so einzeln erhalten und muessen nicht durch
# eine zweite Shell.
als_postgres() {
  setpriv --reuid=postgres --regid=postgres --init-groups "$@"
}

psql_intern() {
  als_postgres psql -v ON_ERROR_STOP=1 -h /var/run/postgresql -d postgres "$@"
}

# Wenn die Einrichtung abbricht, darf die Datenbank nicht einfach
# weiterlaufen: sonst bleibt eine postmaster.pid liegen und der
# naechste Start muss sie wegraeumen. Beim regulaeren Ende greift der
# Trap nicht, weil supervisord per exec uebernimmt.
PG_LAEUFT=0
aufraeumen() {
  if [ "${PG_LAEUFT:-0}" = "1" ]; then
    echo "[rackview] Beende die Datenbank nach dem Abbruch ..." >&2
    als_postgres "${PGBIN}/pg_ctl" -D "${PGDATA}" -w -t 30 -m fast stop >/dev/null 2>&1 || true
  fi
}
trap aufraeumen EXIT

if [ "${INTERNE_DB}" = "1" ]; then
  log "Eingebaute Datenbank wird verwendet (kein DB_HOST gesetzt)."

  # Passwort der eingebauten Datenbank. PostgreSQL hoert nur auf
  # 127.0.0.1 im Container, das Passwort verlaesst ihn nie.
  if [ -z "${DB_PASSWORD:-}" ]; then
    if [ -s "${CONFIG}/db-passwort" ]; then
      DB_PASSWORD="$(cat "${CONFIG}/db-passwort")"
    else
      DB_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
      printf '%s\n' "${DB_PASSWORD}" > "${CONFIG}/db-passwort"
      chmod 600 "${CONFIG}/db-passwort"
    fi
  fi

  # Wir rufen initdb direkt auf, nicht ueber pg_createcluster - der
  # Pfad des Unix-Sockets wird deshalb ueberall ausdruecklich gesetzt.
  install -d -o postgres -g postgres -m 2775 /var/run/postgresql

  if [ ! -s "${PGDATA}/PG_VERSION" ]; then
    log "Lege das Datenverzeichnis von PostgreSQL an ..."
    mkdir -p "${PGDATA}"
    chown postgres:postgres "${PGDATA}"
    chmod 700 "${PGDATA}"

    # Sortierung nach deutschen Regeln (ICU). Sollte die Ausgabe
    # von PostgreSQL das nicht koennen, tut es auch die einfache
    # Sortierung - dann stehen Umlaute hinter z.
    als_postgres "${PGBIN}/initdb" -D "${PGDATA}" -E UTF8 \
        --locale=C.UTF-8 --locale-provider=icu --icu-locale=de-DE \
        --auth-local=peer --auth-host=scram-sha-256 \
      || { warn "PostgreSQL ohne ICU - es wird die einfache Sortierung benutzt."
           rm -rf "${PGDATA:?}"/*
           als_postgres "${PGBIN}/initdb" -D "${PGDATA}" -E UTF8 \
               --locale=C.UTF-8 \
               --auth-local=peer --auth-host=scram-sha-256; }
  else
    chown postgres:postgres "${PGDATA}"
    chmod 700 "${PGDATA}"
  fi

  log "Starte PostgreSQL fuer die Einrichtung ..."
  : > /tmp/postgres-start.log
  chown postgres:postgres /tmp/postgres-start.log

  als_postgres "${PGBIN}/pg_ctl" -D "${PGDATA}" -w -t 60 \
      -o "-c listen_addresses=127.0.0.1 -p ${DB_PORT} -c unix_socket_directories=/var/run/postgresql" \
      -l /tmp/postgres-start.log start \
    || { cat /tmp/postgres-start.log >&2; fehler "PostgreSQL ist nicht gestartet."; }
  PG_LAEUFT=1

  # Rolle und Datenbank anlegen, falls noch nicht vorhanden. Das
  # Passwort wird bei jedem Start gesetzt, damit es auch nach einem
  # Wechsel wieder passt.
  if [ "$(psql_intern -tAc "SELECT 1 FROM pg_roles WHERE rolname = '${DB_USERNAME}'")" != "1" ]; then
    log "Lege den Datenbankbenutzer ${DB_USERNAME} an ..."
    psql_intern -c "CREATE ROLE \"${DB_USERNAME}\" LOGIN" >/dev/null
  fi
  # Einfache Anfuehrungszeichen im Passwort verdoppeln, so verlangt es
  # SQL. Die psql-Variable :'pw' hilft hier nicht: bei -c geht die
  # Zeile unveraendert an den Server, psql ersetzt darin nichts.
  HOCHKOMMA="'"
  PASSWORT_SQL="${DB_PASSWORD//${HOCHKOMMA}/${HOCHKOMMA}${HOCHKOMMA}}"
  psql_intern -c "ALTER ROLE \"${DB_USERNAME}\" WITH PASSWORD '${PASSWORT_SQL}'" >/dev/null

  if [ "$(psql_intern -tAc "SELECT 1 FROM pg_database WHERE datname = '${DB_DATABASE}'")" != "1" ]; then
    log "Lege die Datenbank ${DB_DATABASE} an ..."
    psql_intern -c "CREATE DATABASE \"${DB_DATABASE}\" OWNER \"${DB_USERNAME}\"" >/dev/null
  fi
fi

export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

# -------------------------------------------------------------
# 3b. Auf die Datenbank warten
#     Deckt den Neustart einer externen Datenbank im laufenden
#     Betrieb ab.
# -------------------------------------------------------------
log "Warte auf die Datenbank ${DB_HOST}:${DB_PORT} ..."
for versuch in $(seq 1 30); do
  if php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT"), $e, $s, 2) ? 0 : 1);'; then
    log "Datenbank erreichbar."
    break
  fi
  [ "${versuch}" -eq 30 ] && fehler "Datenbank nach 60 Sekunden nicht erreichbar."
  sleep 2
done

# -------------------------------------------------------------
# 3c. Einmaliger Import einer Sicherung
#     Wer von einem anderen Stand umzieht, legt den pg_dump als
#     /config/restore.sql oder /config/restore.sql.gz ab. Der
#     Import laeuft nur, solange die Datenbank noch leer ist.
# -------------------------------------------------------------
IMPORT=""
[ -s "${CONFIG}/restore.sql" ]    && IMPORT="${CONFIG}/restore.sql"
[ -s "${CONFIG}/restore.sql.gz" ] && IMPORT="${CONFIG}/restore.sql.gz"

if [ -n "${IMPORT}" ]; then
  export PGPASSWORD="${DB_PASSWORD}"
  tabellen="$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -tAc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")"

  if [ "${tabellen}" = "0" ]; then
    log "Importiere ${IMPORT} ..."
    if [ "${IMPORT}" = "${CONFIG}/restore.sql.gz" ]; then
      gzip -dc "${IMPORT}" | psql -v ON_ERROR_STOP=1 -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" >/dev/null
    else
      psql -v ON_ERROR_STOP=1 -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -f "${IMPORT}" >/dev/null
    fi
    mv "${IMPORT}" "${IMPORT}.importiert-$(date +%Y%m%d_%H%M%S)"
    log "Import abgeschlossen."
  else
    warn "${IMPORT} wird nicht importiert: die Datenbank enthaelt bereits Tabellen."
  fi
  unset PGPASSWORD
fi

# -------------------------------------------------------------
# 4. .env schreiben
#    Im Image liegt keine .env - alle Werte kommen aus den
#    Variablen des Containers.
# -------------------------------------------------------------
cd "${APP}"

# Werte in Anfuehrungszeichen setzen. Laravels .env-Leser bricht sonst
# bei jedem Wert mit Leerzeichen ab ("Encountered unexpected whitespace")
# - und dann startet die Anwendung ueberhaupt nicht mehr. Innerhalb der
# Anfuehrungszeichen muessen \ und " geschuetzt werden.
wert() {
  local text="${1-}"

  text="${text//\\/\\\\}"
  text="${text//\"/\\\"}"

  printf '"%s"' "${text}"
}

cat > .env <<ENVFILE
APP_NAME=$(wert "${APP_NAME:-RackView}")
APP_ENV=production
APP_KEY=$(wert "${APP_KEY}")
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=$(wert "${APP_URL:-http://localhost}")

LOG_CHANNEL=stderr
LOG_LEVEL=${LOG_LEVEL:-warning}

DB_CONNECTION=pgsql
DB_HOST=$(wert "${DB_HOST}")
DB_PORT=${DB_PORT}
DB_DATABASE=$(wert "${DB_DATABASE}")
DB_USERNAME=$(wert "${DB_USERNAME}")
DB_PASSWORD=$(wert "${DB_PASSWORD}")

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

RACKVIEW_REGISTRATION=${RACKVIEW_REGISTRATION:-true}

OIDC_ENABLED=${OIDC_ENABLED:-false}
OIDC_ISSUER=$(wert "${OIDC_ISSUER:-}")
OIDC_CLIENT_ID=$(wert "${OIDC_CLIENT_ID:-}")
OIDC_CLIENT_SECRET=$(wert "${OIDC_CLIENT_SECRET:-}")
OIDC_LABEL=$(wert "${OIDC_LABEL:-Mit Authentik anmelden}")
ENVFILE
chown root:www-data .env
chmod 640 .env

# -------------------------------------------------------------
# 5. Migrationen und Caches
# -------------------------------------------------------------
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  log "Fuehre die Migrationen aus ..."
  php artisan migrate --force --no-interaction
else
  log "AUTO_MIGRATE=false - Migrationen werden uebersprungen."
fi

log "Baue die Caches ..."
php artisan config:clear --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

chown -R www-data:www-data storage bootstrap/cache

# -------------------------------------------------------------
# 6. Prozesse einrichten
# -------------------------------------------------------------
mkdir -p /etc/supervisor/conf.d
rm -f /etc/supervisor/conf.d/postgres.conf /etc/supervisor/conf.d/cron.conf

if [ "${INTERNE_DB}" = "1" ]; then
  # Fuer den Dauerbetrieb uebernimmt Supervisor die Datenbank -
  # damit wird sie neu gestartet, falls sie sich beendet.
  log "Beende die Einrichtungs-Instanz von PostgreSQL ..."
  als_postgres "${PGBIN}/pg_ctl" -D "${PGDATA}" -w -t 60 -m fast stop
  PG_LAEUFT=0

  cat > /etc/supervisor/conf.d/postgres.conf <<SUPERVISOR
[program:postgres]
command=${PGBIN}/postgres -D ${PGDATA} -c listen_addresses=127.0.0.1 -p ${DB_PORT} -c unix_socket_directories=/var/run/postgresql
user=postgres
autostart=true
autorestart=true
priority=10
# SIGTERM hiesse bei PostgreSQL "warte auf alle Verbindungen" - der
# Container wuerde beim Anhalten ins Zeitlimit laufen und hart beendet.
stopsignal=INT
stopwaitsecs=30
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUPERVISOR
fi

# Naechtliche Sicherung der Datenbank. Die Bilder bleiben aussen
# vor: sie liegen als normale Dateien in /config/uploads und sind
# damit schon von jeder Sicherung des Datenpfads erfasst.
umask 077
cat > /run/rackview-db.env <<DBENV
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
RETENTION_DAYS=${RETENTION_DAYS:-14}
DBENV
umask 022

if [ -n "${BACKUP_SCHEDULE:-}" ]; then
  mkdir -p /etc/cron.d

  printf '%s\n' \
    'SHELL=/bin/bash' \
    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
    "${BACKUP_SCHEDULE} root /usr/local/bin/rackview-sicherung >> /proc/1/fd/1 2>&1" \
    > /etc/cron.d/rackview
  chmod 644 /etc/cron.d/rackview

  cat > /etc/supervisor/conf.d/cron.conf <<'SUPERVISOR'
[program:cron]
command=cron -f
autostart=true
autorestart=true
priority=40
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUPERVISOR

  log "Sicherung der Datenbank: ${BACKUP_SCHEDULE} (Aufbewahrung ${RETENTION_DAYS:-14} Tage)"
else
  log "BACKUP_SCHEDULE ist leer - es wird keine Sicherung angelegt."
fi

log "RackView ist bereit."
exec supervisord -c /etc/supervisor/supervisord.conf

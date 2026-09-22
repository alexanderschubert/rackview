#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

log() { echo "[entrypoint] $*"; }

# -------------------------------------------------------------
# 1. Pflichtwerte pruefen
# -------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
  echo "[entrypoint] FEHLER: APP_KEY ist nicht gesetzt." >&2
  echo "[entrypoint] Im Produktionsbetrieb wird kein Schluessel automatisch erzeugt," >&2
  echo "[entrypoint] weil damit alle verschluesselten Werte und Sessions ungueltig wuerden." >&2
  echo "[entrypoint] Erzeugen mit: docker compose run --rm backend php artisan key:generate --show" >&2
  echo "[entrypoint] und den Wert in die .env eintragen." >&2
  exit 1
fi

for var in DB_DATABASE DB_USERNAME DB_PASSWORD; do
  if [ -z "${!var:-}" ]; then
    echo "[entrypoint] FEHLER: ${var} ist nicht gesetzt (fehlt die .env im Projektroot?)." >&2
    exit 1
  fi
done

# -------------------------------------------------------------
# 2. .env aus den Container-Variablen schreiben
#    (im Image liegt keine .env – siehe backend/.dockerignore)
# -------------------------------------------------------------
log "Schreibe .env aus den Container-Variablen ..."
cat > .env <<ENVFILE
APP_NAME=${APP_NAME:-RackView}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-http://localhost}

LOG_CHANNEL=stderr
LOG_LEVEL=${LOG_LEVEL:-warning}

DB_CONNECTION=pgsql
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-5432}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=${SESSION_DRIVER:-database}
CACHE_STORE=${CACHE_STORE:-database}
QUEUE_CONNECTION=${QUEUE_CONNECTION:-database}
ENVFILE

# -------------------------------------------------------------
# 3. Auf die Datenbank warten
#    depends_on deckt den ersten Start ab, nicht aber einen
#    Neustart der DB im laufenden Betrieb.
# -------------------------------------------------------------
log "Warte auf die Datenbank ${DB_HOST:-db}:${DB_PORT:-5432} ..."
for i in $(seq 1 30); do
  if php -r '
      $h = getenv("DB_HOST") ?: "db";
      $p = getenv("DB_PORT") ?: "5432";
      exit(@fsockopen($h, (int) $p, $e, $s, 2) ? 0 : 1);
  '; then
    log "Datenbank erreichbar."
    break
  fi
  if [ "$i" -eq 30 ]; then
    echo "[entrypoint] FEHLER: Datenbank nach 60s nicht erreichbar." >&2
    exit 1
  fi
  sleep 2
done

# -------------------------------------------------------------
# 3b. Symlink public/storage -> storage/app/public
#     Muss bei jedem Start laufen: das Upload-Verzeichnis haengt
#     als Volume im Container, der Link liegt im Image.
# -------------------------------------------------------------
log "Verknüpfe das öffentliche Storage-Verzeichnis ..."
mkdir -p storage/app/public
php artisan storage:link --force >/dev/null 2>&1 || true
chown -R www-data:www-data storage/app/public

# -------------------------------------------------------------
# 4. Migrationen
# -------------------------------------------------------------
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  log "Fuehre Migrationen aus ..."
  php artisan migrate --force --no-interaction
else
  log "AUTO_MIGRATE=false – Migrationen werden uebersprungen."
fi

# -------------------------------------------------------------
# 5. Caches neu aufbauen (Config/Routen/Views)
# -------------------------------------------------------------
log "Baue Konfigurations-, Routen- und View-Cache ..."
php artisan config:clear --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache  --no-interaction

chown -R www-data:www-data storage bootstrap/cache

log "Starte Nginx und PHP-FPM ..."
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf

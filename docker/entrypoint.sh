#!/usr/bin/env bash
set -e

cd /var/www/html

# 1. .env anlegen, falls noch keine existiert (frischer Checkout)
if [ ! -f .env ]; then
  echo "[entrypoint] Keine backend/.env gefunden, erstelle aus .env.example ..."
  cp .env.example .env
fi

# 2. Composer-Abhaengigkeiten installieren, falls vendor/ fehlt
if [ ! -d vendor ]; then
  echo "[entrypoint] Installiere Composer-Abhaengigkeiten ..."
  composer install --no-interaction --prefer-dist
fi

# 3. DB-Zugangsdaten aus den Container-Env-Variablen in backend/.env schreiben,
#    damit compose (root .env) und Laravel (backend/.env) synchron bleiben.
if [ -n "$DB_DATABASE" ]; then
  sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=pgsql/" .env
  sed -i "s/^DB_HOST=.*/DB_HOST=${DB_HOST:-db}/" .env
  sed -i "s/^DB_PORT=.*/DB_PORT=${DB_PORT:-5432}/" .env
  sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_DATABASE}/" .env
  sed -i "s/^DB_USERNAME=.*/DB_USERNAME=${DB_USERNAME}/" .env
  sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASSWORD}/" .env
fi

# 4. APP_KEY generieren, falls keiner gesetzt ist
CURRENT_KEY=$(grep -E "^APP_KEY=" .env | cut -d '=' -f2-)
if [ -z "$CURRENT_KEY" ]; then
  echo "[entrypoint] Generiere APP_KEY ..."
  php artisan key:generate --force
  echo "[entrypoint] Tipp: den neuen APP_KEY aus backend/.env in die root .env kopieren, damit er stabil bleibt."
fi

# 4b. Symlink public/storage -> storage/app/public, damit hochgeladene
#     Geraetebilder ausgeliefert werden koennen.
php artisan storage:link --force >/dev/null 2>&1 || true

# 5. Migrationen automatisch ausfuehren (nur wenn gewuenscht)
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  echo "[entrypoint] Fuehre Migrationen aus ..."
  php artisan migrate --force
fi

echo "[entrypoint] Starte PHP-Server ..."
exec php -S 0.0.0.0:8000 -t public

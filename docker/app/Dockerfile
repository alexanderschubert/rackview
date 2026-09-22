# =============================================================
# RackView – Ein-Container-Image
# =============================================================
# Enthaelt alles, was RackView zum Laufen braucht:
#
#   Nginx        liefert die Oberflaeche aus und reicht /api weiter
#   PHP-FPM      Laravel-Backend
#   PostgreSQL   Datenbank (nur falls kein DB_HOST gesetzt ist)
#   Cron         naechtliche Sicherung der Datenbank
#
# Gedacht fuer Unraid: ein Container, ein Port, ein Datenpfad
# (/config). Wer die Datenbank lieber getrennt betreibt, setzt
# DB_HOST – dann bleibt der eingebaute PostgreSQL aus.
#
# Gebaut wird aus dem Projektroot:
#   docker build -f docker/app/Dockerfile -t rackview:latest .
# oder bequemer mit docker/app/bauen.sh
# =============================================================


# --- Stufe 1: Oberflaeche bauen ------------------------------
FROM node:22-bookworm AS oberflaeche

WORKDIR /app

# Abhaengigkeiten zuerst – bleibt im Cache, solange sich
# package.json/package-lock.json nicht aendern.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build


# --- Stufe 2: Laufzeit ---------------------------------------
FROM php:8.4-fpm-bookworm

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive \
    PGBIN=/usr/lib/postgresql/17/bin \
    PGDATA=/config/db

# --- Systempakete, PostgreSQL 17 und PHP-Erweiterungen -------
# Die Paketquelle von PostgreSQL wird zuerst eingetragen, damit
# apt fuer libpq durchgehend dieselbe Version waehlt.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
    install -d /etc/apt/keyrings; \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
         -o /etc/apt/keyrings/pgdg.asc; \
    echo "deb [signed-by=/etc/apt/keyrings/pgdg.asc] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
         > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        cron \
        gzip \
        git \
        unzip \
        postgresql-17 \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev; \
    # Die Paketinstallation legt einen leeren Cluster an, den RackView
    # nicht benutzt – die Daten liegen spaeter unter /config/db.
    rm -rf /var/lib/postgresql/17/main /etc/postgresql/17/main; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        mbstring \
        bcmath \
        intl \
        zip \
        opcache; \
    rm -rf /var/lib/apt/lists/*

# --- PHP, Nginx, Supervisor ----------------------------------
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/app/php.ini "$PHP_INI_DIR/conf.d/zz-rackview.ini"

RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf
COPY docker/app/nginx.conf /etc/nginx/conf.d/rackview.conf
COPY docker/app/nginx-php.inc /etc/nginx/rackview-php.inc
COPY docker/app/supervisord.conf /etc/supervisor/supervisord.conf

# --- Backend: Abhaengigkeiten zuerst -------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

# --- Backend: Anwendungscode ---------------------------------
COPY backend/ ./
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    composer run-script post-autoload-dump --no-dev; \
    rm -f /usr/bin/composer; \
    mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache

# --- Oberflaeche aus Stufe 1 ---------------------------------
COPY --from=oberflaeche /app/dist /srv/www

# Symbol fuer die Docker-Uebersicht von Unraid, abrufbar unter
# /icon.png - fuer Server, die GitHub nicht erreichen.
COPY unraid/rackview-icon.png /srv/www/icon.png

# --- Start ---------------------------------------------------
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/app/sicherung.sh /usr/local/bin/rackview-sicherung
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/rackview-sicherung

VOLUME ["/config"]
EXPOSE 80

# start-period grosszuegig: beim ersten Start legt PostgreSQL sein
# Datenverzeichnis an und Laravel laesst die Migrationen laufen.
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

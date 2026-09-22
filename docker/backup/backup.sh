#!/bin/sh
set -e
# Ohne pipefail zaehlt in "pg_dump | gzip" nur der Exit-Code von gzip -
# ein abgebrochener Dump wuerde als erfolgreiches Backup durchgehen.
set -o pipefail

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
FILE="/backups/rackview_${TIMESTAMP}.sql.gz"
UPLOADS_FILE="/backups/rackview_${TIMESTAMP}_uploads.tar.gz"

export PGPASSWORD="$DB_PASSWORD"

# ---------------------------------------------------------------
# 1. Datenbank
# ---------------------------------------------------------------
echo "[backup] Starte Backup von ${DB_DATABASE}@${DB_HOST} ..."

if ! pg_dump -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" | gzip > "$FILE"; then
  echo "[backup] FEHLER: pg_dump ist fehlgeschlagen, unvollstaendige Datei wird entfernt." >&2
  rm -f "$FILE"
  exit 1
fi

echo "[backup] Datenbank: $FILE ($(du -h "$FILE" | cut -f1))"

# ---------------------------------------------------------------
# 2. Hochgeladene Geraetebilder
#    Gleicher Zeitstempel wie der Dump, damit zusammengehoerige
#    Staende auf einen Blick erkennbar sind.
# ---------------------------------------------------------------
# Nur echte Dateien zaehlen - Laravels .gitignore und leere
# Geraeteverzeichnisse allein sind kein Grund fuer ein Archiv.
# Bewusst kein "find | grep -q": grep beendet sich beim ersten
# Treffer, find endet dann mit SIGPIPE und pipefail wuerde die
# Bedingung genau dann falsch werten, wenn Bilder vorhanden sind.
# Im Argument von [ ] spielt der Exit-Code dagegen keine Rolle.
if [ -d /uploads ] && [ -n "$(find /uploads -type f ! -name .gitignore 2>/dev/null | head -n 1)" ]; then
  tar -czf "$UPLOADS_FILE" -C /uploads .
  echo "[backup] Bilder:    $UPLOADS_FILE ($(du -h "$UPLOADS_FILE" | cut -f1))"
else
  echo "[backup] Bilder:    keine vorhanden, uebersprungen"
fi

# ---------------------------------------------------------------
# 3. Aufbewahrung
# ---------------------------------------------------------------
RETENTION_DAYS="${RETENTION_DAYS:-14}"
echo "[backup] Loesche Backups aelter als ${RETENTION_DAYS} Tage ..."
find /backups -name "rackview_*.sql.gz" -mtime "+${RETENTION_DAYS}" -delete
find /backups -name "rackview_*_uploads.tar.gz" -mtime "+${RETENTION_DAYS}" -delete

echo "[backup] Aktuelle Backups:"
ls -lh /backups/rackview_* 2>/dev/null || echo "  (keine)"

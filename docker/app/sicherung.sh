#!/usr/bin/env bash
# =============================================================
# RackView – naechtliche Sicherung der Datenbank
# =============================================================
# Wird von Cron aufgerufen; den Zeitplan setzt BACKUP_SCHEDULE.
# Die Zugangsdaten legt der Entrypoint in /run/rackview-db.env ab,
# denn Cron kennt die Umgebung des Containers nicht.
#
# Die Geraetebilder werden bewusst nicht mitgesichert: sie liegen
# als normale Dateien in /config/uploads und sind damit in jeder
# Sicherung des Datenpfads enthalten.
# =============================================================
set -euo pipefail

ZIEL=/config/backups

if [ ! -r /run/rackview-db.env ]; then
  echo "[sicherung] FEHLER: /run/rackview-db.env fehlt - laeuft der Container richtig?" >&2
  exit 1
fi

# shellcheck disable=SC1091
. /run/rackview-db.env

mkdir -p "${ZIEL}"

ZEITSTEMPEL="$(date +%Y%m%d_%H%M%S)"
DATEI="${ZIEL}/rackview_${ZEITSTEMPEL}.sql.gz"

export PGPASSWORD="${DB_PASSWORD}"

echo "[sicherung] Sichere ${DB_DATABASE}@${DB_HOST} nach ${DATEI} ..."

# Ohne pipefail zaehlt in "pg_dump | gzip" nur der Rueckgabewert von
# gzip - ein abgebrochener Dump ginge als erfolgreich durch.
if ! pg_dump -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" | gzip > "${DATEI}"; then
  echo "[sicherung] FEHLER: pg_dump fehlgeschlagen, unvollstaendige Datei wird entfernt." >&2
  rm -f "${DATEI}"
  exit 1
fi

echo "[sicherung] Fertig: ${DATEI} ($(du -h "${DATEI}" | cut -f1))"

TAGE="${RETENTION_DAYS:-14}"
if [ "${TAGE}" -gt 0 ] 2>/dev/null; then
  echo "[sicherung] Entferne Sicherungen aelter als ${TAGE} Tage ..."
  find "${ZIEL}" -name 'rackview_*.sql.gz' -mtime "+${TAGE}" -delete
fi

echo "[sicherung] Vorhandene Sicherungen:"
ls -1sh "${ZIEL}"/rackview_*.sql.gz 2>/dev/null || echo "  (keine)"

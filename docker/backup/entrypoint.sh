#!/bin/sh
set -e

SCHEDULE="${BACKUP_SCHEDULE:-0 3 * * *}"
mkdir -p /backups
touch /var/log/backup.log

echo "${SCHEDULE} /usr/local/bin/backup.sh >> /var/log/backup.log 2>&1" > /etc/crontabs/root
echo "[entrypoint] Backup-Zeitplan: ${SCHEDULE} (Aufbewahrung: ${RETENTION_DAYS:-14} Tage)"

# Beim Container-Start direkt ein erstes Backup ziehen, damit man nicht
# bis zum naechsten Cron-Zeitpunkt warten muss.
/usr/local/bin/backup.sh >> /var/log/backup.log 2>&1 || true

crond -f -l 2 &
CROND_PID=$!

tail -f /var/log/backup.log &
TAIL_PID=$!

wait $CROND_PID $TAIL_PID

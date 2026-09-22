#!/usr/bin/env bash
# =============================================================
# RackView – Image von Hand bauen
# =============================================================
# Das veroeffentlichte Image baut GitHub Actions
# (.github/workflows/image.yml). Dieses Skript ist fuer den
# eigenen Rechner:
#
#   NUR_BAUEN=1 bash docker/app/bauen.sh  baut nur, ohne zu schieben
#   bash docker/app/bauen.sh              baut und schiebt :latest
#   TAG=v1.2 bash docker/app/bauen.sh     anderer Tag statt latest
#
#   REGISTRY=lokal PAKET=rackview NUR_BAUEN=1 bash docker/app/bauen.sh
#                                         eigener Name: lokal/rackview
#
# Zum Schieben einmalig anmelden:
#   docker login ghcr.io
# =============================================================
set -euo pipefail

REGISTRY="${REGISTRY:-ghcr.io}"
PAKET="${PAKET:-alexanderschubert/rackview}"
TAG="${TAG:-latest}"

# Immer aus dem Projektroot bauen, egal von wo aufgerufen.
cd "$(dirname "$0")/../.."

BILD="${REGISTRY}/${PAKET}"

# Zusaetzlicher Tag mit dem Git-Stand, damit man zu einer
# bestimmten Fassung zurueckkehren kann.
STAND="$(git rev-parse --short HEAD 2>/dev/null || true)"

echo "==> Baue ${BILD}:${TAG}"
docker build -f docker/app/Dockerfile -t "${BILD}:${TAG}" .

if [ -n "${STAND}" ]; then
  docker tag "${BILD}:${TAG}" "${BILD}:${STAND}"
  echo "==> Zusaetzlich getaggt: ${BILD}:${STAND}"
fi

if [ -n "${NUR_BAUEN:-}" ]; then
  echo "==> NUR_BAUEN gesetzt - es wird nichts geschoben."
  exit 0
fi

echo "==> Schiebe ${BILD}:${TAG}"
if ! docker push "${BILD}:${TAG}"; then
  echo
  echo "Das Schieben ist fehlgeschlagen. Haeufigster Grund: nicht angemeldet."
  echo "  docker login ${REGISTRY}"
  echo "Als Passwort bei ghcr.io einen Token von GitHub verwenden"
  echo "(Settings -> Developer settings -> Personal access tokens,"
  echo " classic, Bereich: write:packages)."
  exit 1
fi

[ -n "${STAND}" ] && docker push "${BILD}:${STAND}"

echo
echo "Fertig. In Unraid genuegt jetzt bei RackView 'Force Update',"
echo "oder der Container zieht das neue Image beim naechsten Start."

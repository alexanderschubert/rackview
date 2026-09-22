# RackView entwickeln

Für den Betrieb reicht das fertige Image, siehe [README](README.md). Diese Seite
richtet sich an alle, die am Quelltext arbeiten oder das Image selbst bauen wollen.

## Aufbau

```
backend/                  Laravel-API (PHP 8.4)
  docker/                 Produktions-Image des Compose-Stapels (Nginx + PHP-FPM)
frontend/                 Vue-3-Oberfläche (Vite)
docker/
  app/                    Ein-Container-Image: Nginx, PHP-FPM, PostgreSQL, Cron
  backup/                 Backup-Container des Compose-Stapels (Cron + pg_dump)
  entrypoint.sh           Entrypoint des Entwicklungs-Containers
  php.Dockerfile          Entwicklungs-Image (php -S)
templates/rackview.xml    Unraid-Vorlage, auch für den App-Katalog
ca_profile.xml            Profil im Unraid-App-Katalog
unraid/                   Anleitung und Symbol für Unraid
docs/                     Projektseite (GitHub Pages) und Bilder
.github/workflows/        baut, prüft und veröffentlicht das Image
docker-compose.yml        Entwicklungsbetrieb (Projekt: rackview)
docker-compose.prod.yml   Produktionsbetrieb aus dem Quelltext (Projekt: rackview-prod)
```

Es gibt zwei Wege, RackView zu betreiben, und beide haben eigene Dateien:

- **Ein Container** (`docker/app/`) – das veröffentlichte Image. Ein Port, ein
  Datenpfad, die Datenbank läuft mit im Container.
- **Compose-Stapel** (`docker-compose*.yml`, `backend/docker/`,
  `frontend/Dockerfile.prod`) – vier getrennte Container. Für die Entwicklung mit
  Hot Reload gedacht.

Änderungen am Betrieb deshalb immer in beiden Welten prüfen.

## Image selbst bauen

Gebaut wird aus dem Projektroot:

```bash
docker build -f docker/app/Dockerfile -t rackview:latest .
```

oder mit dem Hilfsskript, das zusätzlich einen Tag mit dem Git-Stand vergibt:

```bash
NUR_BAUEN=1 bash docker/app/bauen.sh
```

Der erste Bau dauert einige Minuten (PHP-Erweiterungen, Composer, npm). Spätere
Bauläufe sind deutlich schneller, solange sich `composer.lock` und
`package-lock.json` nicht ändern.

Das veröffentlichte Image baut GitHub Actions
([`.github/workflows/image.yml`](.github/workflows/image.yml)) bei jedem Push auf
`main`. Vor dem Veröffentlichen startet der Ablauf einen frischen Container und
fragt die Schnittstelle ab – ein Stand, der nicht startet, landet nicht in der
Registry.

## Eine Version veröffentlichen

```bash
git tag v1.2.0
git push origin v1.2.0
```

Der Ablauf baut daraus `ghcr.io/alexanderschubert/rackview:1.2.0` und `:1.2`.
Danach auf GitHub unter *Releases → Draft a new release* den Tag wählen und
aufschreiben, was sich geändert hat.

## Entwicklungsbetrieb

Alle Zugangsdaten und Ports kommen aus einer `.env` im Projektroot. Diese Datei
liegt bewusst nicht im Git:

```sh
cp .env.example .env
```

Anschließend in der `.env` mindestens `DB_PASSWORD` setzen. `APP_KEY` darf
beim ersten Start leer bleiben – der Entwicklungs-Entrypoint erzeugt einen
Schlüssel und legt ihn in `backend/.env` ab. Damit er über Neustarts hinweg
stabil bleibt, den erzeugten Wert danach in die root `.env` übernehmen.

> **Achtung:** `DB_DATABASE`, `DB_USERNAME` und `DB_PASSWORD` werden von
> PostgreSQL nur beim allerersten Start übernommen. Spätere Änderungen wirken
> erst nach einem Zurücksetzen des Datenverzeichnisses oder per
> `ALTER USER … WITH PASSWORD …` im laufenden Container.

```sh
docker compose up -d --build
```

| Dienst     | Adresse                 | Beschreibung                       |
|------------|-------------------------|------------------------------------|
| Frontend   | http://localhost:5173   | Vite-Dev-Server mit Hot Reload     |
| Backend    | http://localhost:8022   | Laravel über `php -S`              |
| PostgreSQL | `localhost:55432`       | nur für externe Tools              |

Der Vite-Dev-Server leitet `/api` intern an den `app`-Container weiter
(siehe `frontend/vite.config.js`), das Frontend arbeitet also mit relativen
API-Pfaden – genau wie im Produktionsbetrieb.

Migrationen laufen bei jedem Start des `app`-Containers automatisch. Über
`AUTO_MIGRATE=false` in der `.env` lässt sich das abschalten.

## Produktionsbetrieb aus dem Quelltext

```sh
docker compose -f docker-compose.prod.yml up -d --build
```

Beide Stapel sind getrennte Compose-Projekte (`rackview` und
`rackview-prod`) mit eigenen Containernamen, Netzen, Volumes und Ports.
Sie können deshalb gefahrlos parallel laufen.

Unterschiede zum Entwicklungsbetrieb:

- **Backend** läuft als Nginx + PHP-FPM mit aktiviertem OPcache statt
  `php -S`, mit vorkompiliertem Config-, Routen- und View-Cache.
- **Frontend** wird zu statischen Dateien gebaut (`npm run build`) und von
  Nginx ausgeliefert; derselbe Nginx reicht `/api` an das Backend weiter.
- **Nur ein Port** ist nach außen offen (`PROD_PORT`, Standard 8080). Backend
  und Datenbank sind ausschließlich im internen Docker-Netz erreichbar.
- `APP_ENV=production` und `APP_DEBUG=false` sind fest gesetzt.
- Die Datenbank liegt im Named Volume `rackview-db-data`, **nicht** im
  Bind-Mount `./docker/postgres` des Entwicklungsbetriebs.

`APP_KEY` muss im Produktionsbetrieb in der `.env` gesetzt sein; der
Entrypoint erzeugt bewusst keinen, weil damit alle verschlüsselten Werte
ungültig würden. Einen Schlüssel erzeugen:

```sh
docker compose -f docker-compose.prod.yml run --rm backend php artisan key:generate --show
```

## Datenbank-Sicherungen im Compose-Stapel

Jeder Stapel hat einen eigenen `backup`-Container, der per Cron
`pg_dump`-Dumps ablegt: der Entwicklungs-Stapel nach `backups/postgres/`,
der Produktions-Stapel nach `backups/postgres-prod/` (`BACKUP_DIR` bzw.
`PROD_BACKUP_DIR`). Standardmäßig täglich um 03:00 Uhr, mit 14 Tagen
Aufbewahrung (`BACKUP_SCHEDULE`, `RETENTION_DAYS`). Beim Start des Containers
wird sofort ein erstes Backup gezogen.

```sh
# von Hand sichern
docker compose exec backup /usr/local/bin/backup.sh

# zurückspielen (Entwicklungs-Stapel)
gunzip -c backups/postgres/rackview_JJJJMMTT_HHMMSS.sql.gz | docker compose exec -T db psql -U rackview -d rackview
```

## Daten zwischen den Stapeln übertragen

```sh
docker compose up -d --wait db
docker compose exec -T db pg_dump -U rackview -d rackview --data-only --disable-triggers > /tmp/rackview-daten.sql
docker compose -f docker-compose.prod.yml exec -T db psql -U rackview -d rackview < /tmp/rackview-daten.sql
```

`--wait` ist wichtig: ohne das kehrt `up -d` zurück, bevor PostgreSQL
Verbindungen annimmt. `--data-only` ist richtig, weil das Schema im
Produktions-Stapel bereits über die Migrationen entstanden ist.

Den Umzug vom Compose-Stapel in das Ein-Container-Image beschreibt
[unraid/README.md](unraid/README.md#4-umzug-vom-compose-stapel).

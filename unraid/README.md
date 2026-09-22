# RackView auf Unraid

Alles, was RackView braucht, steckt in **einem** Container: Oberfläche,
Schnittstelle und die PostgreSQL-Datenbank. Installiert und aktualisiert wird
über die Docker-Oberfläche von Unraid.

| Datei | Zweck |
| --- | --- |
| `../templates/rackview.xml` | Vorlage für Unraid – dieselbe Datei nutzt der App-Katalog |
| `rackview-icon.png` | Symbol für die Docker-Übersicht |
| `../docker/app/Dockerfile` | Bauanleitung für das Image |

Das fertige Image liegt unter `ghcr.io/alexanderschubert/rackview` – selbst bauen
muss man nichts.

---

## Warum ein Container statt vier

Container im Standardnetz „bridge" von Unraid können sich **nicht** über ihre
Namen erreichen. Ein Stapel aus Oberfläche, Schnittstelle und Datenbank in
getrennten Containern bräuchte deshalb ein eigenes Netz oder feste IP-Adressen –
beides fehleranfällig und bei jeder Neuinstallation wieder von Hand zu machen.

Deshalb dieses Image: ein Port, ein Datenpfad, fertig.

Wer die Datenbank trotzdem getrennt betreiben will, trägt `DB_HOST` (plus Name,
Benutzer, Passwort) ein – dann bleibt die eingebaute Datenbank aus. Dasselbe
Image kann also beides.

### Was wo liegt

Alles Dauerhafte liegt im Datenpfad, im Container ist das `/config`:

```
/config/db              Datenverzeichnis von PostgreSQL
/config/uploads         hochgeladene Gerätebilder
/config/backups         nächtliche Sicherungen der Datenbank
/config/app.key         Anwendungsschlüssel von Laravel
/config/db-passwort     Passwort der eingebauten Datenbank
```

> **Der Anwendungsschlüssel ist wichtig.** Mit ihm sind die 2FA-Geheimnisse
> verschlüsselt. Geht `app.key` verloren, kann sich niemand mehr mit
> Zwei-Faktor anmelden, und alle Sitzungen sind ungültig.

---

## 1. Vorlage holen

In der Konsole von Unraid (oben rechts `>_`):

```bash
wget -O /boot/config/plugins/dockerMan/templates-user/my-RackView.xml \
  https://raw.githubusercontent.com/alexanderschubert/rackview/main/templates/rackview.xml
```

Die Vorlage liegt damit auf dem USB-Stick und übersteht jeden Neustart.

---

## 2. Container anlegen

1. **Docker** → **Add Container**
2. Oben bei *Select a template* unter **User templates** den Eintrag
   **RackView** wählen
3. Die Felder prüfen:

   | Feld | Wert |
   | --- | --- |
   | Weboberfläche | `8080` – oder ein anderer freier Port |
   | Datenpfad | `/mnt/user/appdata/rackview-app` |
   | Zeitzone | `Europe/Berlin` |
   | Selbstregistrierung | `true` fürs erste Konto |

4. **Apply**

Unraid lädt das Image herunter und startet den Container. Der **erste Start
dauert länger**: PostgreSQL legt sein Datenverzeichnis an und Laravel lässt die
Migrationen laufen. Was gerade passiert, steht im Protokoll:

```bash
docker logs -f RackView
```

Sobald dort `RackView ist bereit` steht, ist die Oberfläche unter
`http://<server>:8080` erreichbar. Beim ersten Aufruf legt man sich ein Konto an –
das erste Konto wird automatisch Administrator. Danach die Selbstregistrierung
auf `false` stellen, vor allem wenn RackView aus dem Internet erreichbar ist.

> Wer den Quelltext selbst auf dem Server liegen hat: Der Datenpfad darf **nicht**
> auf diesen Ordner zeigen. Deshalb der eigene Ordner `rackview-app`.

---

## 3. Updates

In Unraid: **Docker** → unten **Check for Updates** → bei RackView erscheint
*update ready* → anklicken. Im Kontextmenü des Containers gibt es alternativ
**Force update** (dafür oben rechts *Advanced View* einschalten).

Der Container zieht sich das neue Image und startet neu. Die Migrationen laufen
dabei automatisch, die Daten im Datenpfad bleiben unberührt.

**Eine bestimmte Fassung festhalten:** im Feld *Repository* statt `:latest` eine
Versionsnummer eintragen, etwa `ghcr.io/alexanderschubert/rackview:1.0.0`. Welche
es gibt, steht unter
[Packages](https://github.com/alexanderschubert/rackview/pkgs/container/rackview).
Jeder einzelne Stand hat außerdem einen Tag `sha-<commit>` – damit geht es auch
zurück auf eine Fassung zwischen zwei Versionen.

> **Hast du die Vorlage neu heruntergeladen, prüfe Port und Datenpfad.** In der
> Vorlage stehen die Vorgaben `8080` und `/mnt/user/appdata/rackview-app`. Sie
> überschreiben beim Ersetzen von
> `/boot/config/plugins/dockerMan/templates-user/my-RackView.xml`, was du
> eingestellt hattest. Beim Port fällt es sofort auf – liegt dort schon
> etwas, bricht der Start mit `Bind for 0.0.0.0:8080 failed: port is already
> allocated` ab. Beim Datenpfad fällt es zu spät auf: RackView legt im neuen
> Pfad eine leere Datenbank an, und der Bestand wirkt verschwunden (er liegt
> unberührt im alten Pfad). Also im Edit-Fenster jedes Feld durchsehen,
> bevor du *Apply* drückst.

---

## 4. Umzug vom Compose-Stapel

Wer RackView bisher mit `docker-compose.prod.yml` aus dem Quelltext betrieben
hat, legt die Daten **vor dem ersten Start** des neuen Containers bereit. Der
Entrypoint importiert sie dann von selbst, solange die Datenbank noch leer ist.

```bash
ZIEL=/mnt/user/appdata/rackview-app
mkdir -p "$ZIEL/uploads"

# 1. Anwendungsschlüssel übernehmen – sonst sind die 2FA-Einrichtungen hin
grep '^APP_KEY=' /pfad/zum/quelltext/.env | cut -d= -f2- > "$ZIEL/app.key"
chmod 600 "$ZIEL/app.key"

# 2. Datenbank sichern
docker exec rackview-prod-db pg_dump -U rackview rackview > "$ZIEL/restore.sql"

# 3. Gerätebilder übernehmen
docker cp rackview-prod-backend:/var/www/html/storage/app/public/. "$ZIEL/uploads/"
```

Erst danach den Container anlegen. Solange der alte Stapel läuft, ist Port 8080
belegt – den neuen Container also zunächst auf einen anderen Port legen, etwa
`8081`. Im Protokoll muss `Importiere … restore.sql` und `Import abgeschlossen`
stehen. Die importierte Datei wird anschließend umbenannt
(`restore.sql.importiert-…`) und kann gelöscht werden.

Wenn alles stimmt, kann der alte Stapel weg:

```bash
docker compose -f docker-compose.prod.yml down
```

Danach im Container den Port auf 8080 zurückstellen (Docker → RackView →
*Edit*), dann stimmen auch die alten Lesezeichen wieder.

---

## 5. Sicherungen

Der Container legt jede Nacht um 3 Uhr einen Dump nach `/config/backups` und
räumt Dateien älter als 14 Tage weg (beides einstellbar). Die Gerätebilder
werden nicht mitgesichert – sie liegen als gewöhnliche Dateien im Datenpfad und
sind damit in jeder Sicherung des Datenpfads enthalten, etwa durch das Plugin
*CA Appdata Backup*.

Sicherung von Hand:

```bash
docker exec RackView rackview-sicherung
```

Wiederherstellen: Container anhalten, `/config/db` löschen, den gewünschten Dump
als `/config/restore.sql.gz` ablegen, Container starten. Der leere Datenbestand
wird neu angelegt und der Dump eingespielt.

---

## 6. Fehlersuche

```bash
docker logs -f RackView                  # Protokoll
docker exec -it RackView bash            # Konsole im Container
docker exec -it RackView php artisan rackview:user mail@example.de --admin
```

Der letzte Befehl legt ein Konto an oder setzt ein Passwort zurück – nützlich,
wenn die Selbstregistrierung abgeschaltet ist. `--disable-2fa` schaltet die
Zwei-Faktor-Anmeldung eines Kontos ab.

**Container startet nicht.** Das Protokoll nennt den Grund als Zeile mit
`[rackview] FEHLER:`. Häufig: der Datenpfad ist nicht beschreibbar oder eine
eingetragene externe Datenbank ist nicht erreichbar.

**Container startet nicht: `port is already allocated`.** Der Port gehört
schon jemand anderem – meist einem zweiten Container, manchmal einem
`docker-proxy`, der nach einem harten Ende zurückgeblieben ist. Wer draufsitzt:

```bash
docker ps -a --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | grep 8080
netstat -tlnp | grep ':8080'
```

Dann entweder den anderen Container beenden oder RackView im Edit-Fenster auf
einen freien Port legen. Zeigt `netstat` einen Prozess ohne zugehörigen
Container, ist es der verwaiste `docker-proxy` – dessen PID beenden.

**Die Oberfläche lädt, zeigt aber keine Daten.** Dann läuft PHP, aber die
Datenbank nicht – im Protokoll nach Zeilen von PostgreSQL suchen.

**Datenbank auf dem Array.** Läuft der Datenpfad nicht über den Cache, wird
PostgreSQL spürbar langsam. Die Share `appdata` sollte auf *Cache: prefer* oder
*only* stehen.

---

## 7. Anmeldung über Authentik (OIDC)

Optional. Ist sie eingerichtet, steht auf der Anmeldeseite unter den
Feldern ein Knopf „Mit Authentik anmelden". Die Anmeldung mit Passwort
bleibt daneben bestehen – fällt der Anbieter aus, kommt man weiterhin
herein.

**In Authentik:** *Applications → Providers → Create → OAuth2/OpenID
Provider*, Client type **Confidential**. Als Redirect URI genau die
Adresse eintragen, unter der RackView aufgerufen wird:

```
https://<adresse-von-rackview>/api/auth/oidc/callback
```

Dann *Applications → Create* und den Provider zuordnen. Client-ID und
Secret kopieren; der Issuer steht beim Provider und endet auf
`/application/o/<slug>/`.

**In Unraid:** Die fünf Felder stehen in der Vorlage, aber unter
**Advanced View** – der Schalter sitzt oben rechts im Edit-Fenster.
Dort `OIDC_ENABLED` auf `true` setzen und Issuer, Client-ID und Secret
eintragen.

> **Ein bereits angelegter Container kennt neue Felder nicht.** Unraid
> kopiert die Vorlage beim Anlegen einmal; spätere Änderungen daran
> erreichen ihn nicht mehr. Zwei Wege:
>
> * **Von Hand** (ohne Risiko): im Edit-Fenster unten *Add another Path,
>   Port, Variable, Label or Device* → Config Type **Variable**, Key
>   z. B. `OIDC_ENABLED`, Value `true`. Für jede der Variablen einmal.
> * **Vorlage ersetzen** (schneller): die Vorlage mit dem `wget`-Befehl
>   aus [Abschnitt 1](#1-vorlage-holen) neu herunterladen. Achtung – darin
>   stehen die Vorgaben für Port und Datenpfad, nicht deine eigenen Werte;
>   danach im Edit-Fenster **jedes Feld prüfen**, bevor du *Apply* drückst.

**Zur Adresse:** RackView leitet die Rückkehradresse aus der Adresse ab,
unter der es aufgerufen wird. Erreichst du es mal über die IP und mal
über einen Namen, müssen beide Varianten in Authentik eingetragen sein –
sonst lehnt der Anbieter den Rückweg ab.

**Konten:** Wer sich über Authentik anmeldet und noch kein Konto hat,
bekommt eines mit eigenem, leerem Bereich. Gibt es schon ein Konto mit
derselben E-Mail, werden beide verknüpft – aber nur, wenn Authentik die
Adresse als bestätigt meldet. Die Zwei-Faktor-Abfrage von RackView
entfällt bei diesem Weg; die zweite Stufe macht dann Authentik.

Klemmt etwas, steht der Grund im Protokoll des Containers als Zeile mit
`OIDC-Anmeldung fehlgeschlagen`.

---

## 8. Selbst bauen

Nötig nur, wer am Quelltext etwas ändern will. Auf dem Server:

```bash
git clone https://github.com/alexanderschubert/rackview.git
cd rackview
NUR_BAUEN=1 bash docker/app/bauen.sh
```

Das Image heißt danach genauso wie das veröffentlichte, Unraid benutzt es also
ohne weitere Änderung. Aber: **Check for Updates** meldet dann ein Update und
würde es durch das veröffentlichte ersetzen. Wer dauerhaft mit einem eigenen Stand
arbeitet, gibt dem Image einen eigenen Namen und trägt ihn im Feld *Repository*
ein:

```bash
REGISTRY=lokal PAKET=rackview NUR_BAUEN=1 bash docker/app/bauen.sh
# -> lokal/rackview:latest
```

Mehr dazu in [ENTWICKLUNG.md](../ENTWICKLUNG.md).

**Platz im Docker-Speicher.** Das Image ist rund ein Gigabyte groß, dazu kommt
der Zwischenspeicher des Bauvorgangs. Vor dem ersten Bau lohnt ein Blick auf
`docker system df`; aufgeräumt wird mit `docker builder prune`.

---

## Anhang: alle Einstellungen

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `TZ` | `Europe/Berlin` | Zeitzone für Protokolle und Sicherung |
| `RACKVIEW_REGISTRATION` | `true` | Selbstregistrierung auf der Anmeldeseite |
| `APP_URL` | – | nur bei Betrieb hinter einem Reverse-Proxy nötig |
| `APP_KEY` | – | leer lassen, wird erzeugt und in `/config/app.key` abgelegt |
| `AUTO_MIGRATE` | `true` | Datenbank nach einem Update automatisch anpassen |
| `BACKUP_SCHEDULE` | `0 3 * * *` | Cron-Ausdruck; leer schaltet die Sicherung ab |
| `RETENTION_DAYS` | `14` | Aufbewahrung der Sicherungen in Tagen |
| `DB_HOST` | – | leer = eingebaute Datenbank; gesetzt = externe Datenbank |
| `DB_PORT` | `5432` | Port der Datenbank |
| `DB_DATABASE` | `rackview` | Name der Datenbank |
| `DB_USERNAME` | `rackview` | Benutzer der Datenbank |
| `DB_PASSWORD` | – | nur bei externer Datenbank nötig |
| `OIDC_ENABLED` | `false` | Anmeldung über Authentik freischalten |
| `OIDC_ISSUER` | – | Adresse des Anbieters, endet auf `/application/o/<slug>/` |
| `OIDC_CLIENT_ID` | – | Client-ID der Application im Anbieter |
| `OIDC_CLIENT_SECRET` | – | Client-Secret der Application |
| `OIDC_LABEL` | `Mit Authentik anmelden` | Aufschrift des Knopfes |

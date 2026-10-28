# Selbst-Update: Wiederherstellung von Hand

Der Updater (`php artisan ignis:update:run`, gestartet von der Systemseite) macht bei einem fehlgeschlagenen Schritt selbst einen Rollback. Diese Seite ist für die Fälle, in denen das nicht reicht: Der Prozess wurde beendet (Stromausfall), der Rollback selbst ist fehlgeschlagen (`failed_rollback`: der Wartungsmodus bleibt an, die Meldung nennt das Backup) oder die App startet nicht mehr.

## Wo was liegt

| Was | Wo |
|---|---|
| Update-State | `storage/app/update/state.json` |
| Update-Log | `storage/logs/update-<UTC time>.log` |
| Backups | `storage/app/update/backups/<time>-<from>-to-<to>/`: `db.sqlite` oder `db.sql`, `code.zip`, `env` (Kopie der `.env`), `manifest.json`. Sie enthalten die `.env` und die Datenbank: sensibel. |
| Alter Code während eines Swaps (flache Installation) | `.update-old/<old version>/` mit `swap.json` (Journal) und `revert.php` |

## 1. Der normale Weg

```
php artisan ignis:update:recover
```

Das setzt ein unterbrochenes Update anhand seines Backups zurück oder markiert es als erledigt, wenn es schon fertig war. Meldet der Befehl, dass der Besitzer der Sperre noch lebt, obwohl kein Update läuft (Windows kann Prozess-IDs wiederverwenden), prüfe, dass kein `ignis:update:run`-Prozess übrig ist. Führe dann `php artisan ignis:update:recover --force` aus und bestätige (oder hänge `--yes` an).

Nach einem `failed_rollback` behebst du zuerst die Ursache, die die Meldung nennt (gesperrte Dateien, Datenbank nicht erreichbar, Platte voll), und startest den Rollback dann neu: Der Button **Reset** unter *Settings > System* bleibt bei aktivem Wartungsmodus nutzbar (nur dieser POST, der Status und die Systemseite kommen durch den Wartungsmodus; nur für Admins, mit Bestätigung), oder auf dem Server:

```
php artisan ignis:update:rollback --force
```

Das stellt Code, Datenbank und `.env` aus dem neuesten Backup wieder her. Alles, was seit diesem Backup geschrieben wurde, geht verloren. Der Wartungsmodus wird wieder ausgeschaltet, außer er war schon vor dem Update an.

**Nach einer Wiederherstellung von Hand** (Abschnitt 2 oder die manuellen Schritte unten) steht der State weiter auf `failed_rollback` und neue Updates bleiben gesperrt. Markiere ihn als gelöst:

```
php artisan ignis:update:recover --force        # fragt nach Bestätigung; mit --yes entfällt die Frage
```

Der State wird zu `rolled_back` (Grund `resolved_by_hand`, der alte Fehler bleibt in der Meldung erhalten). Das wird abgelehnt, solange noch ein Swap-Journal (`.update-old/*/swap.json`) existiert: Dann wurde der Code nicht wiederhergestellt, führe zuerst `revert.php` aus. Der Wartungsmodus bleibt unberührt: Führe `php artisan up` aus, wenn die Seite bereit ist.

**Git-Checkout:** In einem Checkout (`.git` im App-Verzeichnis) weigern sich recover und rollback zu arbeiten, solange kein Update etwas hinterlassen hat (ein Swap-Journal, ein Lauf, der behauptet zu laufen, oder ein `failed_rollback`).

**`backup_format`:** Das Backup wurde von einer Version mit anderem Backup-Aufbau geschrieben (`format` in seiner `manifest.json`). Diese Version stellt es nicht wieder her; es wurde nichts verändert. Stelle es von Hand wieder her (Abschnitt 2) oder mit der Version, die es geschrieben hat.

**State nicht lesbar (`unknown`, das Panel meldet es):** `storage/app/update/state.json` ist beschädigt. Stelle sicher, dass kein `ignis:update:*`-Prozess läuft, sieh dir das neueste Update-Log an und verschiebe dann die Datei (`mv storage/app/update/state.json storage/app/update/state.json.bad`): Keine Datei heißt "kein Update", und Updates sind wieder entsperrt. Zeigt das Log einen Lauf, der nach `switching` abgebrochen ist, stelle zuerst aus seinem Backup wieder her (Abschnitt 2).

## 2. Wenn artisan nicht startet (flache Installation, Swap auf halbem Weg gestoppt)

```
php .update-old/<old version>/revert.php
```

Führe es im App-Verzeichnis aus. Das Skript nutzt nur PHP-Bordmittel (kein Framework, kein Composer). Es verschiebt den alten Code zurück und entfernt, was die neue Version hinzugefügt hat. Danach kopiert es eine SQLite-Datenbank und die `.env` aus dem Backup zurück. Bei MariaDB gibt es den Befehl `mariadb <database> < <backup>/db.sql` aus, den du von Hand ausführst; leere die Datenbank vorher, denn der Dump löscht keine Tabellen, die eine neuere Version hinzugefügt hat. Zweimal ausführen schadet nicht. Danach startet die App wieder: Führe `php artisan ignis:update:recover` aus (schließt den State ab; nach einem `failed_rollback`: `--force`, siehe Abschnitt 1) und dann `php artisan up`.

Fehlt `revert.php` oder schlägt es fehl, stelle von Hand aus dem Backup-Verzeichnis wieder her:

1. Entpacke `code.zip` über das App-Verzeichnis. Lass `storage/`, `.env` und `.git` unangetastet; entferne Dateien, die die neue Version hinzugefügt hat.
2. Datenbank: SQLite: kopiere `db.sqlite` über die Datenbankdatei und lösche `<file>-wal`, `-shm` und `-journal`. MariaDB: lösche alle Tabellen, dann `mariadb <database> < db.sql`.
3. Kopiere `env` nach `.env`.
4. `php artisan optimize:clear`, dann `php artisan up`.

## 3. Release-Layout (`current` -> `releases/<version>`)

`.env`, `storage/` und eine SQLite-Datenbank müssen in `shared/` liegen und in jedes Release eingebunden sein. Eine Datenbankdatei innerhalb eines Release-Verzeichnisses wird vor jeder Änderung abgelehnt, weil der Wechsel sie zurücklassen würde. Um einen Wechsel von Hand rückgängig zu machen, setze `current` zurück: `ln -sfn releases/<old version> current`. Stelle danach die Datenbank wie oben aus dem Backup wieder her.

## Nach einem Update oder Rollback: PHP-FPM

Der Updater leert nur den Opcache seines eigenen CLI-Prozesses. Lade bei PHP-FPM den Dienst neu (`systemctl reload php8.4-fpm` oder das Äquivalent). Mit Herd reicht ein Neustart der Site.

## Dateien, die der Swap mitnimmt

Eine flache Installation behält diese Pfade innerhalb der Code-Verzeichnisse, wenn der Code getauscht wird: `public/storage`, `public/.well-known`, `public/.user.ini`, `public/web.config` und die SQLite-Datenbank (mit ihren `-wal`/`-shm`-Dateien). Liefert ein Release eine davon mit, gewinnt die eigene Kopie der Installation.

## Docker (Updater-Container)

Mit Docker läuft das Update im Dienst `updater` (`App\Update\DockerUpdateService`), nicht in der App. Gleiches State-Format, andere Orte:

| Was | Wo |
|---|---|
| Update-State | Volume `update-state`: `/var/lib/ignis-update/state/state.json` (die App mountet es schreibgeschützt) |
| Update-Log | gleiches Volume: `update-<UTC time>.log` (die Systemseite zeigt sein Ende) |
| Image, das der Updater nutzt | Volume `updater-private` (nur Updater): `/var/lib/ignis-updater/compose.image.yml`, ein Compose-Override mit dem `image:` von `app`, `worker`, `scheduler` (und `reverb`, wenn sein Container zu Beginn des Laufs existierte); außerdem `image_ref` im State |
| Backups | Volume `update-backups`: `<time>-<from>-to-<to>/db.sql` und `manifest.json` (`image` = vorheriges Image, getaggt als `ignis-update-previous:<version>`; `image_id`, und `image_pull`, wenn es aus einer Registry kam) |

Lies sie mit `docker compose exec updater cat /var/lib/ignis-update/state/state.json` (oder `ls`, `tail` auf das Log).

Der Updater führt `docker compose` mit einer minimalen Umgebung aus: Jede Variable, die die Compose-Datei braucht, muss in der `.env` neben `docker-compose.yml` stehen, nicht nur in der Shell, die den Stack gestartet hat.

**Unterbrochener Lauf** (Updater-Container beendet, Host neu gestartet): Der Updater stellt sich beim nächsten Start selbst wieder her (`php updater/updater.php recover` läuft, bevor er ausliefert): Ein Lauf, der seinen Health-Check bestanden hatte, wird als erledigt markiert, alles andere nach dem Wechsel wird zurückgerollt, und ein vom Updater eingeschalteter Wartungsmodus wird ausgeschaltet. Starte ihn mit `docker compose up -d updater`.

**Override abgelehnt (`docker_failed: override`, jede Aktion schlägt sofort fehl):** Der Updater nutzt seine Override-Datei nur, wenn sie genau dem entspricht, was er geschrieben hat, und das Image nennt, das der State festhält (`image_ref`). Ein Absturz genau zwischen dem Schreiben der Datei und dem Festhalten des Images (oder eine veränderte Datei) lässt beides auseinanderlaufen, und der Updater lehnt alle Compose-Befehle ab (fail-closed). Prüfe, welches Image laufen soll (`docker compose ps`, das Update-Log), entferne dann die Datei und lass den Updater neu anfangen: `docker compose exec updater rm /var/lib/ignis-updater/compose.image.yml`, `docker compose restart updater` (recover läuft beim Start). Ohne die Datei nutzt Compose `IMAGE_TAG` aus der `.env`: Setze es zuerst auf das Image, das laufen soll.

**Rollback nach `failed_rollback`:** Behebe die Ursache und nutze dann den Button **Reset** unter *Settings > System* (er läuft über den Updater-Container und bleibt im Wartungsmodus nutzbar, solange der Container `app` antwortet), oder auf dem Host im Verzeichnis mit `docker-compose.yml`:

```
docker compose exec updater php updater/updater.php rollback
```

Nach einer Wiederherstellung von Hand (unten) markierst du den fehlgeschlagenen Rollback als gelöst, damit Updates wieder entsperrt sind; schalte danach den Wartungsmodus aus:

```
docker compose exec updater php updater/updater.php resolve --yes
docker compose exec app php artisan up
```

Die `artisan`-Befehle `ignis:update:recover` und `ignis:update:rollback` funktionieren in Docker nicht (der App-Container liest den State nur); gehe immer über den Updater-Container.

**`rollback_unavailable`:** Das vorherige Image (Tag, ID und gespeicherter Digest) oder der Datenbank-Dump ist weg. Nichts wurde gestoppt oder wiederhergestellt; die aktuelle Version läuft weiter. Stelle nur mit einem Dump und einem Image von Hand wieder her, denen du vertraust.

**Rollback von Hand** nach `failed_rollback` (der Wartungsmodus bleibt an). Im Verzeichnis mit `docker-compose.yml`:

1. Schlage das Backup im State nach (`backup`) und sein vorheriges Image (`image` in seiner `manifest.json`):
   `docker compose exec updater cat <backup>/manifest.json`.
2. Stoppe die App: `docker compose stop app worker scheduler` (und `reverb`, wenn es läuft).
3. Datenbank: Lösche jede Tabelle und View (der Dump löscht nicht, was eine neuere Version hinzugefügt hat), dann lade den Dump:
   ```
   docker compose exec -T updater cat <backup>/db.sql > ignis-restore.sql
   docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" -N -e "SELECT CONCAT(IF(TABLE_TYPE = \"VIEW\", \"DROP VIEW IF EXISTS \", \"DROP TABLE IF EXISTS \"), \"\`\", TABLE_NAME, \"\`;\") FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()" "$MARIADB_DATABASE"' > ignis-drop.sql
   (echo 'SET FOREIGN_KEY_CHECKS=0;'; cat ignis-drop.sql) | docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE"'
   docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE"' < ignis-restore.sql
   ```
   Lösche `ignis-restore.sql` und `ignis-drop.sql` danach (die erste ist die ganze Datenbank).
4. Image: Setze `IMAGE_TAG=<previous image>` in der `.env`, dann `docker compose up -d --no-deps app worker scheduler` (plus `reverb`, wenn es läuft).
5. Sobald `app` läuft (`docker compose ps`; es muss noch nicht healthy sein):
   `docker compose exec app php artisan up`.
6. `docker compose exec updater php updater/updater.php resolve --yes` (der State verlässt `failed_rollback`).

**Manuelle Compose-Befehle nach einem Klick-Update.** Der Updater hält das neue Image in seiner eigenen Override-Datei, nicht in deiner `.env`. Ein einfaches `docker compose up -d` (zum Beispiel nach dem Bearbeiten der `.env`) startet das Image, das `IMAGE_TAG` nennt, also die alte Version gegen die bereits migrierte Datenbank. Setze nach jedem erfolgreichen Klick-Update `IMAGE_TAG` in der `.env` auf das Image, das die Systemseite zeigt ("Set IMAGE_TAG=..."; auch `image_ref` im State und `image override:` im Update-Log), bevor du einen solchen Befehl ausführst.

**Der Updater selbst** wird durch ein Update nicht ersetzt (er behält seinen eigenen Code), ebenso wenig `docker-compose.yml` und `.env`. Aktualisiere ihn, nachdem du den Repository-Checkout aktualisiert hast: `docker compose build updater && docker compose up -d
updater`. Ein Release, das Änderungen an Compose, `.env` oder Updater braucht, lässt sich nicht per Klick installieren; seine Release-Notes enthalten die Schritte zum Neubau.

# Betrieb: Warteschlange, Scheduler, Aufräumen, Sicherung, Überwachung

- **Zeitplan:** `routes/console.php`. Täglich `notifications:prune`, `queue:prune-failed --hours=720`, `model:prune`; wöchentlich `ignis:storage:cleanup`; stündlich/halbstündlich die Abrufe; jede Minute `ignis:heartbeat`. `ignis:update:check` läuft täglich, aber nur mit `updates.check_enabled`; `tests/Feature/StorageCleanupTest.php` prüft den Zeitplan.
- **`model:prune`** räumt jedes Modell mit `Prunable`; heute `ScheduledTaskRun` (Lauf-Verlauf, 30 Tage, mindestens 10 je Aufgabe). Neue `Prunable`-Modelle brauchen keinen weiteren Eintrag.
- **`ignis:storage:cleanup`** (`App\Console\Commands\StorageCleanupCommand`): Datenträger `documents` (`editor/{7 Ziffern}.pdf`, Temp-Dateien `editor/.*.tmp`, `legacy/XXXX-XXXX-XXXX.pdf` und die 7-stellige Altform `legacy/NNNNNNN.pdf`), `branding` (Logo) und `profile-pictures`. Gelöscht wird, was dem Namensmuster der selbst erzeugten Dateien entspricht, älter als 24 h ist und auf das keine Zeile verweist (`documents.pdf_path`, `legacy_documents.pdf_path`, Einstellung `system.logo`, `personnel.profile_picture`). Andere Dateien bleiben. Symlinks werden weder betreten noch gelöscht; Pfade kommen nie aus Eingaben. Ein neuer Dateispeicher muss in `stores()` eingetragen werden, sonst wird er nie aufgeräumt.
- **Nicht während eines Imports ausführen** (`ignis:import-legacy --uploads`): Dateien, die der Import gerade kopiert hat, sind zwar jünger als 24 h, ein wiederholter Lauf mit alten Quelldateien kann aber Zeilen und Dateien zeitlich trennen.
- **Fehlt die Verweisabfrage** (z. B. Datenbank nicht erreichbar), löscht der Befehl für diesen Datenträger nichts und endet mit Fehlercode.
- **Kein Löschen ohne Verweisprüfung:** die Verweise werden nach dem Auflisten gelesen, die 24-Stunden-Frist deckt Uploads ab, deren Zeile noch nicht geschrieben ist.
- **Updates und Releases:** Klick-Update, Backups (`storage/app/update/backups`, Docker: Volume `update-backups`), Protokolle `update-<Zeit>.log` und Wiederherstellung: `INSTALL.md` "Aktualisieren", `docs/dev/update-recovery.md`.
- **Vernetzung (Modul `federation`, Standard aus):** `federation:sync` alle 5 min (`withoutOverlapping`) stellt je fälligem Link einen `SyncLink`-Job in die Warteschlange (Container `worker`; 3 Versuche nach 1/5/15 min, unique je Link 10 min); `federation:prune` täglich 04:00 löscht fremde eNOTF-/Einsatz-Caches älter `federation.cache_days` (90), Lauf-Log älter 30 Tage und eine abgelaufene Einladung. Beides läuft nur bei aktivem Modul. Voraussetzungen: NTP auf beiden Servern (±300 s), öffentliches HTTPS, Proxy reicht `X-Ignis-*` durch. `FEDERATION_ALLOWED_HOSTS` (Komma-Liste; gesetzt = nur diese Hosts) und `FEDERATION_ALLOW_PRIVATE` (`true` = private Ziele erlaubt, nur Testnetze) in `.env`. **Fehlersuche:** *Einstellungen > Vernetzung* zeigt je Verbindung Status, letzten Abruf und die Läufe der letzten 24 h (Tabelle `federation_sync_runs`), immer nur als Fehlercode (`docs/dev/modules.md`, Abschnitt Vernetzung); ein endgültiger Fehlschlag schreibt eine Warnung `Federation sync failed` mit `link_id` und Code ins Log. Typisch: `clock_skew` (Uhr), `tls`/`unreachable` (Zertifikat, DNS, Firewall), `blocked_address` (Ziel privat), `redirect` (Proxy leitet um), `unauthorized` (Gegenstelle hat entkoppelt), `peer_v1` (Gegenstelle noch Altsystem), `http_403` (Gegenstelle stellt den Typ nicht bereit). Manuell: `php artisan federation:sync --link=ID`. **Achtung:** `cache:clear`/`optimize:clear` leert den Nonce-Speicher der Signaturprüfung; für bis zu 5 min (Zeitfenster ±300 s) ließen sich mitgeschnittene Peer-Anfragen dann erneut einspielen. Cache nur leeren, wenn nötig. Ohne PHP-Erweiterung `curl` sendet Vernetzung nicht (`unreachable`), weil das DNS-Pinning nur mit curl greift.
- **Reverb (Opt-in):** Dienst `reverb` (Compose-Profil `reverb`) oder Supervisor-Programm `php artisan reverb:start --host=127.0.0.1 --port=8081`; Schalter, Proxy und Schlüssel: `INSTALL.md`. Grenzen (`config/reverb.php`): ein Prozess, `max_connections` 1000 (ohne `ext-uv` etwa 1024 Deskriptoren), Nachricht höchstens 10 KB, `allowed_origins` = Host aus `APP_URL`, Client-Ereignisse abgewiesen, kein Redis-Scaling. Nach Updates `php artisan reverb:restart`; im Docker-Betrieb tauscht das Klick-Update `reverb` mit aus, wenn sein Container existiert (`compose ps -a -q reverb`; ab einem Updater-Container aus v1.0.0). Fehlersuche: `php artisan reverb:start --debug`; ohne laufenden Reverb liefert `/app/` 502 und die Seiten pollen weiter. Pulse-Fehler stehen als Warnung im Log (höchstens alle 5 min).
- **Antwort-Header (Docker, gemessen im Smoke-Stapel, `curl -sI /login`):** `X-Frame-Options: SAMEORIGIN` (genau einmal, von der App), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `Cache-Control: no-cache, private`, `Server: nginx` (ohne Version). Cookies `XSRF-TOKEN` und `laravel-session` (`HttpOnly`, `SameSite=Lax`; im Smoke ohne `Secure`, weil dort `SESSION_SECURE_COOKIE=false` gilt, im Betrieb Standard `true`). Es fehlen `Content-Security-Policy` und `Permissions-Policy` (nicht gesetzt). PHP im Image: `upload_max_filesize` und `post_max_size` je 100M, `exif` geladen. Der Smoke-Test gibt die Header bei jedem Lauf aus.

## Datensicherung und Wiederherstellung

`scripts/backup.sh [Zielordner] [--keep N]` und `scripts/restore.sh <Ordner>` (Docker, Bash; Bedienung und Cron-Beispiel in `INSTALL.md` "Datensicherung", Ablauf oben in `restore.sh`). Datenbank als App-Benutzer (`MARIADB_USER`/`MARIADB_PASSWORD` aus der Umgebung des db-Containers, nie in argv), Storage-Volume `<projekt>_storage` per `docker run … alpine:3.22@sha256:… tar` (Digest gepinnt), Prüfsummen `SHA256SUMS`. Dump und Volume-Archiv entstehen nacheinander, nicht atomar: eine Datei, die zwischen beiden Schritten hochgeladen wird, liegt dann ohne Zeile im Archiv (`ignis:storage:cleanup` räumt sie später auf). `--keep` nutzt GNU `head -n -N`: läuft unter Linux und Git Bash, nicht unter macOS.

`restore.sh` löscht alle Tabellen vor dem Einspielen; vorher eine aktuelle Sicherung ziehen. Bricht das Skript ab, meldet es den Zustand (Wartungsmodus an, Dienste gestoppt): Lauf wiederholen oder von Hand `docker compose up -d` und `php artisan up`. Ein weiteres Sicherheitsnetz hat das Skript nicht.

Geprüft im Wegwerf-Stapel (`-p ignis_restore`): Installation per CLI, API-Schlüssel und eine Datei im Storage angelegt; Sicherung dreimal mit `--keep 2` (zwei Ordner bleiben); `down -v`, neuer Stapel (neuer `app.key`, Datei weg); eine manipulierte Sicherung wird abgewiesen; Wiederherstellung: gleicher API-Schlüssel, `app.key` und Datei zurück, Eigentümer `www-data`, Storage beschreibbar, Wartungsmodus aus, `/healthz` `ok`, `/login` 200.

## Überwachung

- **Health:** `GET /healthz` (ohne Anmeldung, auch im Wartungsmodus) liefert JSON mit `status` `ok`, `degraded` (HTTP 200) oder `down` (HTTP 503) und den Prüfungen `db`, `queue` (offene und fehlgeschlagene Jobs), `storage` (freier Platz), `migrations`, `php_extensions`, `rewrite`. Ein externer Monitor (z. B. Uptime Kuma) prüft HTTP 200 **und** `"status":"ok"`; der Docker-Healthcheck von `app` prüft nur HTTP 200.
- **Täglich in der Woche nach der Umstellung, danach wöchentlich:** `/healthz`; *Einstellungen > System > Aufgaben* (fehlgeschlagene Jobs); `docker compose logs --since 24h app worker scheduler | grep -E 'ERROR|CRITICAL'`; EMD-Sync-Warnungen und 503-Antworten des EMD-Sync (Sperre); Vernetzungs-Läufe (*Einstellungen > Vernetzung*); Reverb-Warnungen; Datenbankgröße; Ergebnis des Backup-Laufs (Cron-Log, jüngster Ordner mit `SHA256SUMS`).
- **Docker-Status:** `docker compose ps` (alle `healthy` bzw. `running`; `worker` und `scheduler` haben eigene Healthchecks).

## Logs

- **Anwendung (Docker):** Image-Standard `LOG_STACK=daily,stderr`, `LOG_LEVEL=warning`: `storage/logs/laravel-<Datum>.log` im Storage-Volume (14 Tage, `LOG_DAILY_DAYS`) und zugleich `docker compose logs`. Die Docker-Logs rotieren (`x-logging` in `docker-compose.yml`: 10 MB × 5 je Container).
- **Updates:** `update-<UTC-Zeit>.log` im Volume `update-state` (die Systemseite zeigt das Ende), ohne Docker in `storage/logs`.
- **Ohne Docker:** `LOG_CHANNEL`/`LOG_STACK` aus `.env`; mit `daily` rotiert Laravel selbst.

## Worker, Scheduler und Einstellungen

- **Worker:** ein Container `worker` (`queue:work --tries=3`) für Discord-DMs, Webhooks und Vernetzungs-Abrufe. Steht er, meldet `/healthz` offene Jobs (`queue.pending`) und Discord-Nachrichten bleiben liegen. Ohne Docker nach jeder Code-Änderung `php artisan queue:restart`.
- **Scheduler:** Container `scheduler` (`schedule:work`) bzw. Cron `* * * * * php artisan schedule:run`; Pflicht (`INSTALL.md` "Scheduler ist Pflicht"). Lebenszeichen: `ignis:heartbeat` jede Minute.
- **Geänderte Einstellungen im laufenden Worker:** `Settings` hält die Einstellungen in `Cache::memo()`, einer `scoped`-Bindung. Der Queue-Worker vergisst `scoped`-Instanzen vor jedem Job (`Illuminate\Queue\QueueServiceProvider`, `forgetScopedInstances`), `schedule:work` startet jede Aufgabe als eigenen Prozess: eine in der Oberfläche geänderte Einstellung (Bot-Token, Webhook, Schalter) gilt ab dem nächsten Job ohne Neustart (aus dem Framework-Code gelesen; die Probe mit echtem Discord steht in der Testmatrix). Werte aus `.env` sind dagegen beim Start zwischengespeichert (`optimize`): nach Änderungen an `.env` `docker compose up -d` (erzeugt die Container neu).

## Drosselung (Rate Limits)

Abgeleitet aus `php artisan route:list --json` (alle Module eingeschaltet). `throttle:N,1,name` = N Anfragen je Minute je angemeldetem Konto, sonst je IP; benannte Limiter ohne Zahl stehen in den Service-Providern (`RateLimiter::for`, Schlüssel in der Spalte Grenze). Überschreitung: 429 mit `Retry-After`. Die IP stimmt hinter einem Proxy nur mit passendem `TRUSTED_PROXIES`.

| Grenze/min | Limiter | Routen |
|---|---|---|
| 5 | `api-key-write` | `POST settings/system/api-key` |
| 5 je IP | `enotf-hospital-login` | `POST enotf/schnittstelle/login` |
| 6 | `update-github` | `POST settings/system/update`, `…/update/check` |
| 6 gesamt | `enotf-billing` | `POST api/enotf/billing` |
| 10 | `federation-pair`, `install`, `enotf-login`, `enotf-release`, `enotf-clinic-code-create`, `enotf-patient-sync` | `POST api/federation/pair`, `POST install`, `POST enotf/login`, `POST enotf/p/{p}/release`, `…/clinic-code`, `…/patient-sync` |
| 10 je IP | `enotf-clinic-code` | `POST enotf/schnittstelle/klinikcode` |
| 12 je Fahrzeug | `firetab-status` | `POST firetab/status` |
| 20 | `discord-login` | `auth/discord`, `auth/discord/callback`, `auth/callback`, `invite` |
| 20 | `notifications-poll`, `document-issue`, `enotf-share`, `forms-submit`, `picture` | `GET api/notifications/poll`, `POST documents/{d}/issue`, `POST enotf/p/{p}/share`, `POST forms/new/{type}`, Profilbild hochladen/löschen |
| 30 | `tablet-login` | `GET auth/tablet` |
| 30 je Konto | `mail-drafts` | Mail-Entwürfe, Anhänge, Senden, Signatur (6 Routen) |
| 30 | `calendar-ical`, `announcement-dismiss`, `legacy-document-write`, `api-key-read`, `system-config-write`, `settings-write` (19 Routen: Vernetzung, Discord, Jobs, Logo, Module, Update-Rollback), `dashboard-write`, `document-template-write`, `vehicle-write`, `vehicle-defect-write`, `invite`, `profile-write`, `kb-images-upload` | Verwaltungs- und Schreibrouten |
| 30 | `enotf-create`, `enotf-write`, `enotf-crew`, `enotf-admin`, `enotf-prereg`, `enotf-qm`, `enotf-activity`, `enotf-status`, `enotf-share-list`, `enotf-share-answer`, `enotf-portal-poll` | eNOTF-Tablet und Klinikportal (ohne Autosave) |
| 60 je Konto, sonst IP | `broadcast-auth` | `broadcasting/auth` |
| 60 je IP, nur Gäste | `kb-read` | `GET lexicon`, `GET lexicon/{entry}` (angemeldet: unbegrenzt) |
| 60 je Konto, sonst IP | `firetab-write` | FireTab-Schreibrouten (21: Einsatz, Karte, Bericht, Lagemeldungen, Fahrzeuge, Einsatztagebuch, QM) |
| 60 | `asu-sync`, `notifications-write`, `notifications-popover`, `service-number`, `global-search`, `calendar-write`, `forms-write`, `kb-write`, `logbook-write`, `manv-write`, `enotf-changes`, `enotf-portal`, `enotf-settings`, `poi-settings`, `loadout-write`, `symbol-template-write`, `vehicle-registry-write`, `vehicle-registry-poll` | übrige Schreib- und Abfrageroute |
| 120 gesamt | `emd-sync`, `character-identify` | `POST api/emd/sync`, `POST api/character/identify` (ein Zähler für alle Aufrufer, gedacht für einen FiveM-Server) |
| 120 je Verbindung | `federation-peer` | `GET api/federation/{handshake,personnel,enotf,fire-incidents}` |
| 120 je Konto, sonst IP | `firetab-asu` | Atemschutzüberwachung (7 Routen, mit `asu/state`) |
| 120 | `enotf-save` | eNOTF-Autosave `PATCH enotf/p/{p}`, Adressen, Medikamente, Vitalwerte (8 Routen) |
| 120 | `cards`, `vehicle-card`, `branding`, `calendar-feed`, `document-save`, `enotf-search`, `firetab-search`, `kb-lookup`, `manv-personnel`, `manv-patient`, `manv-resource`, `legacy-pdf` | Karten, Suchen, Kalender-Feed, MANV, PDFs |
| 240 | `kb-images` | `GET storage/kb-images/{file}` |

Alle übrigen Routen haben keine eigene Drosselung (nur Anmeldung bzw. API-Schlüssel).

# Installation

Es gibt zwei Wege: mit Docker (empfohlen, in etwa zehn Minuten erledigt) oder klassisch als Laravel-Anwendung auf deinem eigenen Webserver. Such dir einen aus, beide sind in sich vollständig. Danach kommt "Einrichtung und Betrieb" als Nachschlagewerk. Lies dort, wenn du es brauchst.

**Inhalt**

1. [Installation mit Docker](#installation-mit-docker)
2. [Installation mit Laravel (ohne Docker)](#installation-mit-laravel-ohne-docker)
3. [Einrichtung und Betrieb](#einrichtung-und-betrieb)
   - [Discord-Anwendung](#discord-anwendung), [Installation ohne Browser](#installation-ohne-browser), [Nachkontrolle mit preflight](#nachkontrolle-mit-preflight)
   - [Reverse-Proxy und HTTPS](#reverse-proxy-und-https), [Echtzeit](#echtzeit-optional), [Vernetzung](#vernetzung-optional)
   - [Aktualisieren](#aktualisieren), [Wartungsmodus](#wartungsmodus), [Worker und Scheduler](#worker-und-scheduler), [Datensicherung](#datensicherung)
   - [Hinweise zum Docker-Stapel](#hinweise), [Fehlersuche](#fehlersuche)
   - [Anmeldung und Konten](#anmeldung-und-konten), [Personal](#personal), [Dokumente](#dokumente), [Fahrzeuge](#fahrzeuge)
   - [Startseite, Schnellzugriffe, Benachrichtigungen und Suche](#startseite-schnellzugriffe-benachrichtigungen-und-suche)
   - [Systemeinstellungen und Module](#systemeinstellungen-und-module)
   - [API-Schlüssel für ef_bridge](#api-schlüssel-für-ef_bridge), [Tablet-Login](#tablet-login-ef_bridge), [Import aus dem Altsystem](#import-aus-dem-altsystem)

## Installation mit Docker

### Voraussetzungen

- Docker mit Compose v2.
- Ein Discord-Konto, mit dem du eine Anwendung im Developer Portal anlegen kannst.
- Für den echten Betrieb ein Reverse-Proxy mit HTTPS davor (siehe [Reverse-Proxy und HTTPS](#reverse-proxy-und-https)). Zum Ausprobieren geht es auch ohne.

### Schritte

**1. Code holen.** Ein fertiges Image gibt es noch nicht, Docker baut es lokal.

```
git clone <Repository-URL> ignis
cd ignis
```

`<Repository-URL>` ist die Adresse, unter der der Betreiber den Code bereitstellt. Das Repository `QuitScope/ignis-laravel` ist derzeit nicht öffentlich. Mit Zugriff: `git@github.com:QuitScope/ignis-laravel.git`. Nimm ein eigenes, sauberes Verzeichnis und keinen Entwicklungs-Checkout, denn dessen `.env` würde Compose sonst mitlesen.

**2. Discord-Anwendung anlegen.** Wie das geht, steht unter [Discord-Anwendung](#discord-anwendung). Du brauchst danach Client-ID, Client-Secret und deine eigene Discord-ID.

**3. `.env` anlegen und vier Werte setzen.**

```
cp docker/.env.example .env
```

| Variable | Wert |
| --- | --- |
| `DB_PASSWORD` | ein langes, zufälliges Passwort |
| `APP_URL` | die Adresse, unter der Ignis erreichbar sein wird, z. B. `https://ignis.example.org` (ohne `/` am Ende) |
| `IGNIS_INSTALL_TOKEN` | `openssl rand -hex 24`. Ohne Token kann jeder, der `/install` zuerst erreicht, deine Instanz einrichten. |
| `UPDATER_SECRET` | `openssl rand -hex 32` (siehe [Aktualisieren](#aktualisieren)) |

Optional sind `APP_PORT` (Standard 8080) und `IGNIS_VERSION`. Testest du erst mal ohne HTTPS direkt über `http://127.0.0.1:…`? Dann zusätzlich `SESSION_SECURE_COOKIE=false`, sonst bleibst du nicht angemeldet. Im echten Betrieb hinter HTTPS lässt du das weg.

**4. Starten.**

```
docker compose up -d --build
```

Der erste Start dauert bis zu zwei Minuten. Fertig ist er, wenn `docker compose ps` den Zustand `healthy` zeigt.

**5. Installer öffnen.** Im Browser `http://127.0.0.1:8080/install` aufrufen (oder deinen `APP_PORT`, oder über den Proxy). Bis die Installation fertig ist, leitet jede Seite dorthin um. Gib den Token aus `.env` ein und folge dem Assistenten: Organisation, Discord-Anwendung, erster Administrator, Module. Danach melden sich alle per Discord an. Lieber ohne Browser? Siehe [Installation ohne Browser](#installation-ohne-browser).

**6. Nachkontrolle.** `IGNIS_INSTALL_TOKEN` in `.env` leeren, dann:

```
docker compose up -d
docker compose exec app php artisan ignis:preflight
```

Was die Prüfung meldet, steht unter [Nachkontrolle mit preflight](#nachkontrolle-mit-preflight). Für den echten Betrieb fehlen dann noch der [Reverse-Proxy mit HTTPS](#reverse-proxy-und-https) und eine [Datensicherung](#datensicherung).

### Gut zu wissen

- Beim ersten Start erzeugt Ignis einen `APP_KEY` und legt ihn im Storage-Volume ab (`storage/app.key`). Die Migrationen laufen automatisch.
- Der Container lauscht nur auf `127.0.0.1:8080` (bzw. `APP_PORT`). Von außen kommt man nur über einen Reverse-Proxy dran.
- Die `.env` neben `docker-compose.yml` heißt genauso wie die `.env` der Laravel-Entwicklung. In einem Entwicklungs-Checkout nimmt Compose also deren Werte.
- Der Stapel heißt in Compose `ignis` (Container `ignis-app-1` usw.). Für einen zweiten Stapel auf demselben Host setzt du `COMPOSE_PROJECT_NAME=<name>` in `.env`.
- Zum Betrieb gehören die Container `worker` und `scheduler`. Beide müssen `healthy` sein (siehe [Worker und Scheduler](#worker-und-scheduler)).

## Installation mit Laravel (ohne Docker)

### Voraussetzungen

- PHP 8.3 oder neuer mit `fileinfo`, `gd`, `intl`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `xml` und `zip` (genau die Liste der Health-Prüfung).
- MariaDB **10.11 oder neuer**. Ältere Server haben `explicit_defaults_for_timestamp=OFF`, dann bekommt die erste nicht-nullbare `TIMESTAMP`-Spalte einer Tabelle stillschweigend `ON UPDATE CURRENT_TIMESTAMP`.
- Composer und Node.js (getestet mit 24).
- Ein Webserver (z. B. nginx) mit HTTPS, dazu etwas, das Prozesse dauerhaft laufen lässt (systemd oder Supervisor), und Cron.
- Ein Discord-Konto für die Discord-Anwendung.

### Schritte

**1. Code holen und bauen.** Zur `<Repository-URL>` siehe [Installation mit Docker](#installation-mit-docker).

```
git clone <Repository-URL> ignis && cd ignis
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

**2. Discord-Anwendung anlegen.** Siehe [Discord-Anwendung](#discord-anwendung).

**3. `.env` anlegen.**

```
cp .env.example .env
php artisan key:generate
```

Dann in `.env` setzen:

| Variable | Wert |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | deine öffentliche Adresse, z. B. `https://ignis.example.org` |
| `DB_*` | Zugang zur Datenbank. Die Datenbank legst du vorher an. |
| `IGNIS_INSTALL_TOKEN` | `openssl rand -hex 24` |
| `SESSION_SECURE_COOKIE` | `true` hinter HTTPS. Ohne Docker ist das nicht voreingestellt. |
| `TRUSTED_PROXIES` | IP deines Proxys, siehe [Reverse-Proxy und HTTPS](#reverse-proxy-und-https) |

**4. Datenbank anlegen und Konfiguration cachen.**

```
php artisan migrate --force
php artisan optimize
```

Die Konfiguration ist danach gecacht. Änderst du später `.env`, rufst du `php artisan optimize` noch einmal auf.

**5. Webserver und Dienste einrichten.**

- Den Webserver auf `public/` zeigen lassen. Alle Anfragen gehen an `public/index.php`, auch `*.php`-Pfade (Vorlage: `docker/nginx/legacy-php.conf`).
- Queue-Worker dauerhaft laufen lassen: `php artisan queue:work --tries=3` (z. B. als systemd-Dienst oder mit Supervisor).
- Scheduler per Cron: `* * * * * cd /pfad/zu/ignis && php artisan schedule:run >> /dev/null 2>&1`.
- HSTS und die Umleitung von http auf https macht dein Webserver oder Proxy.

**6. Installer öffnen.** `{APP_URL}/install` im Browser aufrufen, den Token eingeben und dem Assistenten folgen. Oder ohne Browser, siehe [Installation ohne Browser](#installation-ohne-browser).

**7. Nachkontrolle.** `IGNIS_INSTALL_TOKEN` in `.env` leeren, dann:

```
php artisan optimize
php artisan ignis:preflight
```

Sicherung nicht vergessen: Datenbank und `.env` (mit `APP_KEY`) gehören immer zusammen (siehe [Datensicherung](#datensicherung)).

### Variante: aus einem Release-Archiv

Willst du später per Klick aktualisieren, installierst du aus einem Release-Archiv statt aus Git. Das geht nur, wenn der Betreiber vor dem Bau den öffentlichen Schlüssel in `resources/update/public.key` eingetragen hat (`PLACEHOLDER` ersetzt). Sonst kann eine solche Installation nie per Klick aktualisieren.

```
unzip ignis-<version>.zip && cd ignis-<version>   # enthält vendor/, public/build, VERSION und .env.example; kein composer/npm nötig
cp .env.example .env
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache        # für den PHP-Benutzer des Webservers (chown -R www-data: storage bootstrap/cache)
php artisan key:generate
```

In `.env` setzt du dieselben Werte wie oben und zusätzlich `IGNIS_RELEASE_REPO`. Weiter geht es mit Schritt 4. Der Ordner muss dem PHP-Benutzer gehören oder für ihn beschreibbar sein, sonst meldet die Systemseite "nicht beschreibbar". Klick-Updates tauschen nur die Code-Ordner aus und lassen `.env`, `storage/` und `.env.example` in Ruhe.

## Einrichtung und Betrieb

Die Befehle stehen hier in der Docker-Form. Ohne Docker lässt du `docker compose exec app` weg und rufst `php artisan …` im Anwendungsordner auf. Wo sich mehr unterscheidet, steht es dabei.

### Discord-Anwendung

Im [Discord Developer Portal](https://discord.com/developers/applications):

1. *New Application*, Namen vergeben, anlegen.
2. Links auf *OAuth2*. Client-ID und Client-Secret kopieren. Beides fragt der Installer ab.
3. Unter *Redirects* diese Adresse eintragen und speichern: `{APP_URL}/auth/discord/callback`, also zum Beispiel `https://ignis.example.org/auth/discord/callback`.

Für den ersten Admin brauchst du noch deine eigene Discord-ID: In Discord den Entwicklermodus einschalten, dann Rechtsklick auf dein Profil und ID kopieren.

**Anwendung aus dem Altsystem:** Nutzt du sie weiter, kannst du sie lassen, wie sie ist. Dort steht `{APP_URL}/auth/callback.php`, und das funktioniert: Das Suffix `.php` wird entfernt, `/auth/callback` ist ein Alias. Der Import setzt dafür `discord.redirect_path` auf `/auth/callback.php`, damit Ignis genau diese URI an Discord schickt. Trägst du später im Portal die kanonische URI ein, stellst du die Einstellung auf `/auth/discord/callback` zurück (siehe `docs/dev/import.md`). Die URI, die Ignis sendet, und die im Portal müssen übereinstimmen, sonst lehnt Discord die Anmeldung ab.

### Installation ohne Browser

```
docker compose exec app php artisan ignis:install --org="Feuerwehr Beispiel" \
  --discord-client-id=... --discord-client-secret=... --admin-discord-id=... \
  --modules=... --locale=de --install-token=...
```

`--modules` wiederholst du für jedes Modul, das an sein soll, mit dem Ordnernamen unter `modules/`, z. B. `--modules=FireTab --modules=Enotf`. Was du nicht nennst, bleibt aus. Du kannst es später unter *Einstellungen > System > Module* einschalten.

### Nachkontrolle mit preflight

`ignis:preflight` prüft nur lokal Konfiguration und Laufzeit: `APP_ENV`/`APP_DEBUG`, `https` in `APP_URL`, Sitzungscookie, Log-Stufe, Install-Token, `LEGACY_DB_PASSWORD`, Ausnahmen der Vernetzung, Erreichbarkeit von Reverb, Health, fehlgeschlagene Jobs, Lebenszeichen des Schedulers, ausstehende Migrationen, API-Schlüssel und Update-Schlüssel. Je Punkt gibt es `ok`, `warn` oder `fail`. Bei `fail` endet der Befehl mit Exit-Code 1. Für Skripte gibt es `--format=json`. Ein http-Test ohne Proxy meldet erwartungsgemäß `warn` für `app_url` und `session_secure`.

### Reverse-Proxy und HTTPS

Der Proxy (nginx, Caddy, Traefik usw.) beendet TLS und leitet auf `127.0.0.1:8080` weiter. Dabei gilt:

- Er reicht `Host` und `X-Forwarded-Proto` weiter. `APP_URL` beginnt mit `https://`.
- Die Anwendung muss dem Proxy vertrauen (`TRUSTED_PROXIES`), sonst nutzen Links, Assets und der Discord-Redirect kein `https`. Mit Docker ist der Standard `*`. Das ist sicher, solange der App-Port nur an `127.0.0.1` hängt und nur dein lokaler Proxy ihn erreicht. Machst du den Port öffentlich, setz `TRUSTED_PROXIES` auf die IP des Proxys.
- Ohne Docker ist `TRUSTED_PROXIES` leer, es wird also keinem Proxy vertraut. Dann setzt du es selbst in `.env`: kommagetrennte IPs, oder `*` für einen Proxy, den du selbst kontrollierst.
- Stimmen `TRUSTED_PROXIES` und `APP_URL=https://...`, stimmt auch die Redirect-URI `{APP_URL}/auth/discord/callback`.
- Der Proxy setzt **keinen** eigenen `X-Frame-Options`- oder CSP-Header. Die Anwendung setzt `X-Frame-Options: SAMEORIGIN` selbst und lässt ihn für das FiveM-Tablet (CitizenFX-Browser) weg, das eingebettet werden muss.
- `*.php`-Pfade müssen bei `index.php` landen, sonst funktioniert `ef_bridge` nicht. Im Docker-Image erledigt das `docker/nginx/legacy-php.conf` (z. B. `/api/emd/sync.php`, `/auth/callback.php`). Die Anwendung entfernt das Suffix und leitet alte deutsche Pfade um. Ohne Docker nimmst du diese Datei als Vorlage für deinen Webserver.

**Anfragebegrenzung:** Die Limits für Anmeldung, Einladung, Tablet-Token und falsche API-Schlüssel gelten je Client-IP. Stimmt `TRUSTED_PROXIES` nicht, sieht die Anwendung für alle Besucher nur die IP des Proxys. Dann teilen sich alle ein Limit, und die Logs nennen die falsche IP. Prüf nach der Einrichtung, dass in den Logs die echten Client-IPs stehen.

**Zugriffsprotokolle:** Der Tablet-Token (`/auth/tablet?token=...`) und ein per Query übergebener API-Schlüssel (`?api_key=...`) landen in den Zugriffsprotokollen von Webserver und Proxy. Der Token gilt nur 60 Sekunden und nur einmal, der API-Schlüssel aber dauerhaft. Darum sollten `ef_bridge` und eigene Aufrufe den Schlüssel im Header `X-API-Key` senden. Gib Zugriffsprotokolle nicht weiter.

### Echtzeit (optional)

Ohne Echtzeit fragen Tablet- und Verwaltungsseiten regelmäßig nach (Polling). Mit Echtzeit (Laravel Reverb) meldet der Server Änderungen sofort, die Seiten laden dann nach und fragen nur noch selten nach. Über den WebSocket gehen nur Thema und ID, nie Inhalte. Fällt Reverb aus, pollen die Seiten einfach wieder im alten Takt.

**Einschalten mit Docker:** beide Zeilen in `.env` setzen, dann `docker compose up -d`.

```
BROADCAST_CONNECTION=reverb
COMPOSE_PROFILES=reverb
```

Der Dienst `reverb` hat keinen eigenen Port. Der nginx im Container `app` leitet `/app` an ihn weiter (gleicher Ursprung). Fehlen `REVERB_APP_ID`, `REVERB_APP_KEY` und `REVERB_APP_SECRET` in `.env`, erzeugt der erste Start sie in `storage/reverb.keys`. Das Profil `reverb` nur zusammen mit `BROADCAST_CONNECTION=reverb` aktivieren.

**Updates und Reverb:** Das Klick-Update tauscht `reverb` mit aus, sobald sein Container existiert. Schaltest du Reverb erst später ein, bringt `docker compose up -d` ihn auf den Stand von `IMAGE_TAG`. Das gilt ab einem Updater aus v1.0.0. Einen älteren Updater baust du erst neu (`docker compose build updater && docker compose up -d updater`). Bis dahin nach jedem Klick-Update einmal `docker compose up -d`.

**Ausschalten:** beide Zeilen entfernen, dann `docker compose up -d --remove-orphans` und `docker compose rm -sf reverb`. Der letzte Befehl ist wichtig: Solange der angehaltene Container existiert, tauscht das Klick-Update ihn weiter mit aus und startet ihn dabei wieder.

**Proxy:** Er muss das WebSocket-Upgrade auf `/app` durchreichen. Caddy macht das von selbst, Traefik auch (Timeouts nicht unter 120 s). nginx braucht:

```
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 120s;
}
```

Die Anwendung setzt keine CSP. Setzt dein Proxy eine, muss sie `connect-src 'self' wss://<host>` enthalten.

**Ohne Docker:** in `.env` `BROADCAST_CONNECTION=reverb`, die drei `REVERB_APP_*` (je `openssl rand -hex 16`), `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8081` und `REVERB_SERVER_HOST=127.0.0.1` setzen, dann `php artisan optimize`. Den Server lässt du dauerhaft laufen, zum Beispiel mit Supervisor:

```
[program:ignis-reverb]
command=php /pfad/zu/ignis/artisan reverb:start --host=127.0.0.1 --port=8081
user=www-data
autorestart=true
stopwaitsecs=10
minfds=4096
```

Der Webserver leitet `/app/` mit Upgrade-Headern an `127.0.0.1:8081` weiter. Vorlage: `docker/nginx/reverb.conf`, nur mit Ziel `127.0.0.1:8081` statt `reverb:8081`. Nach jedem Update `php artisan reverb:restart` (Supervisor startet ihn neu). Zur Fehlersuche: `php artisan reverb:start --debug`.

### Vernetzung (optional)

Das Modul **Vernetzung** koppelt mehrere Ignis-Instanzen, nur lesend: Personal, freigegebene eNOTF-Protokolle und Einsätze. Weil es fremde Server anfragt, ist es **standardmäßig aus**. Einschalten unter *Einstellungen > System > Module*, dann einrichten unter *Einstellungen > Vernetzung*.

- **HTTPS mit öffentlichem Zertifikat** auf beiden Seiten. Die Zertifikatsprüfung ist an, `http` und Umleitungen gehen nicht. `APP_URL` muss die öffentliche `https://`-Adresse sein, sie steht im Einladungs-Token.
- **Der Proxy reicht die Kopfzeilen `X-Ignis-*` unverändert durch** und schreibt Pfad und Query nicht um, denn sie sind signiert. Leitet der Proxy `/api/federation/*` um, bricht die Kopplung (`redirect`).
- **Uhrzeit:** Beide Server per NTP synchron halten. Ab 5 Minuten Abweichung kommt `clock_skew`.
- **Worker und Scheduler** müssen laufen. Der Abruf läuft als Job alle 5 Minuten, "Jetzt synchronisieren" stellt auch nur einen Job ein.
- **Ziele:** Private, interne und reservierte Adressen sind gesperrt. Für Testnetze gibt es `FEDERATION_ALLOWED_HOSTS=a.example,b.example` (dann gehen **nur** diese Hosts, auch mit anderen Ports als 443) und `FEDERATION_ALLOW_PRIVATE=true` (erlaubt private Adressen, aber nie `http` oder Umleitungen). Im Betrieb lässt du beide leer bzw. auf `false`.
- **Altsystem:** Alte Instanzen sprechen das alte, unsignierte Protokoll und werden abgewiesen. Beide Seiten müssen migrieren. Der Import übernimmt die Verbindungen samt verschlüsselter Schlüssel, siehe `docs/dev/import.md`.

### Aktualisieren

Du kannst per Klick in der App aktualisieren (mit und ohne Docker) oder von Hand. **Leg vorher immer ein Backup an** (siehe [Datensicherung](#datensicherung)).

**Was dabei nach außen geht.** Der Update-Klick und "Nach Updates suchen" fragen einmalig `api.github.com` und `github.com` ab (Release, Manifest, Archiv). GitHub leitet Downloads auf `objects.githubusercontent.com` und `release-assets.githubusercontent.com` um, auch diese Hosts werden also kontaktiert. Die tägliche Prüfung läuft nur, wenn *Datenschutz > Versionsprüfung* (`updates.check_enabled`) an ist, Standard ist aus. Daten über deine Installation werden nicht gesendet. Jedes Release ist mit dem Schlüssel des Betreibers signiert (Ed25519), der öffentliche Schlüssel liegt im Code (`resources/update/public.key`). Ohne gültige Signatur und passende SHA-256-Summe wird nichts installiert.

#### Update per Klick ohne Docker

Unter *Einstellungen > System* gibt es "Jetzt prüfen" und "Update starten". Fehlt eine Voraussetzung, nennt die Seite den Grund. Voraussetzungen:

- Die Installation kommt aus einem **Release-Archiv** (Datei `VERSION` im Anwendungsverzeichnis, siehe [Variante: aus einem Release-Archiv](#variante-aus-einem-release-archiv)). Ein Git-Checkout (`.git`) oder `dev` geht nicht.
- `IGNIS_RELEASE_REPO` in `.env` nennt das Repository der signierten Releases (`besitzer/name`).
- Der PHP-Benutzer darf im Anwendungsverzeichnis schreiben, anlegen und umbenennen, weil die Code-Ordner ausgetauscht werden.
- Mit MariaDB müssen `mariadb-dump` (oder `mysqldump`) und der Client `mariadb` auf dem Server liegen. Bei SQLite wird die Datei kopiert.
- PHP-Erweiterung `zip`.

So läuft es ab: Voraussetzungen prüfen, Archiv laden und prüfen (Signatur, SHA-256, Inhalt), Wartungsmodus an, Backup von Datenbank, Code und `.env` nach `storage/app/update/backups`, optional ein Probelauf der Migrationen auf einer Kopie, Austausch der Code-Ordner (`app`, `vendor`, `public`, `modules` usw.), Migrationen, Gesundheitsprüfung, Wartungsmodus aus. `.env` und `storage/` bleiben. Der alte Code liegt bis zum Abschluss in `.update-old/<Version>/`.

Geht ein Schritt schief, rollt das Update automatisch zurück (Code und Datenbank). Das Protokoll liegt in `storage/logs/update-<Zeit>.log`. Bleibt die Installation im Zustand `failed_rollback` hängen, bleibt der Wartungsmodus an. Dann versucht es der Knopf "Zurücksetzen" auf der Systemseite noch mal. Hilft das nicht: [docs/dev/update-recovery.md](docs/dev/update-recovery.md).

**Von Hand ohne Docker** (Git-Checkout): `git pull`, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force` und `php artisan optimize`. Danach Queue-Worker neu starten (`php artisan queue:restart`), damit er den neuen Code lädt.

#### Update mit Docker

**Von Hand per Image:** In `.env` den gewünschten Stand als `IMAGE_TAG` setzen (Standard ist das lokal gebaute `ignis:local`), dann:

```
docker compose pull && docker compose up -d
```

Baust du weiter lokal: `git pull` und `docker compose up -d --build`. Neue Migrationen laufen beim Start von `app` automatisch (`AUTORUN_ENABLED`). `worker` und `scheduler` starten mit neu und laden so den neuen Code.

**Per Klick über den Dienst `updater`:** Der Updater ist ein eigener Container. Nur er hat Zugriff auf den Docker-Socket, er hat keinen veröffentlichten Port und ist nur über das interne Netz `update` von `app` aus erreichbar. In `.env` brauchst du:

- `UPDATER_SECRET` (`openssl rand -hex 32`, Pflicht),
- `IGNIS_RELEASE_REPO` (Repository der signierten Releases),
- bei lokalem Bau `IGNIS_VERSION` mit der Version des Checkouts. Sie landet als `VERSION` im Image. Mit `dev` gibt es kein Klick-Update.

Der Updater prüft jedes Release selbst (Signatur, Version) und startet nur das Image, das im signierten Manifest steht (`image@image_digest`). Vorher sichert er die Datenbank (Volume `update-backups`). Bei einem Fehler geht er auf das vorherige Image und die Sicherung zurück.

Der Probelauf der Migrationen braucht Rechte auf die Datenbank `ignis_trial`. Das ist optional und einmalig als root: `GRANT ALL ON ignis_trial.* TO 'ignis'@'%'`. Ohne diese Rechte bietet die Systemseite "ohne Probelauf fortfahren" an.

**Wichtig nach jedem Klick-Update:** Das erste Klick-Update stellt `app`, `worker` und `scheduler` vom lokalen Bau auf das veröffentlichte Image um. Der Updater merkt sich das in einer eigenen Override-Datei, nicht in `.env`. **Setz deshalb nach jedem erfolgreichen Klick-Update `IMAGE_TAG` in `.env` auf den Wert, den die Systemseite nennt** ("IMAGE_TAG=… in .env setzen"). Sonst startet ein späteres `docker compose up -d` wieder die alte Version gegen die schon migrierte Datenbank.

Alle Variablen, die `docker-compose.yml` braucht, gehören in die `.env` neben der Datei. Der Updater ruft Compose ohne die Umgebung deiner Shell auf. Nach einem `git pull` baust du den Updater selbst neu: `docker compose build updater && docker compose up -d updater`. Im Fehlerfall: `docs/dev/update-recovery.md` (Abschnitt "Docker").

### Wartungsmodus

```
docker compose exec app php artisan down --retry=60
docker compose exec app php artisan up
```

Ohne Docker: `php artisan down --retry=60` und `php artisan up`.

Nach `down` sehen alle Besucher die Wartungsseite (503), bis du `up` aufrufst. Das ist gut für Wiederherstellungen und größere Eingriffe. Für ein normales Update brauchst du es nicht.

### Worker und Scheduler

Zwei Dienste gehören zum Betrieb:

- `worker` (`queue:work`) erledigt Hintergrundaufgaben, zum Beispiel Discord-Nachrichten.
- `scheduler` (`schedule:work`) startet die geplanten Aufgaben.

Mit Docker sind das eigene Container, die `healthy` sein müssen (`docker compose ps`). Ohne Docker sind es der Queue-Worker und der Cron-Eintrag aus [Installation mit Laravel](#installation-mit-laravel-ohne-docker).

*Einstellungen > System > Jobs* (`/settings/system/jobs`) zeigt die geplanten Aufgaben mit letztem Lauf, die Warteschlange und fehlgeschlagene Jobs. Dort kannst du Jobs erneut versuchen, löschen oder ausgewählte Aufgaben sofort starten. Die Systemübersicht zeigt das Lebenszeichen des Schedulers (`ignis:heartbeat`, jede Minute). Fehlt es, läuft der Scheduler nicht.

Was geplant ist, steht unter [Scheduler ist Pflicht](#scheduler-ist-pflicht). `ignis:storage:cleanup` (wöchentlich) löscht verwaiste Dateien: Dokument-PDFs, Logos, Profilbilder und Temp-Dateien, auf die keine Datenbankzeile mehr zeigt und die älter als 24 Stunden sind. Vorher anschauen, ohne etwas zu löschen:

```
docker compose exec app php artisan ignis:storage:cleanup --dry-run
```

### Datensicherung

**Sichere Datenbank und Storage-Volume immer zusammen.** Im Volume liegt `storage/app.key`. Damit sind gespeicherte Geheimnisse verschlüsselt: Discord-Client-Secret, API-Schlüssel, Bot-Token, Webhook-Adressen. Ist das Volume weg, die Datenbank aber nicht, sind diese Einstellungen unlesbar. Einen gesicherten Schlüssel stellst du mit `APP_KEY=` in `.env` wieder her.

Im Volume liegen außerdem die **Profilbilder** (`storage/app/private/profile-pictures`) und die **Dokument-PDFs** (`storage/app/private/documents`, mit `editor/` für ausgestellte und `legacy/` für importierte Altdokumente). Ohne Sicherung fehlen sie nach einer Wiederherstellung, und die Datenbank zeigt ins Leere. Ein ausgestelltes Dokument lässt sich nur aus dieser Datei wieder ausgeben, die PDF-Erzeugung lässt sich nicht wiederholen.

**Mit Docker** (im Verzeichnis mit `docker-compose.yml`, in Bash, unter Windows Git Bash):

```
scripts/backup.sh [Zielordner] [--keep N]
scripts/restore.sh <Zielordner>/<Zeitstempel>
```

`backup.sh` schreibt nach `<Zielordner>/<UTC-Zeit>/` (Standard `./backups`):

- `db.sql.gz` (`mariadb-dump --single-transaction --routines --triggers` als App-Benutzer, ohne Sperre),
- `storage.tgz` (Volume `<projekt>_storage`),
- `SHA256SUMS`.

Es behält die `N` neuesten Sicherungen (Standard 7). Die Sicherung enthält die ganze Datenbank und den Schlüssel. Halte den Ordner nur für root lesbar und kopier ihn zusätzlich auf einen anderen Rechner. Täglich per Cron **auf dem Host** (nicht im Scheduler-Container, der sieht die Volumes nicht):

```
30 3 * * * cd /opt/ignis && scripts/backup.sh /var/backups/ignis --keep 14 >>/var/log/ignis-backup.log 2>&1
```

**Vor `restore.sh` eine frische Sicherung ziehen.** `restore.sh` prüft die Prüfsummen, schaltet den Wartungsmodus an, hält `worker`, `scheduler` und `reverb` an, löscht **alle Tabellen und Views**, spielt den Dump ein und ersetzt den Inhalt des Storage-Volumes. Dann startet es den Stapel (`docker compose up -d`, Migrationen laufen, falls das Image neuer ist) und schaltet den Wartungsmodus aus. Dabei gilt:

- Der Stapel muss laufen (`docker compose up -d`), auch auf einem frischen Server.
- Alles, was seit der Sicherung geschrieben wurde, ist weg.
- Bricht es nach dem Löschen ab, bleibt eine leere oder halbe Datenbank zurück. Das Skript meldet das (Wartungsmodus an, Dienste gestoppt). Dann den Lauf wiederholen oder den Stapel von Hand öffnen: `docker compose up -d` und `docker compose exec app php artisan up`.
- Die Volumes des Updaters (`update-state`, `update-backups`) sind nicht Teil der Sicherung. Die Update-Backups ersetzen keine Datensicherung.

**Ohne Docker:** dieselben Schritte von Hand, vorher `php artisan down`:

```
mariadb-dump --single-transaction --routines --triggers -u ignis -p ignis | gzip > ignis-db.sql.gz
tar czf ignis-storage.tgz -C /pfad/zu/ignis storage .env
```

Zurückspielen: Datenbank und Storage aus **demselben** Sicherungsstand einspielen. Vorher alle Tabellen löschen (Muster in [docs/dev/update-recovery.md](docs/dev/update-recovery.md)), danach `php artisan up`.

### Hinweise

Diese Punkte betreffen den Docker-Stapel.

- **Sitzungscookie:** Compose setzt `SESSION_SECURE_COOKIE=true`, das Cookie geht also nur über HTTPS. Hinter einem TLS-Proxy ist das richtig. Nur für einen reinen http-Test `SESSION_SECURE_COOKIE=false` in `.env`, sonst bleibst du nicht angemeldet.
- **HSTS und Header:** `Strict-Transport-Security` (ein Jahr, `includeSubDomains`), `X-Content-Type-Options` und `Referrer-Policy` kommen vom nginx des Basis-Images, `X-Frame-Options` von der App. Beendet dein Proxy TLS, darf er `Strict-Transport-Security` nicht noch mal setzen. Die Umleitung von http auf https macht der Proxy.
- **Datenbank-Root:** Das Root-Passwort steht nicht im Container-Log. Ohne `DB_ROOT_PASSWORD` in `.env` setzt das Init-Skript (`docker/mariadb/init/10-ignis.sh`) ein Zufallspasswort und legt es nur im Volume `db` ab: `docker compose exec db cat /var/lib/mysql/.root-password`. root kommt nur lokal im Container rein. Dasselbe Skript gibt `ignis` die Rechte auf `ignis_trial` für den Probelauf. Beides wirkt nur bei einem **frischen** `db`-Volume. Ältere Installationen behalten ihr altes Root-Passwort und brauchen den `GRANT` aus [Aktualisieren](#aktualisieren).
- **Logs:** Docker-Logs rotieren (10 MB × 5 je Container). Die Anwendung schreibt ab Stufe `warning` nach `storage/logs/laravel-<Datum>.log` (14 Tage) und nach stderr. Anpassbar mit `LOG_LEVEL` und `LOG_DAILY_DAYS`.
- **Container-Härtung:** `app`, `worker`, `scheduler` und `reverb` laufen mit `no-new-privileges`.
- **Basis-Images** (`serversideup/php:8.4-fpm-nginx`, `mariadb:11`, `node:24-slim`) sind in `Dockerfile` und `docker-compose.yml` auf einen Digest (`@sha256:…`) festgelegt. Der Betreiber erneuert ihn mit jedem Release.
- **Datenbank:** Der Stapel bringt MariaDB 11 mit. Ohne Docker brauchst du mindestens MariaDB 10.11 (siehe [Installation mit Laravel](#installation-mit-laravel-ohne-docker)).

### Fehlersuche

- **Logs:** `docker compose logs app` (ebenso `worker`, `scheduler`, `db`). Den Zustand zeigt `docker compose ps`. Ohne Docker liegen die Logs in `storage/logs/`.
- **Health:** `curl http://127.0.0.1:8080/healthz` (ohne Docker `{APP_URL}/healthz`) liefert JSON mit `status` (`ok`, `degraded`, `down`) und `checks` für `db`, `queue`, `storage`, `migrations`, `php_extensions` und `rewrite`.
  - `down` heißt HTTP 503, etwa wenn die Datenbank nicht erreichbar ist, `storage` nicht beschreibbar ist oder keine Migration gelaufen ist.
  - `degraded` heißt HTTP 200 mit Einschränkung, etwa eine fehlende PHP-Erweiterung oder ein Front-Controller, der nicht `public/index.php` ist.
  - `/api/health` liefert dasselbe, `/api/version` die Version.
- **"set DB_PASSWORD in .env" oder "set APP_URL in .env" beim Start:** `.env` liegt nicht neben `docker-compose.yml`, oder der Wert fehlt.
- **`/install` liefert 404:** Die Instanz ist schon installiert.

### Anmeldung und Konten

#### Discord-Bot und Webhooks

`APP_URL` muss die öffentliche `https://`-Adresse sein, denn Links in Discord-Nachrichten (Einladungen, Benachrichtigungen) und der OAuth-Redirect werden daraus gebaut.

1. Im Developer Portal bei derselben Anwendung unter *Bot* einen Bot anlegen und das Token kopieren.
2. In Ignis unter *Einstellungen > System > Discord* das Token eintragen. Es wird verschlüsselt gespeichert und nie wieder angezeigt.
3. Den Bot über den Einladungslink auf dieser Seite zum Server hinzufügen.
4. Webhooks für Kanal-Benachrichtigungen auf derselben Seite eintragen. Erlaubt sind nur Discord-Adressen.

**Name und Avatar des Bots** änderst du nur im Developer Portal, nicht in Ignis. Nachrichten laufen über den Container `worker`. Läuft er nicht, bleiben sie in der Warteschlange liegen (siehe [Worker und Scheduler](#worker-und-scheduler)).

#### Erster Administrator

Der erste Administrator ist die Discord-ID, die du bei der Installation angegeben hast (`--admin-discord-id` oder das Feld im Assistenten). Meldet sich diese ID zum ersten Mal an, bekommt sie ein Konto mit der Rolle "Admin" und dem Super-Admin-Merkmal, egal welcher Registrierungsmodus gilt. Einen Befehl `bootstrap:admin` gibt es nicht mehr.

Achtung: Wird dieses Konto später hart gelöscht, entsteht beim nächsten Login derselben ID wieder ein Super-Admin. Willst du das nicht, änderst du `install.admin_discord_id` (im Moment nur per `tinker`, wie unten gezeigt).

#### Registrierungsmodus und Einladungen

`auth.registration_mode` bestimmt, wer ein neues Konto bekommt:

- `code` (Standard): nur mit Einladungscode.
- `open`: jeder mit Discord-Konto.
- `closed`: niemand neu. Bestehende aktive Konten melden sich weiter an.

Einladungen erzeugt ein Administrator unter `/users/invitations` (Recht `users.create`, auf Wunsch mit Ablauf in Tagen). Der Link sieht so aus: `{APP_URL}/invite?code=...`. Den Code kann man auch auf der Anmeldeseite eingeben. Jeder Code gilt einmal.

Den Modus stellst du auf der Konfigurationsseite im Reiter "Anmeldung" um, oder per Befehl (Werte `open`, `code`, `closed`, dauerhaft in der Datenbank):

```
docker compose exec app php artisan tinker --execute="app(App\Support\Settings::class)->set('auth.registration_mode', 'open');"
```

#### Rollen und Rechte

Super-Admins verwalten unter `/users/roles` die Rollen, unter `/users` die Konten und unter `/users/audit-log` das Protokoll. Der Rang ist `priority`, eine kleinere Zahl bedeutet einen höheren Rang. Jedes Konto hat genau eine Rolle. Ein Benutzer darf nur Konten mit niedrigerem Rang ändern, deaktivieren oder löschen, nie sich selbst und nie einen Super-Admin.

### Personal

Personalakten liegen unter `/personnel` (Rechte `personnel.view`, `personnel.edit`, `personnel.delete`, `personnel.comment.delete`). Die Stammdaten (Dienstgrade, Titel, Qualifikationen Feuerwehr und Rettungsdienst, Fachdienste) liegen unter `/settings/personnel/…` und lassen sich nur mit `admin` ändern. Der Installer legt die Standardwerte des Altsystems an.

- **Profilbilder:** PNG, JPEG oder WebP, bis 2 MB und 4096 x 4096 Pixel. Sie sind nicht öffentlich, sondern kommen über `/storage/profile-pictures/<Name>` nur an angemeldete Benutzer. Das Verzeichnis gehört in die [Datensicherung](#datensicherung).
- **Einladung aus der Akte:** Wer die Akte bearbeiten und Einladungen erzeugen darf (`users.create`), kann aus einer Akte einen Einladungslink machen. Wer ihn einlöst, wird mit der Akte verknüpft. Im Modus `closed` wirkt der Link nicht.
- **Konto und Akte:** Verknüpft wird in der Benutzerbearbeitung. Bei der Anmeldung und beim Speichern einer Akte verknüpft Ignis ein Konto automatisch mit der Akte, die dieselbe Discord-ID trägt (Super-Admins nie automatisch). Ein Super-Admin darf sein eigenes Konto von Hand verknüpfen und lösen. Andere Super-Admin-Konten und die Konten anderer Benutzer bleiben dafür gesperrt.
- **Charakter-ID:** `personnel.require_character_id` (Standard aus) macht sie zur Pflicht. Schalter unter *Einstellungen > System > Konfiguration*, Reiter "Funktionen".

### Dokumente

Dokumente entstehen aus Vorlagen im Editor und werden als PDF ausgestellt. Rechte: `personnel.documents.view` (ansehen), `personnel.documents.manage` (anlegen, bearbeiten, ausstellen, Altdokumente löschen), `admin` (ausgestellte Dokumente löschen, Verlauf). Vorlagen verwaltest du unter `/settings/documents/editor-templates`, "Meine Dokumente" liegt unter `/me/documents`.

- **Organisation:** Name, Art, Stadt, Straße und PLZ (in Vorlagen `organisation.*`, im Altsystem `SERVER_NAME`, `RP_ORGTYPE`, `SERVER_CITY`, `RP_STREET`, `RP_ZIP`) pflegt ein Administrator im Reiter "Organisation" der Konfigurationsseite (`/settings/organisation` führt dorthin). Der Import übernimmt die Werte. Beispielwerte des Altsystems wie `Musterstadt` oder `CHANGE_ME` gelten als leer. Was fehlt, trägst du von Hand nach, sonst bleibt es im Dokument leer. Der Editor zeigt dann "Noch offen" und fragt vor dem Ausstellen nach.
- **Systemvorlagen:** Der Installer legt die zehn Standardvorlagen des Altsystems an (Urkunden, Zertifikate, Schreiben). Vorlagen mit schon vorhandenem Namen bleiben unberührt. Die Liste der Lehrgangszertifikate pflegst du in der Vorlage.
- **Import:** `ignis:import-legacy` übernimmt Vorlagen, Editor-Dokumente und Altdokumente. PDFs kopiert er nur mit `--uploads=PFAD` (Wurzelordner der Altinstallation mit `storage/documents` und `storage/private/editor-documents`). Ohne die Option bleiben Zeilen ohne Datei, vorhandene Kopien bleiben unverändert. Details im Abschnitt "Dokumente" in [docs/dev/import.md](docs/dev/import.md).
- **Öffentliche Alt-Links:** Die alten Adressen `/storage/documents/XXXX-XXXX-XXXX.pdf` (importierte Altdokumente mit Buchstaben und Ziffern in der Kennung) bleiben **ohne Anmeldung** abrufbar, damit verschickte Links weiter gehen. Dateien mit sieben Ziffern waren schon im Altsystem gesperrt. Editor-PDFs gibt es nur angemeldet über `/documents/{id}/pdf`. Willst du keine öffentliche Auslieferung, entfernst du die Route `storage/documents/{file}`.
- **PDF-Erzeugung:** Dompdf braucht für große Dokumente Speicher. Der Generator hebt `memory_limit` beim Erzeugen selbst auf 256M und das Zeitlimit auf 60 Sekunden, wenn PHP weniger erlaubt (Web und Worker). Verbietet die Umgebung `ini_set` oder hat der Container weniger als 256 MB, scheitert das Ausstellen mit einer Fehlermeldung. Sehr große Tabellen weist der Editor ab: höchstens 20 Spalten, 50 verbundene Zeilen und 2000 Zellen je Tabelle.
- **Akte löschen:** Solange eine Akte Dokumente hat, ist Löschen gesperrt (im Altsystem wurden die Dokumente mitgelöscht). Erst die Dokumente entfernen.

### Fahrzeuge

Fahrzeuge liegen unter `/vehicles` (Navigation "Fahrzeuge"). Rechte:

- `vehicles.view`: Liste, Detail, Mängel melden, Beladelisten und taktische Zeichen lesen.
- `vehicles.manage`: Fahrzeuge, Beladelisten und Vorlagen bearbeiten, Mängel bearbeiten und lösen, Fahrzeugregister.
- `admin`: Mängel löschen.

Wer nur `vehicles.manage` hat, sieht die Liste nicht (wie im Altsystem) und kann auch Beladelisten und TZ-Vorlagen nicht öffnen. Für Mängel bearbeiten und lösen braucht man beide Rechte. Die Fahrzeug-Karte (`/api/vehicles/{id}/card`, beim Überfahren in den Listen) sieht jedes angemeldete Konto.

- **Einsatzfähigkeit:** Der Schalter "aktiv" gehört allein dem Administrator. Einsatzfähig ist ein Fahrzeug, wenn es aktiv ist **und** keinen offenen blockierenden Mangel hat. Mängel schalten ein Fahrzeug nie ein oder aus (anders als im Altsystem). Ein von Hand deaktiviertes Fahrzeug bleibt also aus, auch wenn der letzte Mangel gelöst ist.
- **Mängel:** `/vehicles/defects` mit Filter nach Fahrzeug und Status, Verlauf je Mangel. Melden geht dort oder am Fahrzeug, und die Besatzung kann auch im eNOTF-Tablet melden (`/enotf/fahrzeuginfo`). Ein neuer Mangel löst eine Benachrichtigung aus (`VehicleDefectReported`).
- **Beladelisten:** `/settings/vehicles/loadouts` mit Fächern, Kategorien und Positionen samt Menge, sortierbar per Ziehen. Eine Kategorie ohne Fahrzeugtyp gilt für alle Typen (im Altsystem für keinen). Der Typ wird als genauer Text verglichen, über Groß- und Kleinschreibung entscheidet die Sortierung der Tabelle in MariaDB.
- **Taktische Zeichen:** Ein Zeichen je Fahrzeug (Reiter am Fahrzeug), Vorlagen unter `/settings/vehicles/symbol-templates`. Die Zeichen kommen aus dem npm-Paket `taktische-zeichen-core` und werden im Browser gezeichnet, ohne CDN. Der Server liest die erlaubten Werte aus `resources/js/lib/tacticalSymbolOptions.json`. Diese Datei muss im ausgelieferten Code bleiben. Für den Import der Vorlagen braucht PHP `ext-intl` (im Docker-Image vorhanden).
- **Fahrzeugregister:** Unter `/vehicles/registry` (Recht `vehicles.manage` bzw. `admin`) fordert ein Administrator das Register des FiveM-Servers an und entscheidet über die Einträge: übernehmen, überschreiben, zusammenführen oder ignorieren. Die Daten kommen über den EMD-Sync (`POST /api/emd/sync`, ef_bridge): Die Anforderung setzt ein Kennzeichen, der nächste Heartbeat holt das Register, die Einträge landen in der Warteschlange. Mehr dazu in `docs/dev/vehicle-registry.md`.
- **Import:** `ignis:import-legacy` übernimmt Fahrzeuge, Wachen-POIs, Mängel mit Verlauf, Beladelisten, Vorlagen und entschiedene Registereinträge (Abschnitt "Fahrzeuge" in [docs/dev/import.md](docs/dev/import.md)). `active` wird unverändert übernommen. Fahrzeuge, die das Altsystem wegen eines Mangels ausgeschaltet hat, bleiben also **inaktiv**. Der Bericht nennt sie, du schaltest sie bei Bedarf von Hand ein.
- **Zeitzone beim Import:** Kommt die Altdatenbank aus einem wiederhergestellten Dump, setz `IGNIS_IMPORT_LEGACY_DB_TIMEZONE` auf die Zeitzone des **ursprünglichen** Servers (dort: `SELECT @@global.time_zone, @@system_time_zone;`). Sonst verschieben sich `resolved_at`, `processed_at` und Zeiten aus fireTab. `TIMESTAMP`-Spalten sind nicht betroffen. Den Dump mit den Standardeinstellungen von `mariadb-dump` (`--tz-utc`) wiederherstellen, nicht mit einer anderen Sitzungszone.
- **Alte Adressen:** `/settings/vehicles/vehicles/…` (ohne `/preview`), `/settings/vehicles/defects/…` und `/settings/vehicles/vehload/index` werden dauerhaft umgeleitet, die Abfrage bleibt erhalten. `/api/v1/…` wird wie im Altsystem als `/api/…` beantwortet.

### Startseite, Schnellzugriffe, Benachrichtigungen und Suche

Das gehört alles zum Kern und braucht keine Einrichtung. Hier steht nur, was du wissen solltest.

#### Startseite und Widgets

`/` zeigt Karten, je nach Recht: Einrichtungs-Checkliste (nur `admin`), Fahrzeug-Kennzahlen und Fahrzeugliste sowie Hinweise (mit `vehicles.view`), "Meine Dokumente" (Konten mit verknüpfter Akte) und, falls eingeschaltet, die Neuigkeiten der Website (nur Administratoren). Karten ohne Recht werden weder gezeigt noch berechnet. Module bringen eigene Karten mit (z. B. eNOTF, FireTab, Anträge). Ist ein Modul aus, fehlen seine Karten.

"Einsatzbereit" zählt unter den aktiven Fahrzeugen mit Statusmeldung die mit FMS-Status 1 oder 2. Fahrzeuge mit blockierendem Mangel stehen extra und werden nicht abgezogen. Die Checkliste kannst du ausblenden, das gilt je Browser.

#### Schnellzugriffe (`/dashboard`)

`/dashboard` zeigt Kacheln in Kategorien (es leitet nicht mehr auf `/` um). Verwaltet werden sie unter `/settings/dashboard` (Recht `dashboard.manage` oder `admin`): anlegen, ändern, löschen, per Ziehen sortieren. Erlaubte Ziele sind interne Pfade (`/vehicles`, `/forms/…`), `http://`, `https://` und `mailto:`. `javascript:`, `data:` und `//host` werden abgelehnt. Die Icons des Altsystems sind Font-Awesome-Klassen. Rund 70 häufige davon werden auf Lucide-Icons abgebildet, der Rest bekommt ein Standard-Icon.

#### Benachrichtigungen und Inbox

Die Glocke oben zeigt die Zahl und die letzten ungelesenen Meldungen. Der Browser fragt alle 30 Sekunden nach, aber nur, wenn der Tab sichtbar ist. `/inbox` listet alles, nach Tagen gruppiert, mit Filter "ungelesen" und Typ. Jeder sieht nur seine eigenen Meldungen.

Meldungen entstehen zum Beispiel bei neuen Mängeln (an aktive Konten mit `vehicles.view` oder `vehicles.manage`, nicht an den Meldenden) und beim Ausstellen eines Dokuments (an das mit der Akte verknüpfte Konto). Der Text wird in der Sprache des Auslösers gespeichert. Ist ein Bot eingetragen, kommen ausgewählte Arten zusätzlich als Discord-Direktnachricht (siehe [Discord-Bot und Webhooks](#discord-bot-und-webhooks)). **Gelesene Meldungen löscht `notifications:prune` nach 180 Tagen, ungelesene bleiben.**

#### Suche (Strg K / ⌘ K)

Die Befehlspalette (Strg K, auf dem Mac ⌘ K, oder der Suchknopf oben) sucht in Personal, Dokumenten, Vorlagen, Fahrzeugen und Mängeln, jeweils nur in dem, was das Konto sehen darf. Sie arbeitet direkt mit der Datenbank, eine Suchmaschine gibt es nicht. Einfache Tippfehler verzeiht sie, solche Treffer sind als ungefähr markiert. Bei Dokumenten gilt: Entwürfe nur mit `document.manage`. Empfänger sehen ihre eigenen ausgestellten Dokumente und finden nur exakte Treffer.

#### Ankündigungen (Opt-in)

Standardmäßig **aus**. Unter *Konfiguration > Datenschutz* (Recht `admin`) gibt es zwei getrennte Schalter:

- **Hinweise vom Hub:** ein Banner für alle Konten, ausblendbar.
- **Neuigkeiten der Website:** eine Kachel auf der Startseite, nur für Administratoren.

Sind beide aus, geht kein einziger Aufruf ins Netz, auch nicht beim Seitenaufruf. Sind sie an, holt nur der Scheduler die Daten (Hub stündlich, Website alle 30 Minuten), nie der Browser.

Übertragen wird nur, was jeder HTTP-Abruf verrät: die IP-Adresse des Servers und der allgemeine User-Agent `IgnisLaravel`. Es gibt **keine Installations-ID**, keinen Heartbeat, keine Telemetrie und keine Benutzer- oder Organisationsdaten. Antworten werden streng geprüft: nur Text, Links nur `https://`, begrenzte Größe, keine Weiterleitungen. Die Adressen kommen aus `HUB_URL` und `FORUM_URL` (nur `https://`, Standard `https://emergencyforge.de`), die Datenschutz-Box auf der Seite nennt die eingestellten Hosts. Schlägt ein Abruf fehl, bleibt der letzte Stand stehen.

#### Scheduler ist Pflicht

Aufräumen und Abrufe laufen im Scheduler:

- `notifications:prune`, `queue:prune-failed --hours=720` (fehlgeschlagene Jobs nach 30 Tagen) und `model:prune` (alte Lauf-Verläufe), täglich
- `ignis:storage:cleanup`, wöchentlich
- `announcements:refresh`, stündlich
- `changelog:refresh`, alle 30 Minuten
- `ignis:heartbeat`, jede Minute

Mit Docker macht das der Dienst `scheduler`. Ohne Docker brauchst du den Cron-Eintrag `* * * * * php artisan schedule:run` (siehe [Installation mit Laravel](#installation-mit-laravel-ohne-docker)). Ohne Scheduler wachsen die Meldungen unbegrenzt, und eingeschaltete Ankündigungen veralten. `php artisan schedule:list` zeigt die Termine.

#### Import von Benachrichtigungen und Schnellzugriffen

Benachrichtigungen, Schnellzugriffe und ausgeblendete Ankündigungen übernimmt `ignis:import-legacy` (Importer `notification`, `dashboard`, `announcement_dismissal`, Einzelheiten in [docs/dev/import.md](docs/dev/import.md)). Gut zu wissen:

- Lief das Altsystem unter einem Unterpfad (z. B. `https://host/intra/`), setz `IGNIS_IMPORT_LEGACY_BASE_PATH=/intra`. Sonst bleiben die Präfixe in Links und Kachelzielen stehen.
- Jede Benachrichtigung bekommt eine feste UUID (Version 5) aus der alten ID. Ein zweiter Lauf aktualisiert also statt zu verdoppeln, setzt aber den Gelesen-Status auf den Stand des Altsystems zurück.
- Links auf Fahrzeuge, Mängel, Akten, Dokumente, die Inbox und die bekannten Modulseiten (Wissensdatenbank, Anträge, Kalender, MANV, Mail, FireTab, eNOTF) werden auf die neuen IDs umgeschrieben. Andere interne Links bleiben, wie sie sind, und werden gemeldet.
- Kachelziele laufen durch dieselbe Prüfung wie auf der Einstellungsseite. Platzhalter (`#`) und unzulässige Ziele werden nicht übernommen, der Bericht nennt sie.

### Systemeinstellungen und Module

Administratoren (Recht `admin`) pflegen die Installation im Browser unter *Einstellungen > System*. Umgebungsvariablen für Einstellungen gibt es nicht. Nur `APP_URL`, `HUB_URL` und `FORUM_URL` stehen in der `.env`.

#### Konfigurationsseite und Reiter

`/settings/system/config` hat einen Reiter je Gruppe:

- **Allgemein:** Name der Installation, Akzentfarbe, Logo, Sprache, Bild für Link-Vorschauen
- **Organisation:** Name, Art, Stadt, Straße, PLZ
- **Anmeldung:** Registrierungsmodus, Tablet-Anmeldung, Discord-Anwendung
- **Schnittstellen:** API-Schlüssel
- **Funktionen:** Charakter-ID als Pflichtfeld
- **Rechtliches**
- **Datenschutz:** Opt-in für Ankündigungen und Versionsprüfung

Jeder Reiter wird für sich gespeichert, `?tab=…` in der Adresse öffnet ihn direkt. Geheime Werte wie das Discord-Secret werden verschlüsselt abgelegt und nie angezeigt. Lässt du das Feld leer, bleibt der alte Wert. Jede Änderung steht im Audit-Log (bei Geheimnissen ohne Wert). Einstellungen späterer Module erscheinen automatisch in ihrem Reiter.

#### Branding, Logo und Akzentfarbe

Unter *Allgemein* stellst du den Namen (Browser-Titel, Anmeldeseite), die Akzentfarbe (`#rrggbb`, Standard `#f0500a`, das Altsystem hatte Rot) und ein Logo ein. Die Fußzeile nennt Art und Stadt der Organisation, der Akzent gilt überall. Hat eine Farbe schlechten Kontrast oder ist sie kaum vom Gefahren-Rot zu unterscheiden, warnt die Seite.

Das Logo: PNG, JPEG oder WebP, höchstens 2 MB, kein SVG. Es liegt auf der privaten Disk `storage/branding`, wird aber unter `/branding/<32 Hex>.<png|jpg|webp>` **öffentlich ohne Anmeldung** ausgeliefert, weil die Anmeldeseite es braucht. Ausgeliefert wird nur das aktuelle Logo.

**Reverse-Proxy:** Die öffentliche Logo-Route ist je Client-IP begrenzt (120 Anfragen pro Minute). Stimmt `TRUSTED_PROXIES` nicht (siehe [Reverse-Proxy und HTTPS](#reverse-proxy-und-https)), teilen sich alle ein Limit, und unter Last lädt die Anmeldeseite das Logo nicht mehr.

#### Rechtliches

Zwei Adressen (Impressum und Datenschutzerklärung, nur `https://`) erscheinen als Links in der Fußzeile. Leer heißt ausgeblendet. Eigene Textseiten gibt es nicht.

#### Module

*Einstellungen > System > Module* schaltet abschaltbare Module **sofort** ein und aus, ohne Neustart. Alle Module sind immer registriert, ihre Migrationen laufen immer. Ein ausgeschaltetes Modul antwortet auf alle Routen mit 404 und fehlt in Navigation, Suche, Startseite und Benachrichtigungen. Abhängigkeiten werden geprüft: Kernmodule und Module, die ein anderes eingeschaltetes Modul braucht, lassen sich nicht abschalten. Die Daten bleiben erhalten.

Abschaltbar sind die Ordner unter `modules/`: Kalender, eNOTF, Vernetzung, FireTab, Formulare, Wissensdatenbank, Fahrtenbuch, Mail, MANV-Board.

**Grenze:** Ereignis-Listener, Konsolenbefehle, Jobs und Beobachter eines abgeschalteten Moduls sind nicht automatisch gesperrt. Module müssen das selbst prüfen (Muster und Hilfen in [docs/dev/modules.md](docs/dev/modules.md)).

#### Systemübersicht und ausstehende Migrationen

`/settings/system` (auch `/settings`) zeigt Version, PHP und Datenbank, Gesundheitsprüfungen, Lebenszeichen des Schedulers, Warteschlange, Kennzahlen und die größten Tabellen. Geheimnisse und Servervariablen zeigt sie nicht.

Stehen Migrationen aus (etwa wenn `AUTORUN_ENABLED` aus ist), zeigt ein Banner die Liste, statt dass Seiten mit einem Fehler ausfallen. Mit Docker startest du dann **den Container neu** (beim Start läuft `migrate --force`) oder rufst `docker compose exec app php artisan migrate --force` auf. Danach `worker` und `scheduler` neu starten, damit sie den neuen Code laden.

#### Theme

Jedes Konto wählt oben in der Oberfläche *Dunkel*, *Hell* oder *System* (folgt dem Betriebssystem). Die Wahl hängt am Konto und gilt auf allen Geräten.

#### Updates

Update per Klick und Versionsprüfung stehen unter [Aktualisieren](#aktualisieren). *Datenschutz > Versionsprüfung* (`updates.check_enabled`, Standard aus) schaltet nur die tägliche Prüfung ein. Der Klick selbst fragt GitHub einmalig ab.

#### Import der Systemeinstellungen

`ignis:import-legacy` übernimmt die Konfiguration des Altsystems: Name, Akzentfarbe, Logo, Organisation, Rechtliches, Charakter-ID, Anmeldung und Modulschalter aus `intra_plugins`. Die Zuordnung je Schlüssel steht in `AppImportegacyconfigmap`. Optionen:

- **`--overwrite-settings`:** Altwerte ersetzen schon gespeicherte Einstellungen. Ohne den Schalter gewinnt, wer zuerst da war. Weil der Installer immer Namen und Modulschalter schreibt, **kommen Name und Schalter des Altsystems ohne diese Option nicht an** (der Bericht nennt die behaltenen Werte). Tipp: den **ersten** echten Lauf mit `--overwrite-settings` machen, spätere ohne, damit deine Änderungen im neuen System bleiben.
- **`--import-api-key`:** übernimmt den alten API-Schlüssel, damit `ef_bridge` einfach weiterläuft. Ohne den Schalter behält die Instanz ihren eigenen Schlüssel (`ignis:api-key` zeigt ihn).
- **`--uploads=PFAD`:** Wurzelordner der Altinstallation. Kopiert auch das Logo aus dem alten `storage/branding` in das `storage/branding` der neuen Instanz.
- **Discord-Bot-Token:** wird **nie** übernommen. Trag ihn nach dem Import **neu ein** (*Einstellungen > System > Discord*).
- `SYSTEM_URL` und `BASE_PATH` fallen weg, die Adresse ist `APP_URL`. Zeigt `SYSTEM_URL` auf einen anderen Host als `APP_URL`, warnt der Bericht.

### API-Schlüssel für ef_bridge

Der Schlüssel wird beim ersten Bedarf erzeugt und verschlüsselt in der Datenbank abgelegt. Du siehst ihn **nur über diesen Befehl** oder per Klick unter *Konfiguration > Schnittstellen*. Im Seitenquelltext steht er nie.

```
docker compose exec app php artisan ignis:api-key
```

Den Wert trägst du einmal auf dem FiveM-Server in der Konsole ein: `efbridge key ignis <Schlüssel>`.

- `--regenerate` erzeugt einen neuen Schlüssel. Der alte ist sofort ungültig, `ef_bridge` musst du dann neu einrichten.
- Angenommen wird der Schlüssel im Header `X-API-Key` (bevorzugt, siehe [Zugriffsprotokolle](#reverse-proxy-und-https)), in der Query `api_key` oder im JSON-Feld `intraRP_API_Key` bzw. `api_key`.
- Ist kein Schlüssel gesetzt, antwortet die API mit 503, nie mit Zugriff. Zugriff ohne Schlüssel von `localhost` gibt es nicht mehr.

### Tablet-Login (ef_bridge)

Im FiveM-Browser funktioniert Discord-OAuth nicht. Deshalb holt der FiveM-Server mit dem API-Schlüssel einen Einmal-Token (`POST /api/tablet/login-token`, Body `{"discord_id": "..."}`, 60 Sekunden gültig) und öffnet damit `{APP_URL}/auth/tablet?token=...`. Das meldet das Konto zu dieser Discord-ID an, aber nur, wenn es aktiv ist.

Ab Werk ist das **aus** (Antwort 404 `disabled`). Eine Oberfläche dafür gibt es noch nicht, du schaltest es per Befehl ein:

```
docker compose exec app php artisan tinker --execute="app(App\Support\Settings::class)->set('auth.tablet_login', true);"
```

Der Wert muss der Boolean `true` sein, nicht der Text `"true"`. Ausschalten mit `false`. Der Import übernimmt `TABLET_LOGIN_ENABLED` aus dem Altsystem, wenn die Einstellung noch nicht gesetzt ist.

### Import aus dem Altsystem

Ein bestehendes Ignis/intraRP übernimmst du mit `php artisan ignis:import-legacy`. Das umfasst Konten und Rollen, Personal, Dokumente, Fahrzeuge, Benachrichtigungen, Schnellzugriffe und Systemeinstellungen. Anleitung, Voraussetzungen und Regeln für wiederholte Läufe: [docs/dev/import.md](docs/dev/import.md).

Kurz gesagt:

1. `LEGACY_DB_*` in `.env` setzen. Mit Docker danach `docker compose up -d`, ohne Docker `php artisan optimize`.
2. Erst mit `--dry-run` laufen lassen und den Bericht lesen.
3. Dann ohne `--dry-run`.

Profilbilder und Dokument-PDFs kopiert der Import nur mit `--uploads=PFAD`. Das ist der Ordner der Altinstallation, der `storage/profile-pictures/` enthält. Mit Docker musst du ihn in den Container einbinden. Ohne die Option meldet der Bericht "N Profilbilder nicht kopiert" (Details im Abschnitt "Personal" in `docs/dev/import.md`).

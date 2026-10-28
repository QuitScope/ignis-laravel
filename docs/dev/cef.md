# FiveM CEF (Chromium 103)

FiveM zeigt die Tablets (FireTab, eNOTF) in seinem eingebetteten Chromium (CEF), **Chromium 103**, in einem
Cross-Site-NUI-Iframe von ef_bridge (`html/master.html`, `#firetabScreen`). Tailwind 4 / Vite 8 verlangen offiziell
Chrome ≥ 111. Diese Seite hält fest, was kaputtgeht, wie es behoben ist und wie du es erneut prüfst.

## Probe

`tests/e2e/cef/probe.mjs` rendert dieselben Seiten in einem echten Chromium 103 und in einem aktuellen Chromium und
vergleicht Box, Sichtbarkeit und Farben jedes Elements (dazu eine synthetische Dialogbox mit den Klassen von
`DialogContent`). Sie braucht keine npm-Abhängigkeiten (DevTools-Protokoll über das eingebaute `WebSocket` von Node);
Playwright kam nicht in Frage, weil keine Playwright-Version Chromium 103 mitbringt (1.22 = 102, 1.23 = 104). An den
User-Agent wird ` CitizenFX/1.0` angehängt, damit die Middleware `fivem` den Browser als Tablet behandelt, und die
Anmeldung läuft über den echten Tablet-Login.

Erneut ausführen (Windows, Git Bash; Pfade sind Beispiele):

```bash
# 1. browsers (outside the repo): Chromium 103.0.5058.0 snapshot r1002410 and a current headless shell
npx @puppeteer/browsers install chromium@1002410 --path /tmp/cef
npx @puppeteer/browsers install chrome-headless-shell@stable --path /tmp/cef
# 2. build and serve against a scratch database (never the real one; the file name must contain "cef")
npm run build
touch /tmp/cef.sqlite
(cd public && DB_DATABASE=/tmp/cef.sqlite APP_URL=http://127.0.0.1:8123 php -S 127.0.0.1:8123 \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) &
# 3. fresh DB + one tablet-login URL per browser (tokens live 60 s, so run 4 right after)
eval "$(DB_DATABASE=/tmp/cef.sqlite php tests/e2e/cef/setup.php http://127.0.0.1:8123 OLD NEW | sed 's/=\(.*\)/="\1"/')"
# 4. probe (default pages: /login, /, /personnel, /mci/create; --paths to change)
node tests/e2e/cef/probe.mjs --old /tmp/cef/chromium/win64-1002410/chrome-win/chrome.exe \
    --new /tmp/cef/chrome-headless-shell/win64-*/chrome-headless-shell-win64/chrome-headless-shell.exe \
    --login-old "$OLD" --login-new "$NEW" --out storage/cef-probe
```

`php artisan serve` funktioniert hier nicht: Es entfernt Umgebungsvariablen, die nicht auf der Whitelist stehen, sodass
`DB_DATABASE` auf die `.env` zurückfiele. Exit-Code 1 bedeutet: JS-Fehler in 103, eine verworfene `@media`-Regel oder
ein Layoutunterschied; Farbunterschiede werden nur aufgelistet. Screenshots und `report.json` landen in `--out`.
FireTab, eNOTF, Klinikportal, Vernetzung und Reverb: siehe "Gesamtprobe" unten (`--post`, Netzwerkprotokoll).

## Ergebnis (2026-10-22, Tailwind 4.3.3, Vite 8.3.4)

Chromium 103 fehlen: die Bereichssyntax für Media Queries `(width>=40rem)` (104), die Eigenschaften
`translate`/`scale`/`rotate` (104), `:has()` (105), Container Queries (105), `color-mix()` und `oklch()` (111),
CSS-Nesting (112), `lh` (109) und `dvh` (108). Vorhanden sind `@layer` und `@property`. Auf keiner Seite gibt es
JS-Fehler.

| Problem in 103 vor dem Fix | Fix |
|---|---|
| Alle 8 responsiven `@media`-Regeln fielen weg (`sm:`…`2xl:`): keine Sidebar, Suchfeld eingeklappt | `build.cssTarget` mit `chrome103` in `vite.config.ts`; lightningcss (der CSS-Minifier von Vite) schreibt sie auf `min-width` um |
| `translate-x/y-*` wurde ignoriert: Dialoge landen unten rechts | Rückfall `@supports not (translate: 0)` auf `transform` in `resources/css/app.css` |
| `color-mix()` fehlt: Tailwind fällt auf den deckenden Token zurück, `bg-danger/10 text-danger` ergibt Rot auf Rot (Fehlerboxen unleserlich) | Build-Plugin `cef-color-mix-fallback` (`resources/js/lib/cefCss.ts`): Mischungen unter 50 % werden dort zu `transparent`, wo `color-mix()` fehlt; ab 50 % bleibt der deckende Rückfall |
| JS-Syntax | `build.target` mit `chrome103` (bisher kein Fehler aufgetreten; senkt künftige Syntax ab) |
| Leerer `var()`-Fallback einer registrierten `*`-Eigenschaft ohne `initial-value` (`var(--tw-ring-inset,)`, `var(--tw-ordinal,)`) ist in 103 ungültig, die ganze Deklaration fällt weg: keine `ring`/`focus-visible:ring`, kein `tabular-nums`, kein `filter`/`blur`, kein `rotate-*` (Gesamtprobe) | Build-Plugin `untypedPropertyFallback` (`resources/js/lib/cefCss.ts`): entfernt diese `@property`-Regeln und setzt die Variablen stattdessen in Tailwinds unterster Schicht `properties` je Element auf `initial` (ersetzt `inherits: false`) |

Nach dem Fix: keine Layoutunterschiede, keine verworfenen `@media`-Regeln, keine JS-Fehler auf `/login`, `/`,
`/personnel`, `/mci/create`. Die Targets übernehmen für Edge/Firefox/Safari den Vite-Standard, andere Browser sind
also nicht betroffen.

## Bekannte Grenzen (akzeptiert)

- `:has()`-Utilities (`has-checked:` am Theme-Schalter) bewirken nichts: Das aktive Theme wird nicht hervorgehoben.
  Ebenso das `has-[>svg]:px-*` des Buttons: Buttons mit Icon behalten das breitere Text-Padding (+4 px pro Seite,
  z. B. "Ton aus" im ASÜ-Board). Ohne `:has()` ist kein CSS-Rückfall möglich; rein kosmetisch.
- Deckkraft-Tönungen unter 50 % entfallen, sie werden nicht angenähert (Eingaben `dark:bg-input/30`, weiche Badges);
  echtes Alpha bräuchte Kanal-Tokens (`--danger-rgb`). Setze keinen Text auf eine Tönung von ≥ 50 % der eigenen Farbe.
- Native `<select>` sind um ca. 6 px breiter oder schmaler; Datumsfelder sind in 103 standardmäßig `inline-flex`.
- Verwende auf Tablet-Seiten ohne Rückfall keine Container Queries, `dvh`, `lh`, `text-wrap`, `field-sizing`,
  `@starting-style`; führe die Probe für neue Seiten erneut aus.

## Cookies und Header (Middleware `fivem`)

`App\Http\Middleware\FiveMWebview` (Alias `fivem`; auf `/auth/tablet` und auf den Routengruppen von FireTab und
eNOTF) erkennt FiveM am User-Agent `CitizenFX`, an `Sec-Fetch-Dest: iframe` oder an einem nicht leeren
`X-Iframe-Request`, danach an einem Sitzungs-Flag (XHR/fetch aus dem Iframe senden `Sec-Fetch-Dest: empty`). Dann
entfernt sie CSP und `X-Frame-Options` (SecurityHeaders überspringt sie über `FiveMWebview::isFiveM`) und setzt, **nur
über HTTPS**, das Sitzungs- und das `XSRF-TOKEN`-Cookie auf `SameSite=None; Secure`. Reines HTTP bleibt bei `Lax`,
das NUI-Iframe bekommt dort also keine Sitzung (ef_bridge erzwingt `https`). Normale Browser bleiben unverändert. Eine
Route ohne `fivem` schreibt die Cookies wieder als `Lax`, womit die Sitzung im Iframe verloren geht: Darum schickt
`/auth/tablet` eine erkannte FiveM-Anfrage statt auf `/` auf `/firetab` oder, mit `to=enotf`, auf `/enotf` (beides
`fivem`-Routen); normale Browser landen weiterhin auf `/`. Tests: `tests/Feature/FiveMWebviewTest.php`,
`tests/Feature/TabletLoginTest.php`.

Beide CSS-Rückfälle (translate, color-mix) liegen in der Tailwind-Schicht `utilities`, sodass Variantenreihenfolge und
ungeschichtete Regeln weiterhin Vorrang haben.

## Geteilte Cookies: Tablet-Login, Fahrzeug-Login, zwei Frames

eNOTF und FireTab laufen als zwei Frames desselben Browsers und teilen sich ein Sitzungscookie und ein
`XSRF-TOKEN`. Laravels `session()->regenerate()` rotiert auch das CSRF-Token, was den nächsten Schreibvorgang des
anderen Frames brechen würde, wenn dieser ein gerendertes Token hält. Deshalb gilt:

- `/auth/tablet` regeneriert nur, wenn das Konto wechselt; löst dasselbe Konto ein zweites Token ein, behält es
  Sitzungs-ID, Daten und CSRF-Token (`TabletLoginController::login`).
- Der Fahrzeug-Login (`App\Vehicles\CrewSession::login`, POST `/firetab/login`) ruft `session()->migrate(true)` auf:
  neue Sitzungs-ID gegen Fixation, alte ID zerstört, CSRF-Token bleibt. Das neue Cookie landet im gemeinsamen
  Cookie-Speicher, der andere Frame folgt also mit seiner nächsten Anfrage. Verbleibende Race: Eine Anfrage des
  anderen Frames, die mit der alten ID schon unterwegs ist, bekommt einmal eine leere Sitzung (Weiterleitung zum
  Login / 419).
- Der Besatzungsstatus liegt in der Sitzung unter `crew.<context>` (`crew.firetab`, `crew.enotf`; Middleware
  `crew:<context>`, `firetab.crew` = `crew:firetab`), die Fahrzeugsitzung des einen Frames überschreibt die des anderen
  also nicht; das Abmelden vom Fahrzeug (POST `/firetab/logout`) lässt das Konto angemeldet.

## Nachricht mit dem Charakter-Token

Ältere Seiten riefen `GET /api/character/get-session-id` ab und schickten die **Sitzungs-ID** an ef_bridge. Jetzt
antwortet diese Route mit 410, und die geteilte Prop `fivem.identifyToken` trägt ein Einmal-Token, nur für ein
angemeldetes Konto mit dem User-Agent `CitizenFX`. `TabletLayout` und `AppLayout` rufen beim Einhängen
`useFiveMIdentify()` (`resources/js/lib/fivemIdentify.ts`) auf: `postMessage({type: 'ignis_session', session_id: token}, origin)`
an das Elternfenster (NUI-Iframe, Origin fest auf `https://cfx-nui-ef_bridge`) oder an das Fenster selbst (DUI, eigener
Origin), dieselbe Nachricht wie bisher, ef_bridge bleibt also unverändert. Ein Elternfenster mit anderem Origin (eine
andere NUI-Ressource, eine Website, die die Seite einbettet) bekommt nichts: Sonst könnte es das Token innerhalb seiner
10 Minuten für einen fremden Charakter einlösen. **Wenn du die Ressource ef_bridge umbenennst**, ändert sich ihr
NUI-Origin und die Charakter-Erkennung stoppt unbemerkt (`EF_BRIDGE_ORIGIN` anpassen). Das Token wird
wiederverwendet, solange es einlösbar oder verknüpft ist, sodass `state.linked` in ef_bridge Wiederholungen
überspringt; eine DUI, die die Nachricht nie erhält, startet trotzdem beim Laden des Frames (`dui_check.lua`).
**Noch nicht geprüft** in einem echten FiveM-Client.

## eNOTF: Sperrbild und Abfrage

Das Sperrbild (`/enotf/lockscreen`, PIN-Feld, `inputmode="numeric"`) und der 10-s-Statusabruf (`GET /enotf/status`, `setInterval` + `document.visibilityState`, nur `fetch`, kein EventSource/WebSocket) nutzen nur Chromium-103-taugliche Mittel; die Teilen-Dialoge und die Eingabe der PIN stehen **nicht** in einer Chromium-103-Probe (offen). Die Abfrage läuft nur auf Übersicht und Protokollseiten; jede andere Seite sperrt erst mit dem nächsten Request (Redirect/423). Die Sperre gilt pro Besatzung: ein zweites Gerät derselben Besatzung wechselt beim nächsten Abruf auf das Sperrbild.

## eNOTF: Klinikportal und Tafel

Die Seiten unter `/enotf/schnittstelle/*` (Login mit Zugangscode, Verfügbarkeit, Klinikcode, Ankunftstafel) tragen `fivem`, brauchen kein Konto und laufen in CEF wie im normalen Browser. Die Tafel pollt alle 15 s per `setInterval` + `fetch` (kein EventSource/WebSocket); gedrosselte Formulare zeigen das Inertia-429-Fenster. Chromium-103-Probe: "Gesamtprobe".

## eNOTF-Ziel

`/auth/tablet?token=…&to=enotf` schickt eine FiveM-Anfrage auf `/enotf`; `to` wird gegen die Allowlist
`firetab|enotf` geprüft (keine URL), alles andere führt auf `/firetab`. ef_bridge baut den Link heute ohne `to`
(`server/main.lua`, `requestTabletLogin`: `loginUrl .. '?token=' .. data.token`, geprüft in
`tests/tablet_login_check.lua`). Für das NUI genügt das: `tabletLogin()` in `html/js/master.js` lädt den Link im Frame
des Tablets und setzt den Frame bei `load` auf seine vorherige Seite zurück (eNOTF: `enotf/overview.php` aus `shared/defaults.lua`, intern auf `/enotf/overview` umgeschrieben), die
Landung auf `/firetab` ist also nie zu sehen. Eine DUI, die vor dem Login gestartet wurde, lädt bei `tabletLoginDone`
neu. Sollte ef_bridge einmal direkt auf das Login-Ziel landen (DUI ohne vorherige Seite), hängt es `'&to=enotf'` an,
wenn `tabletType` `eNOTF` ist (serverseitig, neben dem `?token=`); auf der ignis-Seite ist keine Änderung nötig.

### eNOTF-Probe (Setup)

`tests/e2e/cef/setup.php` mit Labels, die mit `ENOTF` beginnen, stellt Tablet-Login-URLs (`&to=enotf`) für ein Konto
**mit Personalakte und ohne Super-Admin** aus (ein Super-Admin schreibt nie als Besatzung, die Seiten wären nur
lesbar), legt ein RTW-Fahrzeug (Dienst 2) mit einem offenen Protokoll (ENR `990001`) an und gibt `VEHICLE=` und `ENR=`
aus. `probe.mjs --enotf-vehicle <id>` meldet die Besatzung nach dem Tablet-Login an (`POST /enotf/login`, PIN `1234`,
wird akzeptiert, egal ob eine PIN verlangt ist). DUI-Tasten: `resources/js/lib/duiKeys.ts` (`useDuiKeys()` in
`TabletLayout.vue`) verarbeitet die `efKey`-Nachrichten von ef_bridge (Tippen, Tab, Enter, Pfeiltasten in Auswahlen)
nur im obersten Fenster.

```bash
eval "$(DB_DATABASE=/tmp/cef.sqlite php tests/e2e/cef/setup.php http://127.0.0.1:8123 ENOTF_OLD ENOTF_NEW | sed 's/=\(.*\)/="\1"/')"
P=/enotf/p/$ENR
node tests/e2e/cef/probe.mjs --old <chrome103.exe> --new <headless-shell.exe>     --login-old "$ENOTF_OLD" --login-new "$ENOTF_NEW" --enotf-vehicle "$VEHICLE" --out /tmp/cef/out     --paths "$P,$P/rettdaten?t=von,$P/rettdaten?t=ziel,$P/erstbefund?t=erweitern&q=1,$P/erstbefund?t=messwerte,$P/anamnese?t=anamnese,$P/diagnose?t=haupt&q=8,$P/massnahmen?t=zugang&q=2,$P/massnahmen?t=medimaske,$P/verlauf?t=list,$P/verlauf?t=add,$P/abschluss?t=rea,$P/abschluss?t=freigabe"
```

Windows: Entpacke den Chromium-Snapshot in einen **kurzen** Pfad (z. B. `%TEMP%\cef9e`); in einem tiefen Verzeichnis
bricht das Entpacken des Zips lautlos bei MAX_PATH ab und `@puppeteer/browsers` meldet eine fehlende `chrome.exe`.

## eNOTF-Protokoll

Die Protokollseiten nutzen nur Chromium-103-taugliche Mittel: **keine nativen Popups** (`<select>`,
`type=date|time|datetime-local`, `<datalist>`): Auswahl über `SelectSheet`/`Suggest`, Datum/Zeit über
Textmasken (`MaskedDate`/`MaskedTime`, `inputmode=numeric`, DUI tippt per `insertText`), Ziffern über `NumberPad`
(Maus). Panels und Fokus-Dialoge (`SelectSheet`, `Suggest`, "Art ändern", Teilen) hängen per `Teleport` an `body` mit
`position:fixed` und tragen die Klasse `enotf` (Tokens gelten außerhalb der Shell); Farben als `rgba()`-Literale, kein
`color-mix()`, `:has()`, Container-Queries, `dvh`, `translate`-Eigenschaft, Nesting. Das Körperschema (`BodyMap`)
schneidet mit `clip-path: url(<absolute Dokument-URL>#<ID je Instanz>)`; die URL wird bei `?t`/`?q`-Wechsel neu
berechnet (`history.replaceState` ändert die Dokument-URL, relative `url(#id)` würde sonst ins Leere zeigen).

**Ergebnis (2026-10-25, Chromium 103.0.5058.0 r1002410 gegen chrome-headless-shell 156, 1280×800):** Übersicht
`/enotf/p/{enr}` und `rettdaten?t=von|ziel`, `erstbefund?t=erweitern&q=1|messwerte`, `anamnese?t=anamnese`,
`diagnose?t=haupt&q=8`, `massnahmen?t=zugang&q=2|medimaske`, `verlauf?t=list|add`, `abschluss?t=rea|freigabe`:
**keine JS-Fehler, keine toten `@media`, keine Layoutunterschiede** (Exit 0). Einzige Farbabweichung: Zähler-Pille
`bg-[rgba(0,123,255,0.2)]` im Verlauf (Rundung 0,120,255 vs 0,125,255, unsichtbar). Das Körperschema wird in 103
identisch beschnitten (Screenshots verglichen). Die Probe ignoriert seither Elemente in SVG-`<defs>` (103 meldet
Boxen für nie gerenderte `clipPath`-Inhalte, aktuelles Chromium 0×0).

**Nicht geprüft:** offene Panels/Dialoge (SelectSheet, Suggest, Teilen, Art ändern): die Probe lädt
nur Seiten und klickt nicht; Eingaben per DUI-Tastatur; echtes FiveM NUI/DUI; Breiten 1920×1080 und 1366×768;
Login/Anlegen/Sperrbild/Druck/Klinikportal (in dieser Probe nicht geladen; siehe "Gesamtprobe").

## Gesamtprobe

**2026-10-25, Chromium 103.0.5058.0 r1002410 gegen chrome-headless-shell 156.0.8078.47, 1280×800, Tailwind 4.3.3.**
`setup.php` legt zusätzlich ein Löschfahrzeug mit Einsatz (ASÜ-Trupp, Lagekarten-Marker), eine Klinik mit Abteilung und
Zugangscode an und schaltet `firetab`, `enotf`, `logbook`, `federation` ein. `probe.mjs --post '<pfad>|<formular>|<ziel>'`
(mehrfach) schickt nach dem Tablet-Login (ohne `--login-*`: nach der ersten Seite) Formular-POSTs, z. B. die
FireTab-Fahrzeuganmeldung oder die Klinik-Anmeldung, und bricht ab, wenn die Weiterleitung woanders endet; `--enotf-vehicle`
ist eine Kurzform davon. Je Seite listet die Probe WebSocket-Handshake, Pusher-Ereignisse und `/broadcasting/auth`-Status.

```bash
# FireTab (Besatzung = Konto mit Personalakte, darum die ENOTF-Logins)
--login-old "$ENOTF_OLD" --login-new "$ENOTF_NEW" --post "/firetab/login|vehicle_id=$FIRE_VEHICLE|/firetab/list" \
  --paths "/firetab/login,/firetab/list,/firetab/incidents/$INCIDENT,/firetab/incidents/$INCIDENT?tab=atemschutz,/firetab/incidents/$INCIDENT/map,/firetab/status,/firetab/logbook"
# QM, Verbund, Druck (Super-Admin)
--login-old "$OLD" --login-new "$NEW" --paths "/firetab/qm,/firetab/qm/incidents/$INCIDENT,/federation/protocols,/federation/incidents,/enotf/print/$ENR"
# Klinikportal (ohne Konto): erst login,klinikcode; dann angemeldet
--post "/enotf/schnittstelle/login|poi_id=$HOSPITAL&code=$HOSPITAL_CODE|/enotf/schnittstelle" --paths "/enotf/schnittstelle/availability,/enotf/schnittstelle"
```

Git Bash: `MSYS_NO_PATHCONV=1` vor `node`, sonst werden `/firetab/...`-Argumente zu Windows-Pfaden.

| Gruppe | Ergebnis |
|---|---|
| FireTab `/firetab/login`, `list`, Einsatz, `?tab=atemschutz`, `/map`, `status`, `logbook` | keine JS-Fehler, keine toten `@media`; Exit 1 nur durch den "Ton aus"-Knopf im ASÜ (`has-[>svg]`, akzeptiert, siehe oben). Datumsfelder `display:flex` statt `block` (bekannt, kein Layoutunterschied) |
| FireTab-QM `/firetab/qm`, `/firetab/qm/incidents/{id}`; Verbund `/federation/protocols`, `/incidents` | vor dem Fix: Tabellenspalten/Zähler um bis zu 9 px verschoben, weil `tabular-nums` in 103 wirkungslos war (Ursache siehe Tabelle oben, belegt per `getComputedStyle`: `normal` statt `tabular-nums`). Danach nur noch die nativen `<select>` (+6 px) und die Knöpfe rechts davon (akzeptiert); Theme-Schalter `has-checked:` und `bg-input/30` wie bekannt |
| eNOTF `/enotf/login`, `/enotf/overview`, `/enotf/print/{enr}` und alle 9e-Protokollseiten | Exit 0, keine Layoutunterschiede; Übersicht: Knopf mit `bg-danger/60` deckend (≥ 50 %, bekannt) |
| eNOTF `/enotf/lockscreen` | ohne Sperre leitet die Seite zur Übersicht; das gesperrte Sperrbild selbst ist **nicht** geprobt |
| Klinikportal `/enotf/schnittstelle/login`, `klinikcode`, `availability`, Tafel `/enotf/schnittstelle` | Exit 0 |
| Desktop `/login`, `/`, `/personnel`, `/mci/create` (Gegenprobe nach dem Fix) | Exit 0, nur bekannte Farbabweichungen |
| Reverb (`BROADCAST_CONNECTION=reverb`, `reverb:start`, Node-Proxy als nginx-Ersatz: `/app/` → 8081, sonst → `php -S`) | in 103 auf FireTab-Liste/Einsatz/ASÜ/Karte/Status, eNOTF-Übersicht/Protokoll und `/`: `ws://…/app/<key>` Handshake 101, `pusher:connection_established`, `/broadcasting/auth` 200, `pusher_internal:subscription_succeeded` |

Rings (`focus-visible:ring-[3px]`) sind statisch nicht sichtbar; der Fix ist per `getComputedStyle` in beiden Browsern
gegengeprüft (`box-shadow`, `filter`, `transform`, `font-variant-numeric` gleich). **Nicht geprüft:** echter Puls über
Reverb (kein Ereignis ausgelöst), gesperrtes Sperrbild, offene Panels/Dialoge, DUI-Tastatur, echtes FiveM NUI/DUI, TLS/`wss`.

## Akzeptierte Risiken

- `fivem`-Routen lassen sich von jedem Origin einbetten, sobald der Browser `Sec-Fetch-Dest: iframe` sendet (so
  gewollt: Das NUI-Iframe muss sie laden). CSRF-Tokens gelten weiterhin für jeden Schreibvorgang. Um `frame-ancestors`
  auf den NUI-Origin zu beschränken, braucht es den echten Origin aus einem FiveM-Client.
- Das dauerhafte Sitzungs-Flag `fivem` läuft nie ab (es lebt so lange wie die Sitzung) und wirkt nur auf
  `fivem`-Routen.

Ohne echten FiveM-Client nicht prüfbar: der genaue CEF-User-Agent und ob er `Sec-Fetch-Dest` sendet, das
Cookie-Verhalten im NUI-Iframe (Cookie-Richtlinie für Drittanbieter), die Darstellung des echten CEF-Builds (die Probe
nutzt einen normalen Chromium-103-Snapshot), GPU- und Schriftunterschiede.

## WebSocket / Echtzeit

Opt-in (`BROADCAST_CONNECTION=reverb`); sonst wird kein WebSocket geöffnet. Client: `laravel-echo` 2.6.1 + `pusher-js` 8.6.0 (exakt gepinnt, lazy in eigenen Chunks `echo-*.js`/`pusher-*.js`), die Dists sind auf APIs jenseits Chromium 103 geprüft (`toSorted`, `withResolvers`, `structuredClone`, `groupBy`, `Set.union`, `AbortSignal.any`: keine). Syntax transpiliert Vite (`chrome103`).

- **Verbindung:** pusher-js wird in `realtime.ts` selbst erzeugt: `wsPath` = Prop `realtime.path`, `forceTLS` folgt dem Schema von `APP_URL` (https gleich wss, sonst ws), `enabledTransports: ['ws','wss']`, `disableStats`. Gleicher Ursprung über den nginx der App (`/app/`), kein zweiter Port. Setzt ein Proxy eine CSP, braucht sie `connect-src 'self' wss://<host>` (ob `'self'` in Chromium 103 `wss:` abdeckt, ist nicht geprüft).
- **Autorisierung:** der WebSocket-Handshake trägt keine Cookies. Echo ruft `POST <path>/broadcasting/auth` per `fetch` (JSON, `X-XSRF-TOKEN`) mit dem Sitzungscookie; die Route liegt in der Gruppe `fivem`, also `SameSite=None; Secure` im Iframe wie bei den anderen Seiten (Test in `RealtimeChannelAuthTest`). Echo 2.6 kennt keinen `authorizer` mehr, deshalb `channelAuthorization.customHandler`.
- **DUI:** eigener Ursprung gleich ignis, gleiche Regeln. `document.hidden` ist dort vermutlich immer `false` (nicht belegt), unschädlich.
- **Rückfall:** schlägt Verbindung oder Autorisierung fehl (WebSocket blockiert, 401/419 nach Sitzungsablauf, Reverb aus), pollen die Seiten im alten Takt weiter, nach `failed` ohne eigenen Retry. Abonnements enden nicht beim Abmelden, sondern beim Seitenwechsel oder Reconnect (Restrisiko: ein Puls verrät nur "etwas geändert").
- **Nicht geprüft:** echtes FiveM (NUI und DUI), `wss` hinter TLS. Chromium 103 gegen laufenden Reverb: "Gesamtprobe".

## Vernetzung

- Im Tablet (CEF) ändert sich nur die FireTab-Leitersuche: bei aktivem Modul kommen Verbundtreffer mit Text-ID `fed:{link}:{remote}` und Namen "Name [Instanz]" (reiner Text, kein `v-html`). Keine neuen Browser-APIs.
- Einstellungsseite und Verbund-Seiten (`/settings/federation`, `/federation/*`) sind Desktop-Seiten außerhalb der Gruppe `fivem`; sie nutzen nur Vue-Textbindung, `fetch`, `navigator.clipboard` (Chromium ≥ 66) und `<details>`. Nicht in Chromium 103 geprobt.

## Visueller Vergleich

Werkzeug unter `tests/e2e/visual/`: Screenshots per DevTools, Pixel-Differenz, Rechenwerte. Pfadlisten
(Stichprobe, Tablet-Beweis) stehen als Kommentar in `shot.mjs`. Referenz des Altsystems und Vorher-Aufnahmen liegen
**außerhalb des Repos** (`visual-ref/p14/`).

```bash
npm run build                                   # gebautes Bundle; ein laufender Vite-Dev-Server (public/hot) bleibt unberührt
export DB_CONNECTION=sqlite DB_DATABASE=/tmp/visual/visual.sqlite CHROME=<chrome.exe>   # Name MUSS "visual" enthalten
touch "$DB_DATABASE"; php tests/e2e/visual/setup.php http://127.0.0.1:8422 fresh   # visual.admin, visual.viewer, Module an; Darstellung `system` (oder fresh dark|light)
php tests/e2e/visual/setup.php http://127.0.0.1:8422 data                          # Daten; gibt VEHICLE, FIRE_VEHICLE, ENR, INCIDENT aus
(cd public && php -S 127.0.0.1:8422 ../tests/e2e/visual/router.php &)              # eigener Router, ignoriert public/hot
AT=$(php tests/e2e/visual/setup.php http://127.0.0.1:8422 token admin | tail -1)   # Tablet-Token (einmalig): admin | viewer [enotf]
node tests/e2e/visual/shot.mjs after out/ http://127.0.0.1:8422/login "$AT" http://127.0.0.1:8422/ --widths 1366 --paths /,/personnel
python tests/e2e/visual/diff.py <vorher> <nachher>      # Präfixe before-/after- (oder legacy-/new-); Anteil Pixel > 25 Graustufen
```

Tablet-Seiten: Token `viewer enotf` (kein Super-Admin, mit Personalakte), dazu
`--post '/enotf/login|vehicle_id=<VEHICLE>&pin=1234' --post '/firetab/login|vehicle_id=<FIRE_VEHICLE>' --widths 1280`.
Vorher-Stand in einem `git worktree`: `vendor` dort nicht als **eine** Verknüpfung auf das Haupt-`vendor` anlegen: Composer leitet `app/` und `modules/` aus dem echten Pfad von `vendor/composer` ab, der Server liefe sonst mit dem PHP-Code
des Hauptbaums. `vendor/composer` und `vendor/autoload.php` kopieren, die Paketordner einzeln verknüpfen. Nach dem Lauf Server beenden und die Wegwerf-Datenbank löschen. Unter Git Bash `MSYS_NO_PATHCONV=1` setzen, sonst werden
`/pfad`-Argumente zu Windows-Pfaden umgeschrieben.

## Skin-Probe (Login, Startseite)

**2026-10-26, Chromium 103.0.5058.0 r1002410 gegen chrome-headless-shell 156.0.8078.47, 1280×800.**
Desktop mit `OLD`/`NEW` (Super-Admin), Tablet mit `ENOTF_OLD`/`ENOTF_NEW`, `--enotf-vehicle` und
`--post "/firetab/login|vehicle_id=$FIRE_VEHICLE|/firetab/list"`.

| Seite | Ergebnis |
|---|---|
| `/login` | Exit 1 nur durch `.ignis-login-stage__heat` (±3 px Höhe). Ursache: die Atem-Animation (`transform: scaleY`, 7 s, `infinite`) steht in beiden Browsern in anderer Phase; im zweiten Lauf kehrte sich die Abweichung um (251/613 ↔ 254/610). Kein 103-Fehler, **akzeptiert**. Keine JS-Fehler, keine toten `@media` |
| `/` | keine Layoutunterschiede; `.ignis-hint__text` meldet `display: -webkit-box` (103) gegen `flow-root` (156, neue `line-clamp`-Umsetzung), gleiche Box; `ignis-btn--accent-soft` Farbe ±6 nach Rundung |
| `/settings` | Exit 0, keine Unterschiede |
| `/personnel` | nur Bekanntes: Knöpfe mit `bg-input/30` (`color-mix`) durchsichtig, native `<select>` +6 px |
| `/mci/create` | nur Bekanntes: Dateifeld `inline-flex` statt `inline-block`, gleiche Box |
| `/enotf/overview`, `/firetab/list` (Skin `classic`) | Exit 0; Übersicht: Knopf mit `bg-danger/60` deckend (≥ 50 %, bekannt) |

**Nicht geprüft:** offene Menüs/Drawer/Dialoge des neuen Skins (die Probe klickt nicht), eingeklappte Sidebar, echtes FiveM-CEF. Tablet-Dialoge/Teleports/`SelectSheet` nicht geöffnet. Isolation über das `html`-Attribut nur konstruktiv begründet, nicht gemessen.

`data-skin` (und das feste Dunkel der Anmeldung, `data-force-dark`) setzt schon `app.blade.php` über `App\Support\LayoutSkin`, dieselbe Regel wie `resources/js/lib/pageSkin.ts`, beide gleich halten.

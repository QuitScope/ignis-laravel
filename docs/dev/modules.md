# Module anlegen

Ein Modul ist ein Ordner unter `modules/<Name>/` mit einem Service-Provider. Die Registry (`App\Modules\ModuleRegistry`) findet jede Datei `modules/<Name>/*ServiceProvider.php`, deren Klasse `App\Modules\ModuleProvider` erweitert, und registriert **jedes** Modul, ob eingeschaltet oder nicht. Der Schalter wirkt pro Anfrage; Umschalten wirkt sofort, ohne Neustart.

**Grenze des Schalters.** Automatisch gesperrt sind bei einem abgeschalteten Modul nur:

- Routen aus `routes/web.php` und `routes/api.php` des Moduls (404 wie eine fehlende Route, auch bei falscher Methode und `OPTIONS`),
- Einträge in Suche, Startseite, Benachrichtigungstypen, Einstellungen und Rechten, die den Modulnamen tragen,
- Scheduler-Einträge aus `schedule()`, Listener aus `$this->listen()`, Befehle auf Basis von `ModuleCommand`,
- Navigationseinträge mit `module`.

**Alles andere bleibt aktiv** und muss selbst prüfen: Listener über `Event::listen()`, Observer, Queue-Jobs (auch schon eingereihte), Policies/Gates, Broadcast-Kanäle, eigene Routen außerhalb der Routendateien. Muster am Anfang:

```php
if (! \App\Modules\ModuleRegistry::active('demo')) {
    return; // Job: return; Policy: return false; Observer/Listener: return;
}
```

Migrationen und Übersetzungen bleiben immer geladen, Daten bleiben erhalten.

Der Name eines Moduls ist sein Ordnername in Kleinbuchstaben (`Demo` → `demo`). Er steht im Schalter `modules.demo.enabled`, im Gate `module:demo` und in jedem Hook.

## Aufbau

```
modules/Demo/
  DemoServiceProvider.php        Pflicht
  routes/web.php                 optional, Middleware `module:demo` + Gruppe `web`
  routes/api.php                 optional, Middleware `module:demo` + Gruppe `api` (stateless)
  database/migrations/*.php      optional, läuft bei `php artisan migrate` immer mit
  lang/de.json, lang/en.json     optional, JSON-Übersetzungen
```

`ModuleProvider::boot()` lädt alle vorhandenen Teile selbst; eigene Registrierung ist nicht nötig. Der Namespace `Modules\` ist in `composer.json` auf `modules/` abgebildet. Wer `boot()` überschreibt, ruft `parent::boot()` auf.

Die Routendateien werden mit der jeweiligen Gruppe eingebunden, aber **ohne** Präfix. In `routes/api.php` des Moduls muss `/api/...` also selbst im Pfad stehen (anders als bei `routes/api.php` der Anwendung).

## Metadaten

Der Provider beschreibt das Modul für die Seite *System > Module* (`/settings/system/modules`). Alles außer `moduleName()` ist optional:

```php
<?php

namespace Modules\Demo;

use App\Modules\ModuleProvider;

class DemoServiceProvider extends ModuleProvider
{
    public function moduleName(): string { return 'Demo'; }

    public function label(): string { return 'demo.label'; }             // Standard: moduleName()
    public function description(): string { return 'demo.description'; } // Standard: '' (keine Beschreibung)
    public function group(): string { return 'einsatz'; }                // einsatz | verwaltung (Standard)
    public function depends(): array { return ['logbook']; }             // Standard: []
    public function core(): bool { return false; }                       // Standard: false
}
```

`label()` und `description()` sind Übersetzungsschlüssel (oder Klartext). Ein **Kernmodul** (`core(): true`) ist immer eingeschaltet, erscheint nicht auf der Seite und nicht im Prop `app.modules`. `depends()` nennt Module, die eingeschaltet sein müssen: Die Seite lehnt es ab, ein Modul abzuschalten, solange ein eingeschaltetes Modul oder ein Kernmodul davon abhängt, und eines einzuschalten, dessen Abhängigkeit aus ist. Nicht installierte Module in `depends()` werden ignoriert (wie im Altsystem). Der Installationsassistent bietet nur abschaltbare Module an und prüft dieselben Abhängigkeiten. `ModuleRegistry::all()` liefert diese Angaben als `ModuleInfo`.

## Schalter

Der Schalter ist die Einstellung `modules.<name>.enabled`. Fehlt sie, gilt das Modul als eingeschaltet; ein unbekannter Name gilt als aus. Der Installationsassistent setzt die Schalter für alle gefundenen abschaltbaren Module entsprechend der Auswahl (`--modules=` bei `ignis:install`), danach die Seite *System > Module* (nur Admin, Audit "Modul aktiviert/deaktiviert", Modul `System`). Ist die Tabelle `settings` noch nicht vorhanden, gelten alle Module als eingeschaltet.

Abgeschaltet heißt: Daten und Einstellungswerte bleiben erhalten, die Tabellen existieren weiter, nur der Zugang ist gesperrt.

## Routen

`ModuleProvider` setzt das Gate `module:<name>` (`App\Http\Middleware\EnsureModuleEnabled`) **vor** die Gruppe `web` bzw. `api`. Ein abgeschaltetes Modul antwortet damit genau wie eine fehlende Route (404, gleiche Seite, bei JSON gleiche Meldung), ohne Sitzung und ohne Umleitung zur Anmeldung. Eine falsche Methode (sonst 405) und `OPTIONS` (sonst 200 mit `Allow`) beantwortet der Router vor jeder Routen-Middleware; dafür läuft dieselbe Middleware ohne Modul global (nur `OPTIONS`) und `bootstrap/app.php` macht aus dem 405 ein 404, wenn jeder passende Pfad hinter einem abgeschalteten Modul liegt. Ein Architekturtest (`ModulesTest`) prüft, dass jede Route eines Moduls das Gate trägt. In der Routendatei kommen die übrigen Middlewares wie gewohnt dazu:

```php
// modules/Demo/routes/web.php
Route::middleware('auth')->get('/demo/ping', fn () => 'pong');
```

Routen außerhalb der Modul-Routendateien (z. B. in einem eigenen `Route::group` im Provider) brauchen das Gate selbst: `Route::middleware(['module:demo', 'web', 'auth'])`.

## Hooks mit Modulname

Jeder Eintrag, den ein Modul in eine Registry schreibt, nennt sein Modul; die Registry lässt ihn weg, solange das Modul aus ist (`ModuleRegistry::active()`). Eintragen in `register()` des Providers:

```php
public function register(): void
{
    // Suche und Startseite: die Klasse implementiert zusätzlich App\Modules\BelongsToModule (module(): 'demo')
    $this->app->make(\App\Search\SearchRegistry::class)->register(new DemoSource);
    $this->app->make(\App\Dashboard\DashboardWidgets::class)->register(new DemoWidget);

    // Benachrichtigungstypen: Schlüssel `module`; Einträge des Typs verschwinden aus Posteingang und Zählern
    $this->app->make(\App\Notifications\NotificationKinds::class)
        ->register('demo_ping', ['label' => 'demo.kind', 'icon' => 'bell', 'module' => 'demo']);

    // Einstellungen: Argument `module`; der Reiter bzw. die Schlüssel verschwinden aus der Konfiguration
    $this->app->make(\App\Support\SettingDefinitions::class)
        ->register('demo.greeting', group: 'demo', type: 'string', default: 'Hallo', module: 'demo');

    // Navigationszähler: null = Eintrag für dieses Konto nicht sichtbar; eine Ausnahme wird geloggt und lässt nur ihn weg
    $this->app->make(\App\Navigation\NavigationCounters::class)
        ->register('demo', fn (\App\Models\User $u) => 3, module: 'demo');
}
```

Die Zähler kommen als lazy Prop `nav.counters` (`{demo: 3}`, nur angemeldet, einmal je voller Seite) und mit der Glocke alle 30 s (`navCounters` in `/api/notifications/poll`); in `navigation.ts` nennt der Eintrag den Schlüssel als fünftes Argument von `item(...)` (Feld `counter`), die Seitenleiste zeigt das Abzeichen.

**Super-Admins:** `Gate::before` lässt sie alles bestehen außer Abilities mit einem Präfix aus `AppServiceProvider::NO_SUPER_ADMIN_BYPASS` (`mail.message`, `mail.delivery`), Policies, die `App\Auth\NoSuperAdminBypass` implementieren, und `UserPolicy`; dort entscheidet die Ability selbst.

Kernquellen und Kern-Widgets implementieren `BelongsToModule` nicht und sind immer aktiv.

**Scheduler:** Aufgaben in `schedule()` des Providers eintragen; jeder dort angelegte Eintrag bekommt automatisch `->when(...)` mit dem Schalter, geprüft bei jedem Lauf:

```php
public function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
{
    $schedule->command('demo:cleanup')->daily();
}
```

Wer Einträge anderswo anlegt, hängt `->when(\App\Modules\ModuleRegistry::whenEnabled('demo'))` selbst an.

**Listener:** `$this->listen(Event::class, Listener::class)` in `boot()` statt `Event::listen()`: der Listener läuft (bzw. wird eingereiht) nur, solange das Modul an ist. Ein bereits eingereihter Listener/Job prüft selbst (Muster oben).

**Befehle:** Die Befehlsklasse erweitert `App\Modules\ModuleCommand` (statt `Command`) und nennt ihr Modul in `module()`; registriert wird wie üblich mit `$this->commands([...])`. Bei abgeschaltetem Modul gibt der Befehl "Modul demo ist deaktiviert" aus und endet erfolgreich, ohne `handle()`.

**Navigation:** Die Navigation steht in `resources/js/lib/navigation.ts`. Ein Eintrag eines Moduls bekommt das Feld `module` (`item('/demo', 'demo.nav', can(user, 'demo.view'), 'demo')`) und erscheint nur, wenn der Prop `app.modules` das Modul nennt; die Aktionen der Befehlspalette folgen automatisch. `app.modules` ist die Liste der eingeschalteten abschaltbaren Module, nur für angemeldete Konten (Gäste bekommen `[]` und erfahren nicht, welche Module es gibt). Er liegt unter `app`, weil Seiten schon einen Prop `modules` haben.

## Einstellungen registrieren

Systemeinstellungen beschreibt die Registry `App\Support\SettingDefinitions` (Gruppe, Typ, Standardwert, Regeln, Geheimnis). Ein Modul trägt seine Schlüssel in `register()` seines Providers ein und nennt dabei `module:` (siehe oben):

```php
public function register(): void
{
    $defs = $this->app->make(\App\Support\SettingDefinitions::class);
    $defs->register('demo.greeting', group: 'features', type: 'string', default: 'Hallo', rules: ['max:50'], module: 'demo');
    $defs->register('demo.webhook', group: 'features', type: 'url', default: '', secret: true, module: 'demo');
}
```

Typen: `string` (eine Zeile, höchstens 255 Zeichen), `bool` (`true|false|1|0|on|off|yes|no`), `int` (Grenzen über `min:`/`max:` in `rules`), `enum` (Werte in `options`), `color` (`#rrggbb`), `url` (nur `https://` mit Host, leer = nicht gesetzt), `list` (höchstens 100 Texte zu je 100 Zeichen). `editable: false` hält einen Schlüssel aus dem allgemeinen Formular (eigene Oberfläche), `deletableOnly: true` erlaubt dort nur das Leeren. Ein doppelter Schlüssel wird mit Warnung ignoriert, der erste gilt. Schlüssel eines abgeschalteten Moduls verschwinden aus dem Formular (eine Gruppe nur aus solchen Schlüsseln samt Reiter, Speichern antwortet 404); `Settings::get()` liefert weiter Wert bzw. Standard.

`Settings::get()` liefert ohne gespeicherten Wert den Standard der Definition (ein beim Aufruf übergebener Standard hat Vorrang). Beschriftungen kommen aus den Übersetzungen `settings.<key>.label` und `settings.<key>.hint` (de und en, z. B. in `lang/de.json` des Moduls); der Reitername einer eigenen Gruppe aus `settings.tab.<gruppe>` und `settings.tab.<gruppe>.lead`. Interne Werte, die niemand im Formular ändern soll (Zeitstempel, ETags, Fehlertexte), werden nicht registriert.

Regeln des Konfigurationsformulars: Ein gesendeter Wert, der dem wirksamen Standard entspricht, wird nicht geschrieben und nicht protokolliert; ändert sich der Standard später, wirkt das auf Installationen, die den Schlüssel nie angefasst haben. Datenschutz-Schalter (Opt-in) setzen deshalb `alwaysStore: true`: sie bekommen beim Speichern immer eine eigene Zeile, damit ein späterer Standardwechsel sie nicht einschaltet. Geheimnisse (`secret: true`) werden getrimmt, nie zurückgesendet (die Seite kennt nur "gesetzt") und ein leeres Feld lässt den gespeicherten Wert unverändert; ein gespeichertes Geheimnis lässt sich über das Formular daher nicht leeren. Geheimnisse bleiben auch bei Validierungsfehlern aus dem Session-Zwischenspeicher (alte Eingaben) heraus, egal in welcher Gruppe oder von welchem Modul sie registriert sind.

## Rechte registrieren

Rechte beschreibt die Registry `App\Auth\Permissions` (Name, Gruppe im Rollen-Editor, Modul). Die Kernrechte stehen in der Klasse selbst; ein Modul trägt seine in `register()` seines Providers ein:

```php
public function register(): void
{
    \App\Auth\Permissions::register('demo.view', group: 'demo', module: 'demo');
    \App\Auth\Permissions::register('demo.manage', group: 'demo', module: 'demo');
}
```

Beschriftungen: Gruppe `permissions.group.<gruppe>`, Recht `permissions.<name>` (de und en, z. B. in `lang/de.json` des Moduls); ein viertes Argument `label` ersetzt den Rechte-Schlüssel. Derselbe Name mit derselben Gruppe und demselben Modul noch einmal ist ohne Wirkung, mit anderer Gruppe oder anderem Modul ein Programmierfehler (`LogicException`).

- `Permissions::catalog()` und `::groups()` enthalten den Kern und die **eingeschalteten** Module: Rollen-Editor, die Rechteliste eines Super-Admins im Prop `auth.user.permissions`, die Prüfung in `RoleRequest`.
- `Permissions::known()` enthält alle registrierten Rechte, auch die abgeschalteter Module; der Import meldet nur Rechte als "unbekannt", die niemand registriert.
- Rechte eines abgeschalteten Moduls (`Permissions::hidden()`) bleiben an den Rollen gespeichert, erscheinen aber weder im Rollen-Editor noch in `auth.user.permissions`; Speichern einer Rolle ohne sie entfernt sie nicht. Neu vergeben lassen sie sich erst, wenn das Modul wieder an ist.
- Die Prüfung selbst (`$user->can(...)`, Policies, Gate `admin`, Super-Admin) bleibt unverändert; die Routen des Moduls sind bei abgeschaltetem Modul ohnehin gesperrt.

## Öffentliche Seiten und Gast-Layout (Beispiel Wissensdatenbank)

Eine Modulroute ohne `auth` ist auch für Gäste erreichbar; der Controller entscheidet dann selbst (`Gate::allows('read', …)`: Gast zum Login oder 404 bei Archiviertem). Die Wissensdatenbank liest `/lexicon` und `/lexicon/{id}` nur bei `knowledgebase.public_access` ohne Anmeldung, nie archivierte Einträge, nie Namen von Bearbeitern, Gäste sind begrenzt (Limiter `kb-read`). Die Seite setzt für Gäste den Prop `noindex` und den Header `X-Robots-Tag: noindex`; `resources/js/components/knowledgebase/kbLayout.ts` wählt danach `GuestLayout` (Gast) oder `AppLayout` (Konto), ohne Props an das Layout zu reichen (Props würden zu DOM-Attributen). Bilder `GET /storage/kb-images/{file}` laufen ohne Sitzung und Cookie (`withoutMiddleware(...)`), der Controller startet Sitzung und Anmeldung selbst, solange der Schalter aus ist; die Kernroute `/storage/{area}/{file}` schließt den Bereich `kb-images` aus. Feste Pfade (`/lexicon/taxonomy`, `/lexicon/create`, …) müssen **vor** dem Platzhalter `/lexicon/{entry}` stehen, der Platzhalter braucht `whereNumber`.

## Haken für Fahrtenbuch-Einträge

`Modules\Logbook\LogbookService::record()` ist der Weg für eNOTF (`/enotf/fahrtenbuch`) und FireTab (`/firetab/logbook`); die Admin-Seite nutzt denselben Weg. Fahrzeug, Fahrer und Quelle kommen vom Aufrufer (aus der Sitzung dort), nie aus dem Formular:

```php
public function record(array $data, Vehicle $vehicle, string $driver, string $source, ?User $by): LogbookEntry
// $data: trip_date (Y-m-d), departed_at (H:i), arrived_at (H:i|null), distance_km (0..99999.9, eine Nachkommastelle),
//        reason (|null), trip_type (einsatz|bewegung|werkstatt|uebung|dienst|sonstige)
// $source: enotf|firetab|admin (LogbookEntry::SOURCES); $by: Konto für das Audit, null = kein Audit-Eintrag
```

Wirft `ValidationException` bei ungültigen Daten. `vehicle_identifier` ist `Vehicle::identifier`, `station_name` Name (und Ort) der Wache des Fahrzeugs zum Buchungszeitpunkt. Das Modul `logbook` muss an sein: bei ausgeschaltetem Modul wirft `record()` eine `LogicException` und schreibt nichts (der Aufrufer sollte vorher `ModuleRegistry::active('logbook')` prüfen, um die Seite sauber zu sperren).

## Kalender: Zeiten, Serien, Feed

- **Speicherung:** Zeitgebundene Termine (`all_day = false`) liegen in `calendar_events.starts_at/ends_at` als **UTC**. Ganztägige Termine (`all_day = true`) sind **Daten**: beide Spalten tragen `00:00:00` des Kalendertags, `ends_at` ist der **letzte Tag (inklusiv)**, ein eintägiger Termin hat `starts_at == ends_at`. Uhrzeit und Zeitzone werden bei Ganztägigen nie umgerechnet (immer `->toDateString()`); `recurrence_until` folgt derselben Regel. Anzeige und "heute" in `config('ignis.timezone')`.
- **Eingabe:** ganztägig `YYYY-MM-DD`; zeitgebunden ISO mit `Z`/Offset oder ohne als Wanduhr der Organisationszone.
- **Serien:** `recurrence_rule` normalisiert `FREQ=DAILY|WEEKLY|MONTHLY;INTERVAL=n[;BYDAY=MO,..][;COUNT=n]`, `UNTIL` steht in `recurrence_until` (ein `UNTIL` im Regeltext wird dorthin übernommen; ein reines Datum heißt bei zeitgebundenen Terminen "bis Ende dieses Tages" in der Organisationszone). `Modules\Calendar\Support\RecurrenceExpander::expand($eventOderArray, $from, $to, $limit = 1000)` liefert `[{start, end}]` (zeitgebunden UTC, ganztägig Datum mit inklusivem Ende): WEEKLY ohne BYDAY = Wochentag des Beginns, Sprung zum Fenster per Arithmetik, MONTHLY lässt Monate ohne den Tag aus, Wanduhrzeit über Zeitumstellungen stabil.
- **Feed** `GET /calendar/events?from&to` (ISO-Datum oder -Zeitpunkt, Fenster ≤ 400 Tage), je Vorkommen: `{id, occurrence_start, occurrence_end, all_day, title, color, category, location, attendee_count, my_response, can_edit, recurring, source_locked, visibility}`; zeitgebunden `YYYY-MM-DDTHH:MM:SSZ`, ganztägig `YYYY-MM-DD` mit **inklusivem** Ende (FullCalendar braucht exklusiv: +1 Tag in der Oberfläche). `attendee_count`: Einladungen (attendees/private), eindeutige Akten der Rollenmitglieder (role), `null` bei `all`.
- **Sicht:** `CalendarEvent::query()->visibleTo($user)` (Klasse `Visibility`) ist die einzige Regel für Feed, Detail, Policy und Konflikthinweis. Termine mit `form_submission_id` (gespiegelte Abwesenheit) ändert niemand von Hand, auch kein Super-Admin.

## Anträge: Status und Ereignisse

- **Status** (`FormSubmission::STATUSES`): `new`, `in_progress`, `deferred`, `accepted`, `rejected`; jeder darf auf jeden wechseln (die Entscheidung ist kein Zustandsautomat). **Offen** (`FormSubmission::OPEN`) sind nur `new` und `in_progress`: nur sie sind offene Aufgaben (Hinweis-Widget, Filter `status=open`). `deferred` ("Aufgeschoben", so kennt es das Altsystem) ist erledigt, aber nicht angenommen: keine Aufgabe, nicht im Filter "Offen". Neue Status gehören an `STATUSES` und die Sprachdateien (`forms.status.*`); die Spalte ist ein Enum, also eine Migration.
- **`Modules\Forms\Events\FormDecided($submission, $oldStatus)`** feuert nach dem Commit (`DB::afterCommit`) bei **jeder** echten Änderung von Status **oder** Bemerkung; `$oldStatus` ist der Status davor (gleich dem neuen, wenn nur die Bemerkung wechselte). Ohne Änderung feuert nichts. Listener müssen deshalb **idempotent** sein und bei Bedarf selbst `$submission->status !== $event->oldStatus` prüfen. Es gibt keine Benachrichtigung für Listener; der Antragsteller wird vom Controller benachrichtigt (nur bei Statuswechsel oder vorhandener Bemerkung, Art `antrag`).
- **`FormDeleted($id, $formTypeId)`** feuert beim Löschen eines Antrags (Modell-Ereignis `deleted`), mit der Datenbank-ID; die Zeile ist dann weg. Der Antragstyp lässt sich nur ohne Anträge löschen.
- Hängt ein anderes Modul an diese Ereignisse (Muster: Kalender), registriert es den Listener **mit Klassennamen als Zeichenkette** und nur, wenn die Klasse existiert (`class_exists('Modules\Forms\Events\FormDecided')`), und prüft zur Laufzeit `ModuleRegistry::active(...)` beider Module. Ohne Forms-Modul läuft der Kalender, nur ohne Abwesenheitsabgleich; die Spalte `calendar_events.form_submission_id` bekommt ihren Fremdschlüssel auf `form_submissions` nur, wenn die Forms-Tabellen zum Zeitpunkt der Migration schon da waren (sonst eine einfache eindeutige Spalte). Ohne Kalender-Modul merkt Forms nichts.

## Kalender: Abwesenheit, Abo-Token, Kopplung

- **Abwesenheit**: `Modules\Calendar\Support\AbsenceSync::sync(FormSubmission)` spiegelt einen **angenommenen** Antrag eines Typs mit `is_absence` (Felder `von_datum`, `bis_datum`, Typ `date`) als **ganztägigen** Termin: Titel `Abwesend: <Name>` (ohne die Dienstnummer in Klammern am Ende), kein Beschreibungstext, kein Ort, Kategorie Abwesenheit, Sicht `all`, `form_submission_id` gesetzt (Fremdschlüssel mit Cascade), Ersteller = wer entschieden hat, der Antragsteller als angenommener Organisator. **Der Grund und die Antworten des Antrags gelangen nie in den Termin** (nicht in Zeile, Feed, Detail, iCal). Jeder andere Status, ein Typ ohne `is_absence` oder ungültige Daten (`von > bis`, kein Datum) entfernt den Spiegel; wiederholtes Entscheiden ist harmlos. Ein Spiegel ist **gesperrt** (`CalendarEvent::isLocked()`): `PUT`/`DELETE` antworten 403, auch für Ersteller, `calendar.manage`, Admin und Super-Admin. `calendar:backfill-absences [--dry-run]` und der Importer rufen dieselbe Schleife `AbsenceSync::backfill()`.
- **Ganztägig** heißt: `all_day = true`, `starts_at`/`ends_at` sind `00:00:00` des ersten bzw. **letzten** Tages (Ende inklusiv); im iCal `VALUE=DATE` mit **exklusivem** `DTEND` (+1 Tag), im Feed inklusiv (FullCalendar bekommt +1 Tag in der Oberfläche).
- **Serien** sind höchstens 31 Tage je Vorkommen lang (`CalendarEvent::MAX_SERIES_DAYS`; der Vorfilter des Feeds sucht ab `from − 32 Tage`). Längere Termine gibt es nur als Einzeltermin; der Importer macht aus längeren Serien Einzeltermine mit Warnung.
- **Abo-Token:** `users.calendar_token_hash` = SHA-256 des Tokens; der Klartext (`bin2hex(random_bytes(32))`, 64 Zeichen) verlässt `IcalToken::issue()` genau einmal in der Antwort von `POST /calendar/subscription` und steht in der Anwendung nirgends sonst (nicht in Audit, Protokoll, Sitzung). Hinweis für den Betrieb: Die Feed-URL enthält den Klartext-Token, deshalb kann ihn das Zugriffsprotokoll des Webservers (access log) festhalten; den Pfad `/api/calendar/ical/` aus dem Zugriffsprotokoll ausnehmen oder die Protokolle kurz aufbewahren und rotieren. `GET /calendar/subscription` sagt nur `has_subscription`. `GET /api/calendar/ical/{token}` ist öffentlich (Gruppe `api`, ohne Sitzung), antwortet bei jedem Fehler (unbekannt, widerrufen, Konto inaktiv, Recht verloren, Modul aus) gleich mit 404 und ist je IP gedrosselt (Bucket `calendar-ical`, 30/min). Zeitgebundene **Serien** gehen mit `TZID=<Organisationszone>` und erzeugtem `VTIMEZONE` hinaus, einzelne zeitgebundene Termine in UTC.
- **Lose Kopplung:** der Kalender kennt Forms nur über `class_exists`-geschützte Listener (siehe oben) und Zeichenketten-Klassennamen; `AbsenceSync` selbst setzt Forms voraus und wird nur dort aufgerufen, wo Forms da ist (Listener, Befehl, Importer mit `class_exists`-Wache). Neue Querverbindungen zwischen Modulen folgen diesem Muster: Ereignis des einen, Listener im anderen, Schutz durch `class_exists` und `ModuleRegistry::active()`.

## MANV-Board: Rechte, Lagenstatus, Sperren

- **Rechte**: `mci.view` liest (Liste, Board, Log, Patient, Ressourcen), `mci.manage` schreibt und schließt `mci.view` ein; `admin` und Super-Admin dürfen alles. `ManvIncidentPolicy` (Gate-Fähigkeiten `viewAny`, `view`, `create`, `update`, `delete`) prüft mit, ob das Modul eingeschaltet ist. Patienten und Ressourcen schreibt nur, wer `update` auf die Lage hat **und** deren Status `active` ist (`ManvIncidentPolicy::canWrite()`, direkt aufgerufen statt über das Gate, damit `Gate::before` den Status nicht überspringt).
- **Lagenstatus**: `active` nimmt alles an; `closed` nur die Statusänderung; `archived` nur die Statusänderung durch `mci.manage` über die Bearbeiten-Seite. `ended_at` wird beim Schließen oder Archivieren gesetzt und beim Reaktivieren gelöscht. Ein unbekannter Status ist ein 422 (Alt: stilles "aktiv").
- **Alle Aktionen sind Web-Routen** mit `auth`, Policy und `scopeBindings()` unter `/mci/{incident}`; Patient oder Ressource einer fremden Lage ist ein 404. Keine Zustandsänderung per GET (Alt: `quick_sk`, `delete_id`); die JSON-API entfällt. Schreibrouten sind gedrosselt (`throttle` mit den Schlüsseln `manv-write`, `manv-patient`, `manv-resource`, `manv-personnel`).
- **Sperren** (MariaDB; unter SQLite ohne Wirkung): die Patientennummer wird unter einer Sperre auf der Lagenzeile vergeben (`PatientNumber::next`, in der Transaktion des Aufrufers) mit `unique (Lage, Nummer)` als Gürtel; Fahrzeugzuweisung und Abfahrt sperren Patient und Ressource in fester Reihenfolge (Lage, dann Ressource; Patient, dann Ressource) und prüfen Fahrzeugtyp, Sichtung und Ziel **unter der Sperre** erneut. Die Abfahrt ist idempotent (zweimal ⇒ 422, ein Logeintrag), lässt die Ressource bestehen und setzt `departed_at`, die Ressource nur, wenn kein anderer Patient mehr in ihr sitzt.
- **Log:** `ManvLog::record()` schreibt Konto-FK und Namensschnappschuss (Akte, sonst Kontoname) aus dem angemeldeten Konto, nie aus der Eingabe; der Audit-Kanal heißt "MANV" und trägt keine Patientendaten außer der Nummer.
- **Board-Abgleich:** Inertia-Polling alle 20 s (ohne Echtzeit und als Rückfall; mit Echtzeit Sicherheitsabruf 60 s) (`router.reload({ only: ['patients', 'resources', 'stats'] })`). Mit Echtzeit melden die Kanäle `manv` (Thema `list`) und `manv.{incident}` (Thema `board`) Änderungen sofort (Abschnitt "Echtzeit").
- **Erweiterung:** `manv_patients` hat keinen Verweis auf ein eNOTF-Protokoll; ein optionales `enotf_protocol_id` (`null`) lässt sich anhängen, ohne die Abfahrtsregeln zu ändern.
- **Tests:** `tests/Feature/ManvBoard/*`, `LegacyContractTest.php` (Port von `MciPolicyTest`, `MciFormsTest`, `MciBoardTest`), `tests/Feature/ManvBoardRightsMatrixTest.php` (jede Route × Gast/ohne Recht/view/manage/Admin/Super-Admin, aktive, geschlossene, archivierte Lage; vergleicht die Routenliste mit `P7A_ROUTES`), `tests/Feature/ManvBoardIntegrationTest.php`, Importer in der Gruppe `legacy` (`tests/Feature/Import/ManvImportTest.php`), `tests/js/manv.test.ts`.

## Mail: Postfächer und Zugriff

- **Kein SMTP:** Modul `mail` (`modules/Mail`, Namespace `Modules\Mail`, Tabellen `mail_*`); Laravels `Mail`-Fassade und `config/mail.php` bleiben unberührt.
- **Zugriff auf Inhalte** nur über `Delivery::query()->visibleTo($user)` (Kopien der Postfächer aus `MailboxAccess::accessible()`, nicht gelöscht). Nie über `hasPermission`, `can('admin')` oder eine Policy ohne `App\Auth\NoSuperAdminBypass` (Architekturtest in `tests/Feature/Mail/SchemaTest.php`); keine Rechte mit Präfix `mail.message`/`mail.delivery`. `admin`, `mail.admin` und Super-Admins öffnen nichts zusätzlich.
- **`MailboxAccess`:** `current()` (eigenes, aktiv und nicht gesperrt), `accessible()` (eigenes + offene Gruppenpostfächer mit Mitgliedschaft), `selected($user, $id)` (nur darunter; Sitzungsschlüssel `mail.mailbox_id`), `autoBind()` (freies Postfach an das **eine** aktive Konto mit `users.personnel_id` der Akte, das noch keins hat; nie umhängen).
- **Provisionierung:** `MailboxProvisioner::sync()` über `PersonnelSaved`/`PersonnelDeleted` und nächtlich `mail:backfill` (03:15, `--dry-run` rollt zurück). Beim Löschen einer Akte geht nur **ihr** Postfach inaktiv (`deactivateFor` auf `eloquent.deleting: Personnel`, in der Lösch-Transaktion, bevor der FK `personnel_id` leert); alle übrigen Waisen legt erst der Backfill still. `is_locked` fasst nichts Automatisches an. Adresse aus `full_name` (nie Titel) per `AddressGenerator` (Muster `initial_dot_last`: Initiale wächst bei Kollision, dann Zähler; `first_dot_last`: Zähler), belegt = Postfach ∪ Verteiler ∪ Historie (`AddressRules::isTaken`).
- **Route-Binding `{message}`** (Provider): nur über `MailDelivery::visibleTo`, sonst 404 (auch Admin/Super-Admin). Postfach eines Posts: `mailbox_id`, sonst die Wahl; fremdes Postfach 403 (`PUT /mail/signature` verlangt `mailbox_id`).
- **Verteiler/Verwaltung (nur Metadaten):** `MailListController` (`/mail/lists…`, `can:manage,MailList` = `admin ∨ mail.lists.manage`): Regel-IDs `role|rank|ems_qualification|fire_qualification|specialty` der neuen Instanz (unbekannte fallen weg), neue statische Mitglieder nur zustellbar, Adresse über `AddressRules::validate()` **in** der Schreib-Transaktion, Unique-Verletzung ⇒ 422 auf `local`. `MailAdminController` (`/settings/mail/mailboxes…`) und `MailSettingsController` (`GET|PUT /settings/mail`, auch Standard-Signatur über `saveDefault()`) hinter `can:manage,Mailbox` (`admin ∨ mail.admin`); Domain nur mit `chooseDomain` (`admin ∨ mail.domain.choose`), sonst wird der Wert ignoriert. Adressänderung: alte Adresse in `mail_address_history` (Alias). Eigenes Postfach (gebunden oder frei mit eigener Akte) nie selbst ändern/sperren/umhängen; Mitglieder nie sich selbst aufnehmen/entfernen; Konto nur an ein aktives, mit der Akte verknüpftes Konto ohne Postfach, nie das eigene; altes Konto bekommt `system`. Lösen (`DELETE …/account`) nur, wenn `MailboxAccess::bindCandidate()` null ist (sonst bände `autoBind` sofort wieder: 422, erst die Akte-Verknüpfung lösen). `MailboxPolicy`/`MailListPolicy` sind keine Inhalts-Policies (Super-Admin darf). Audit Modul `Mail` mit IDs/Adressen, nie Inhalte; keine Seite liest Nachrichten oder Zähler davon.
- **Senden:** `Mailer::send()` (Transaktion mit `Mailer::ATTEMPTS` = 3 Versuchen gegen Deadlocks, auch die Anhang-Sperren; Ereignis erst nach dem Commit, `lockForUpdate` auf die Nachricht, Sendepause `SendLimits::cooldownWait()` unter Postfachsperre, Grenzen in `SendLimits`), Empfänger über `RecipientResolver` (Adresse/Historie ⇒ Postfach, Verteiler statisch/dynamisch über Pivot, Vorrang to > cc > bcc, nur zustellbare Postfächer). `MessageSent` erst nach dem Commit; daran hängt die Glocke `MailType::notify` (Link `/mail/inbox/<id>` als Schlüssel; `MailType::markRead`/`forget` nur für das eigene Konto; DM ohne Betreff, `dm_message: false`). BCC nur über `HeaderVisibility::for()`. Drossel `mail-drafts` (30/min je Konto).
- **Inhalt (Preset `mail`):** `Content\MailContentValidator` (`validate()` streng für Entwürfe/Senden über `MailBodyGuard`, `normalize()` lenient für Renderer, Zitat, Import): Absatz, Umbruch, fett/kursiv/durchgestrichen, Link nur http/https/mailto, Zitat, Listen, Inline-Bild nur Dienstgrad-Abzeichen (`validImageSrc`: Pfad = `ranks.badge`, optional `?v=N`); ≤ 200 000 Byte, JSON-Tiefe ≤ 32, Zitate ab Tiefe 10 gekappt (`capQuotes`). Ausgabe nur `Content\MailContentRenderer::html()` (Links `target=_blank rel="noopener noreferrer"`); `search_text` = Betreff + `Content\PlainText` (auch für Entwürfe, auf 60 000 Byte gekürzt). Browser: `resources/js/editor/mailPreset.ts` + `components/mail/MailEditor.vue`.
- **Arbeitsbereich:** `InboxController`: `GET /mail[/{folder}[/{message}]]` (`?mailbox=`, Ordner `inbox|sent|drafts|archive|trash`), eine Zeile je Mail, Cursor-Seiten zu 50 (`PER_PAGE`), Vorschau aus den ersten 400 Byte `search_text`, nie `body`/roher `header`; Lesebereich per Inertia-Teil-Reload (`only: ['message']`), Text nur aus `MailContentRenderer::html()` (einziges `v-html`: `components/mail/ReadingPane.vue`; Renderer-Fehler ⇒ `broken`, kein 500). Kopie nicht im Ordner/Postfach der URL (Glockenlink `/mail/inbox/<id>`) ⇒ Weiterleitung an den echten Ordner samt `?mailbox=`. GET schreibt nichts (gelesen per `PATCH …/read`). Aktionen `PATCH /mail/messages/{message}/{folder|read|flag}`, `DELETE /mail/messages/{message}` nur auf die eigenen Kopien des gewählten Postfachs (`mailbox_id`, fremd 403), Glocke nur des handelnden Kontos; Entwurf ohne fremde Kopie wird ganz gelöscht. Zähler ungelesen je Ordner und je Postfach je eine Abfrage. `GET /mail/addressbook?q=` (je 20 Postfächer/Verteiler, `managers`-Verteiler nur mit `admin ∨ mail.lists.manage`). Die 404 des `{message}`-Bindings (läuft vor `NoStore`) setzt `NoStore::HEADERS` selbst.
- **Verfassen/Signatur:** `ComposeController` (`/mail/compose`, `…/{reply,reply-all,forward,draft}/{message}`, schreibt nichts; Antwort/Weiterleitung nur auf gesendete Mail mit Kopie im gewählten Postfach, `ReplyBuilder`), `SignatureController` (`GET|PUT /mail/signature`, je Postfach, leer = keine). `SignatureTemplate`: eigene ⇒ Standard (`mail_signatures.mailbox_id` NULL, schreiben nur über `saveDefault()`, eine Zeile unter Sperre) ⇒ `builtIn()`; Platzhalter beim Verfassen eingesetzt (Gruppe: Werte des Schreibenden, Adresse/Name der Gruppe).
- **Anhänge:** private Disk `mail-attachments` (`storage/app/private/mail-attachments`, flach `mail-<id>-<16hex>.<ext>`), nur über `Support\AttachmentStorage`: Typ per Magic Bytes (PNG/JPEG/WebP/GIF/PDF/Text, kein SVG/HTML), Endung von Datei und Namen folgt dem Typ (`nameFor`), Name ohne Steuerzeichen/Pfadtrenner, ≤ 200 Zeichen; 5 MB je Datei, 10 MB je Mail, 200 MB je Postfach (Summe aller Anhänge der Mails mit `sender_mailbox_id` = Postfach), geprüft unter Nachrichten- und dann Postfachsperre (Reihenfolge wie `Mailer::send`; nach dem Senden 409, gesendete Anhänge ändern sich nie). `absolutePath()` per `realpath` im Disk-Wurzelordner, `missing` ⇒ null. Routen (`AttachmentController`): `POST /mail/drafts/{message}/attachments`, `DELETE /mail/attachments/{mailAttachment}` (nur eigener Entwurf des gewählten Postfachs, Drossel `mail-drafts`), `GET /mail/attachments/{mailAttachment}` (Binding über `MailDelivery::visibleTo`, sonst 404; immer `attachment` mit RFC-6266-Namen, `nosniff`, `no-store`). Weiterleiten kopiert die Anhänge beim Anlegen des Entwurfs als neue Dateien (`copyAll`). `mail:cleanup` (03:30, `--dry-run`, `--prune-orphans`): Entwürfe > 30 Tage samt Dateien, Anhänge gesendeter Mails ohne nicht gelöschte Kopie (Nachricht bleibt), Datei-Waisen (unser Namensmuster, älter als 1 Tag) nur melden bzw. mit `--prune-orphans` löschen, fehlende Dateien zählen; nur der flache Wurzelordner, nie Symlinks.
- **Benachrichtigung, Suche, Zähler:** Glocke `mail` (`MailType`, Link `/mail/inbox/<id>`, Discord-DM ohne Betreff), Suchquelle `mails` (`MailSource`, nur Kopien des Kontos, nie ein roher Header) und Navigationszähler `mail` (`MailCounter`, Registry `NavigationCounters`). Die Glocke holt den Zähler per Poll alle 30 s mit; mit Echtzeit meldet der Kanal `user.{id}` (Themen `inbox`, `mail`) Änderungen und die Glocke lädt über denselben Endpunkt nach (Zähler nicht im Ereignis, Regel "nur Thema + ID").
- **Tests:** `tests/Feature/Mail/*` und `tests/Unit/Mail/*` (Wächter in `MailIntegrationTest`), `tests/Feature/MailRightsMatrixTest.php` (jede Mail-Route × Gast/ohne Recht/`mail.use`/`mail.lists.manage`/`mail.admin`/Admin/Super-Admin mit Statuscode **und** Nachzustand der Schreiber; fremde Nachrichten, Anhänge, Entwürfe und Antwort-Routen 404 für alle, auch Admin, Super-Admin und `mail.admin`; vergleicht die Routenliste mit `P7B_ROUTES`), `tests/Feature/MailIntegrationTest.php` (neue Akte ⇒ Postfach ⇒ Mail an Verteiler mit cc/bcc und Anhang ⇒ Glocke, Zähler, Suche ⇒ Antwort an alle ⇒ Download ⇒ Papierkorb/Wiederherstellen), `tests/Feature/LegacyMailUrlsTest.php`, Importer in der Gruppe `legacy` (`tests/Feature/Import/MailImportTest.php`, `MailImportRulesTest.php`, zweiter Lauf gleicher Stand), Vitest `tests/js/mail*.test.ts`.
- **Betrieb:** `upload_max_filesize` und `post_max_size` von PHP (und die Obergrenze des Webservers/Proxys) müssen mindestens 5 MB erlauben, sonst scheitert der Upload vor der Anwendung. `Cache::lock` (Sendepause, Anhangssperren) braucht einen sperrfähigen Cache-Treiber (`database`, `redis`; nicht `array`/`file` bei mehreren Prozessen).

## Suchtext der Wissensdatenbank

`KbEntry::buildSearchText()` baut `kb_entries.search_text` (Titel, Untertitel, Wirkstoff, Wirkstoffgruppe, Text von Inhalt und Abschnitten); Formular und Importer rufen es auf, jede andere Stelle, die Inhalt schreibt, muss es ebenfalls tun, sonst findet die Liste (`LIKE` auf `search_text`) und die globale Suche (Quelle `lexicon`, `KbSource`) den Eintrag nicht.

## Discord-Webhooks

Die drei Kanäle (`WebhookKind::EnotfProtocol|EnotfPrereg|FireProtocol`) sind unter Einstellungen > System > Discord hinterlegt. Ein Modul meldet ein Ereignis mit einem Aufruf, zum Beispiel in einem Listener:

```php
use App\Discord\{DiscordWebhook, Embeds, WebhookKind};

DiscordWebhook::dispatch(WebhookKind::FireProtocol, Embeds::fireProtocol([
    'id' => $incident->id, 'incident_number' => $incident->number, 'location' => $incident->location,
    'keyword' => $incident->keyword, 'started_at' => $incident->started_at, 'leader_name' => $leader,
]));
```

`Embeds::enotfProtocol(['enr', 'last_edit'])` und `Embeds::prereg(['priority' 0-2, 'arrival', 'fahrzeug', 'diagnose', 'ziel', 'enr', 'intubiert', 'kreislauf'])` bauen die anderen beiden. Die Bauer sind reine Funktionen: leere Felder werden "Unbekannt", Werte auf 1024 Zeichen gekürzt, Links nur aus `config('app.url')`. Der Job (`SendDiscordWebhook`, Queue `default`, 3 Versuche) trägt nur Art und Embed; die URL liest er erst beim Lauf aus den Einstellungen und prüft sie jedes Mal gegen die Host-Allowlist (`WebhookUrl::parse`). Ist für die Art nichts hinterlegt, passiert nichts. Das Modul darf die URL nie selbst lesen, loggen oder in Daten legen. Den Listener über `$this->listen(...)` des Modul-Providers eintragen (siehe Listener oben).

## FireTab: Besatzung, Einsätze, QM

Modul `modules/FireTab` (`/firetab`; Altsystem: Plugin `firetab`). Zugang nur mit Konto **und** Fahrzeugsitzung; QM braucht keine Sitzung.

- **CrewSession** (`App\Vehicles\CrewSession`, Kerndienst): einzige Stelle, die Besatzungsstatus liest oder schreibt, Schlüssel `crew.<Kontext>` (`crew.firetab`, `crew.enotf`). Bediener ist immer die eigene Akte (`users.personnel_id`); Super-Admin ohne Akte bekommt 403. `login()` erneuert die Sitzung mit `session()->migrate(true)` (CSRF-Token bleibt, siehe cef.md). Middleware-Alias `firetab.crew` (`EnsureCrewSession`) prüft **je Anfrage** Fahrzeug `service = 3`, einsatzbereit und Akte; **jede** Besatzungsroute muss sie tragen, weil die Policy der Sitzung vertraut. `Support\CrewVehicle::id()` ist der Auflöser für Policies und Controller.
- **Policy** `FireIncidentPolicy` (ohne Super-Admin-Bypass, `NoSuperAdminBypass`): `viewAny` Besatzung oder QM; `view` zugeordnet oder QM/Admin/Super-Admin; `update`/`finalize` zugeordnet **und** offen; `review`/`archive`/`reopen`/`viewQm` mit `fire.incident.qm`, `admin` oder Super-Admin. Fremder Einsatz 403, fremde Unterressource 404 (scoped Binding).
- **Routen:** `routes/web.php` (Gruppe `auth`, `fivem`, `NoStore`; Drosseln `firetab-write` 60/min je Konto, `firetab-status` 12/min je Fahrzeug) und `routes/api.php` (`POST /api/emd/status-poll`, API-Schlüssel, CSRF-frei). Kein POST-Dispatcher.
- **Abschluss:** `FinalizeController` ist idempotent (bedingtes Update, zweiter Aufruf 409); `FireIncidentFinalized` löst Glocke an die Einsatzleitung (`NotifyLeaderOnFinalized`, Art `fire_protocol`) und Discord (`DispatchDiscordOnFinalized`, `WebhookKind::FireProtocol`) je einmal aus. QM: Status, Archiv, Wiederöffnen (Log + Audit "Feuerwehr"), Sammel-Archivierung, leere Protokolle mit Vorschau-Token.
- **FmsStatus** (`App\Vehicles\FmsStatus`): einziger Schreibweg für `vehicles.fms_status*`; **StatusQueue** (`Support\StatusQueue`): Warteschlange für FiveM; **DispatchIncidents**, **FireSitreps**, **FireLog**, **OrganizationTime** (Wanduhr der Organisationszone ↔ UTC). Details und Aufrufregeln für den EMD-Sync im Abschnitt "EMD-Sync und Charakter".
- **Befehle:** `firetab:prune-personal-data` (Melder-/Geschädigtendaten 12 Monate nach Abschluss leeren, Einstellung `firetab.personal_data_months`, 0 = nie, `--dry-run`) und `firetab:prune-status-queue` (stündlich), beide über `schedule()` des Providers.
- **Einstellungen:** `firetab.personal_data_months`, `firetab.asu_consumption`, `firetab.asu_start_bar` (die beiden ASU-Werte nutzt 8b). Recht `fire.incident.qm`; Navigation `/firetab/qm`, Widget "Eigene Einsätze", Suchquelle, Glockenart `fire_protocol`.
- **Fahrtenbuch:** `/firetab/logbook` ruft `LogbookService::record` mit Fahrzeug und Fahrer aus der Sitzung auf; ein einmaliger Formularschlüssel (Cache, 10 min) verhindert Doppelbuchungen.
- **Tests:** `tests/Feature/FireTab/*`, `tests/Feature/FireTabRightsMatrixTest.php` (Routen × Gast, Konto ohne Sitzung, Besatzung, QM, Admin, Super-Admin mit Statuscode und Nachzustand), `tests/Feature/FireTabIntegrationTest.php`, `tests/Feature/Import/FireTabImportTest.php` (Gruppe `legacy`).

## FireTab: Atemschutzüberwachung

Tab `atemschutz` der Einsatzseite (`components/firetab/asu/Board.vue`), lesend auch in der QM-Ansicht. Gespeichert wird nur, was passiert ist; den Zustand rechnet `AsuState`.

- **Tabellen:** `fire_asu_trupps` (Rolle `angriff|sicherheit`, Soll-Minuten, Fahrzeug der anlegenden Besatzung), `fire_asu_members` (Platz `tf|tm1|tm2`, Unique (Trupp, Platz)), `fire_asu_events` (Typen `AsuEventType`; `occurred_at` und `received_at` als `dateTime`, `voided_at`/`voided_by`; Unique (Einsatz, `client_uuid`)), `fire_asu_protocols` (Alt-JSON je Überwacher).
- **Routen** (`/firetab/incidents/{incident}/asu/*`, `firetab.crew`, scoped Binding, Drossel `firetab-asu` 120/min je Konto): `GET state` (`since`, `trupps_etag`, Serverzeit `now`, `writable`, alle annullierten IDs), `POST/PUT/DELETE trupps`, `POST events`, `POST events/{id}/void`. Lesen Policy `view`, Schreiben `update` (zugeordnet und offen; Super-Admin ohne Bypass). QM liest über `GET /firetab/qm/incidents/{incident}/asu/state`.
- **Ereignisannahme** (`Support\AsuRecorder`): Trupp unter Zeilensperre, `insertOrIgnore` auf die Unique ⇒ Doppelsendung = dieselbe ID (`duplicate: true`). Zeit (`Support\AsuClock`): Gerätezeit gilt, wenn `received_at − 15 min ≤ occurred_at ≤ received_at + 5 s`, sonst `received_at`. `angeschlossen`, `draussen`, `mayday`, `mayday_ende` gehen ins Einsatzlog (`asu_<typ>`). Annullieren ist idempotent; ein Trupp mit gültigen Ereignissen lässt sich nicht löschen (422).
- **Zustand:** `resources/js/lib/asuState.ts` (Browser) und `Support\AsuState` (PHP, für den Bericht) auf derselben Fixture `tests/Fixtures/asu-cases.json`; Rückzugsdruck 2×(Anschluss − Ziel), niedrigster Druck maßgeblich, Drittel-Hinweise, Mayday-Vorrang.
- **Client** (`resources/js/lib/asuClient.ts`): Ereignisse und Annullierungen laufen über eine Warteschlange in `localStorage` (seriell, Backoff bis 30 s); 5xx, Netzfehler, 408 und 429 bleiben in der Schlange, **401/419 (Sitzung oder XSRF abgelaufen) ebenfalls**, dazu ein dauerhafter Hinweis `Sitzung abgelaufen – bitte neu laden/anmelden` (`state.authExpired`); nur andere 4xx werden mit Hinweis verworfen. Trupp-Änderungen gehen direkt (nicht offline). Uhrabgleich `offset = server.now − Mitte(Anfrage)`, Polling 3 s, mit Echtzeit 60 s plus Puls `asu` (`pollMs` ist ein Getter, `restart()` setzt die Schleife sofort zurück).
- **Alt-Sync:** `POST /api/asu/sync` und `/api/asu-sync` (API-Schlüssel) upserten je (Einsatz, Überwacher) in `fire_asu_protocols` (`AsuSyncController`).
- **Tests:** `tests/Feature/FireTab/Asu*Test.php`, `tests/js/asuClient.test.ts`, `tests/js/asuState.test.ts`, `tests/Feature/FireTabAsuRightsMatrixTest.php`, `tests/Feature/FireTabAsuIntegrationTest.php`, `tests/Feature/Import/FireTabAsuImportTest.php` (Gruppe `legacy`).

## FireTab: Lagekarte

Eigene Seite `FireTab/Map` (`Pages/FireTab/Map.vue`, Leaflet 1.9.4 mit `CRS.Simple`, `lib/leafletMap.ts`); der Tab `lagekarte` der Einsatzseite und die QM-Ansicht verlinken darauf. Kacheln statisch unter `public/assets/map` ([map-tiles.md](map-tiles.md)), Umrechnung GTA ↔ Karten-Prozent in `App\Support\MapCoordinates`.

- **Tabellen:** `fire_map_markers` (`marker_type` aus `FireMapMarker::TYPES`, sonst `custom`; `vehicle_<id>` nur für ein Fahrzeug am Einsatz; Position in Prozent 0 bis 100 mit 2 Nachkommastellen; Felder des taktischen Zeichens gegen `App\Vehicles\TacticalSymbol`) und `fire_map_zones` (Polygon `points` als JSON-Liste `{x,y}`, Farbe aus `FireMapZone::COLORS`, `is_valid` = false für importierte unbrauchbare Formen: bleiben gespeichert, werden nie gezeichnet). Konto (`created_by_user_id`), Akte (`personnel_id`) und Fahrzeug sind getrennte Fremdschlüssel und kommen aus der Sitzung, nie aus der Eingabe.
- **Routen** (`/firetab/incidents/{incident}/map/*`, `firetab.crew`, scoped Binding, Schreiben mit Drossel `firetab-write`): `GET /` (Inertia, `markers`/`zones` als Lazy-Props), `POST markers`, `PATCH markers/{id}` (nur Position), `DELETE markers/{id}`, `POST zones`, `DELETE zones/{id}`, `PUT location` (Einsatzort-Marker aus den GTA-Koordinaten; ein zweiter Aufruf verschiebt den ersten Einsatzort-Marker). QM liest über `GET /firetab/qm/incidents/{incident}/map` (ohne Sitzung, nie schreibend).
- **Rechte:** Lesen Policy `view`, Anlegen/Verschieben/Einsatzort `update` (zugeordnet und offen), Löschen `deleteMapItem` (offen und entweder echtes `admin`/`fire.incident.qm` oder eigener Eintrag (Konto oder Akte) der zugeordneten Besatzung; Super-Admin ohne Bypass). Jede Änderung schreibt das Einsatzprotokoll (`marker_*`, `zone_*`) und feuert nach dem Commit `MapChanged` (noch kein Broadcast).
- **Aktualisierung:** Teil-Reload alle 15 s (`router.reload({only: ['markers', 'zones']})`), mit Echtzeit 60 s plus Puls `map`.
- **Tests:** `tests/Feature/FireTab/MapTest.php`, `tests/Feature/FireTab/MapTilesTest.php`, `tests/js/leafletMap.test.ts`, `tests/Feature/FireTabMapRightsMatrixTest.php`, `tests/Feature/FireTabMapIntegrationTest.php`, `tests/Feature/Import/FireTabMapImportTest.php` (Gruppe `legacy`).

## EMD-Sync und Charakter

- **Endpunkt** `POST /api/emd/sync` (ef_bridge `server/emd_sync.lua`, einziger Aufrufer; `.php` über `NormalizeLegacyPath`, Alias `/api/emd-sync` über `LegacyUrlMap`): globale Middleware `EmdBodyLimit` nur für diesen Pfad (2 MB, JSON-Tiefe 32, **vor** TrimStrings/ConvertEmptyStringsToNull und `api.key`, die den Body dekodieren), `api.key` (Schlüssel im Body `intraRP_API_Key`), `throttle:emd-sync` (120/min, ein Systemschlüssel). `EmdSyncController` → `App\Emd\EmdSync::run(EmdPayload)` unter **einer** globalen Sperre `Cache::lock('emd-sync', 30)->block(10)` (sonst 503). Kein JSON-Objekt 400; jede Exception 500 **ohne** Text, Log nur die Klasse. Stateless `api`-Gruppe: keine Sitzung, kein Cookie zählt.
- **`EmdPayload::fromRequest`** normalisiert V1/V2: `dispatch_data`→`data`, `fallback_statuses` als Liste **oder** `{entries}`, `lagemeldungen` als Map **oder** `{einsaetze}` (das Altsystem verstand jeweils nur eine Form).
- **Ablauf:** Register-Zweig (`vehicle_registry` → `VehicleRegistryIntake::accept`, Kurzantwort; `too_many_rows` → Anforderung löschen, Glocke an `vehicle.manageImport`, 422) · Einsätze → Status → Fallback-Status · Rückkanäle · Antwort (`situation_reports`/`patient_updates` als Objekte, leere Teile weggelassen, `status_poll`/`status_ack` nur bei `protocol_version = 2`). `emd.last_sync_at` höchstens einmal je Minute.
- **Einsätze:** Fahrzeuge einmal geladen (erste Zeile je Name nach `id`). Je Einsatznummer in einer Transaktion: Rettungsdienst (Service 1/2, Modul `enotf`) → `Modules\Enotf\Support\DispatchProtocols::ensure` (Protokolle `N`, `N_1`, …, `origin = dispatch`, Slot Transport/NA, Patient aus `patienten[0]`, aktive Besatzung als Schnappschuss über `ProtocolCreator::copyCrewOfVehicle`); Feuerwehr (Service 3, Modul `firetab`) → `DispatchIncidents::ensure` (abgeschlossen/archiviert unberührt). Mischeinsatz RTW + NEF + LHF ⇒ zwei Protokolle, ein FW-Einsatz nur mit dem LHF. Lagemeldungen beider Herkünfte → `FireSitreps::importDispatchSitrep(…, $important)`.
- **Status:** Einsatzstatus `{id, mission_number, sender, status, timestamp}` (Wanduhr der Organisationszone → UTC): RD-Fahrzeug → `DispatchProtocols::applyStatus` (`C` → `salarm`, `1/2/3/4/7/8` → `s<n>`, nur offene, nicht ausgeblendete Protokolle der Basis-ENR, über `ProtocolMerger`, Revision steigt); FW-Fahrzeug → `DispatchIncidents::applyStatus` (`fire_incident_vehicles.fms_status/_at`). **FMS-Quelle `dispatch`:** jeder gültige Code setzt zusätzlich `FmsStatus::set($vehicle, $code, 'dispatch', $at)` und geht **nie** in die `StatusQueue` (sonst Echo an FiveM). `status_ack.successful_ids`: Ziel gefunden oder schon gleicher Wert; fehlendes Protokoll/Einsatzfahrzeug/Fahrzeug ⇒ **nicht** quittiert, ef_bridge sendet erneut. Fallback-Status → `FmsStatus::set(…, 'no_dispatch')`. V1-Typen `dispatch_logs`/`status_updates`/`status_no_dispatch` über dieselben Wege.
- **Rückkanäle (at-most-once, wie Alt):** `situation_reports` je FW-Einsatz dieses Syncs (`FireSitreps::pendingFor` + `markSynced` in **einer** Transaktion; `pendingFor` wirft außerhalb); `patient_updates` aus `DispatchProtocols::pendingPatients(100)` (`pending → synced` bedingt); `status_changes` + `status_poll` aus `StatusQueue::deliver(100)`. Eine verlorene Antwort verliert diese Zeilen.
- **Fall 3:** `POST /enotf/protocols` hängt eine fremde ENR nur an ein offenes Dispatch-Protokoll, wenn das eigene Fahrzeug ein Dispatch-Protokoll derselben Basis-ENR hat; sonst 409.
- **Charakter:** Tabelle `fivem_characters` (`App\Models\FiveMCharacter`, `MassPrunable` nach 24 h über `model:prune`). Shared Prop `fivem.identifyToken`: nur angemeldet und mit CitizenFX-User-Agent; 40 Zeichen, gespeichert nur als SHA-256, Klartext nur in der Sitzung (`fivem_identify_token`; damit an Konto + Sitzung gebunden und über den Sitzungs-ID-Wechsel des Fahrzeuglogins hinweg gültig). Wiederverwendet, solange einlösbar (10 min) oder verknüpft (12 h). `TabletLayout`/`AppLayout` senden ihn per `useFiveMIdentify()` als `{type: 'ignis_session', session_id}`. `POST /api/character/identify` (`api.key`, `throttle:character-identify` 120/min): bedingtes UPDATE, gleiche Daten 200, andere 409, unbekannt/abgelaufen 404, leer 422 (ef_bridge-Verbindungstest). `GET|POST /api/character/get-session-id` 410. `FiveMCharacter::current()` liest den Charakter; `CrewSession::vehicles()` filtert mit `enotf.job_filter` auf `allowed_jobs` leer oder enthält den Job, `CrewSession::login()` verlangt mit `enotf.char_lock` den normalisierten Namen der eigenen Akte (422 `crew.char_mismatch`); beides für eNOTF und fireTab, ohne Charakter ohne Wirkung. Keine Logs mit Token oder Charaktername.
- **Tests:** `tests/Feature/Emd/*` (Mischeinsatz-Regression zuerst, Status, Rückkanal), `tests/Feature/CharacterIdentifyTest.php`, `tests/Feature/EmdSyncRightsMatrixTest.php`, `tests/Feature/EmdSyncIntegrationTest.php`, Fixtures `tests/Fixtures/emd/`.

Dienste dafür (FireTab, seit 8a):

- `App\Vehicles\FmsStatus::set(Vehicle, $code, $source, $at = null)`: Codes `0-9, N, #, C`; setzt `fms_status`, `fms_status_at`, `fms_status_source` (`manual`, `no_dispatch`, `dispatch` = EMD-Einsatzstatus, nie zurück in die `StatusQueue`, `incident`). Status je Einsatzfahrzeug (`fire_incident_vehicles.fms_status/_at`) schreibt `DispatchIncidents::applyStatus`.
- `Modules\FireTab\Support\StatusQueue::claim(100)` / `markDelivered($token)`: at-most-once, genau ein Verbraucher je Zeile (bedingtes `UPDATE … WHERE claim_token IS NULL`). Abgeholte, nie zugestellte Zeilen werden nicht erneut ausgegeben; `firetab:prune-status-queue` (stündlich) löscht nicht zugestellte nach 1 h, zugestellte nach 7 Tagen. Antwortformat wie `POST /api/emd/status-poll` (`vehicle_name`, `status`, `incident_number`, `timestamp` als `d.m.Y H:i` in der Organisationszone).
- `DispatchIncidents::ensure(['number', 'vehicles' => [Namen], 'postal', 'location_x', 'location_y', 'dispatch_code', 'dispatch_issue', 'caller_name', 'caller_phonenumber'])`: legt den Einsatz bei unbekannter Nummer an (Platzhalter `BITTE ÄNDERN!`, Notiz `EINSATZMELDUNG: …`), sonst hängt sie nur neue Fahrzeuge an, entfernt nie. Nur Fahrzeuge mit `service = 3` zählen (Mischeinsatz RTW + NEF + LHF ⇒ nur LHF); ohne Feuerwehrfahrzeug `null`. Log ohne Konto (`FireLog::record(..., system: true)`).
- `FireSitreps::pendingFor($incident)` (lokal, `synced_at` leer, `FOR UPDATE`) und `markSynced($ids)` **in einer** `DB::transaction` aufrufen; `importDispatchSitrep($incident, $text, $atUtc, $important)` verwirft gleichen Text im Fenster ±15 min, speichert `source = dispatch`, `synced_at = now`.

## eNOTF: Protokollkern

Modul `modules/Enotf` (`/enotf`, Gruppe `rettung`; Altsystem: Plugin `enotf`). Konto immer Pflicht, Tablet-Einstieg über `/auth/tablet?token=…&to=enotf` (cef.md).

- **Besatzung:** `CrewSession::for('enotf')` (Kontext `crew.enotf`, Fahrzeuge `service` 1/2), Middleware `crew:enotf` auf **jeder** Besatzungsroute. Login: Fahrer = eigene Akte, weitere Positionen nur aus der Akten-Suche (kein Freitext), Tabellen `enotf_crews`/`enotf_crew_members` (Beitreten/Beenden siehe "eNOTF: Geräte und Sitzungen"). Eine offene Besatzung je Person (12 h verwaist): nur der Fahrersitz blockiert, wer nur mitgenannt ist, verlässt die andere Besatzung beim eigenen Login. Policies lesen über `CrewContext` nur eine noch gültige Sitzung.
- **Protokoll** (`enotf_protocols`, hybrid): Kopfspalten (ENR 64 unique, `status` open|released|withdrawn, `origin` dispatch|manual|unknown, `qm_status` 0 bis 4, `hidden_at`, `released_at`, `withdrawn_at`, `billed_at`, Fahrzeug-FK + Label je Slot, Patient, Einsatzdatum/-zeit, Ziel), `data` JSON unter den **Alt-Spaltennamen** (`Support\FieldSchema`: einzige Allowlist, Typ, Katalog, Abschnitt), `legacy` JSON (nur Import), `enotf_protocol_crew` (Slot, Position 1 bis 3, Akte oder Alt-Text). Zeiten `dateTime` UTC; Statuszeiten als ISO-UTC in `data`.
- **Policy** `EnotfProtocolPolicy` (`NoSuperAdminBypass`): Besatzung liest offene/freigegebene des eigenen Fahrzeugs (oder per Akte genannte), schreibt nur offene; `edivi.view`/`edivi.edit`/`admin`/Super-Admin lesen (QM); Korrektur, Ausblenden, Freigabe-Übersteuerung nur mit echtem `edivi.edit`/`admin`; Super-Admin schreibt nie. Zurückziehen nie bei `origin = dispatch`. Show/PATCH/Druck liegen außerhalb von `crew:enotf` (QM liest); die PIN-Sperre (`EnsurePinUnlocked`) liegt auf der ganzen Gruppe `/enotf`.
- **Autosave:** `PATCH /enotf/p/{enr}` `{revision, fields}` → `ProtocolMerger` unter `lockForUpdate` (feldweise Revision `field_revisions`, Antwort `{revision, applied, conflicts, changes_since}`; 409 wenn nicht offen, 413/422 bei Größe/Typ), Drossel 120/min.
- **Plausibilität:** `Support\ConditionsService` (Port, 35 Regeln) und `resources/js/lib/enotfConditions.ts` auf der Fixture `tests/Fixtures/enotf-conditions.json`. `ProtocolValues` liefert die Eingabe; `medis` sind die aktiven Zeilen aus `enotf_medications_given`, keine Zeilen zählen nur mit `medis_keine` (siehe "eNOTF: Fachdaten").
- **Freigabe:** `POST /enotf/p/{enr}/release` atomar unter Sperre, zweiter Aufruf 409; `ProtocolReleased` nach Commit → Glocke (Art `protokoll`) an die Besatzungskonten außer dem Freigebenden, Discord `WebhookKind::EnotfProtocol` ohne Patientendaten. Druck `GET /enotf/print/{enr}` (Policy `print`).
- **QM/Admin:** `/enotf/admin` (Policy `viewQm`), `POST /enotf/admin/hide`, `GET/POST /enotf/admin/empty` (Vorschau + Token); Kategorien/Quicklinks `/settings/enotf/*` mit Recht `enotf.settings`.
- **Kern-Haken:** Suchquelle `enotf-protocols` (nur QM, ohne Patientendaten), Widget "Eigene Protokolle", Hinweis offene Protokolle, `/me/protocols` (`App\Support\OwnProtocols`, Module registrieren sich), Fahrtenbuch über `LogbookService::record`, Mängelmeldung über den Mängeldienst.
- **Befehl:** `enotf:prune {--dry-run}` (täglich 03:30): Name/Geburtsdatum `enotf.personal_data_months` (Std. 12, 0 = nie) nach der Freigabe (zurückgezogene: nach dem Zurückziehen) leeren, auch die Patientenschlüssel `patname`/`pat_vorname`/`pat_nachname`/`patgebdat` in `legacy`; Audit nur Zähler.
- **Einstellungen:** `enotf.pin_enabled`, `enotf.pin_timeout`, `enotf.prereg_enabled`, `enotf.char_lock`, `enotf.job_filter`, `enotf.bz_unit`, `enotf.personal_data_months`.
- **Oberfläche:** jede der sieben Seiten ist eine eigene Komponente `components/enotf/pages/*.vue` im Alt-Layout (Kacheln, Themen/Schritte, Fokusansichten, Körperschema, GCS, Rea-Baum, Zugang-Ablauf); die generische `ProtocolSection.vue`/`EnotfField.vue` gibt es nicht mehr (Abschnitt "eNOTF: Protokoll-Oberfläche").
- **Tests:** `tests/Feature/Enotf/*`, `tests/Feature/EnotfProtocolRightsMatrixTest.php` (Hauptrouten × Gast, Konto ohne Sitzung, Besatzung eigenes/fremdes Fahrzeug, QM, Admin, Super-Admin × offen/freigegeben mit Code und Nachzustand), `tests/Feature/EnotfProtocolIntegrationTest.php`, Vitest `tests/js/enotf*.test.ts`, Import `tests/Feature/Import/EnotfImportTest.php` (Gruppe `legacy`).

## eNOTF: Fachdaten

Aufbau auf dem Protokollkern (Policy, `WritesProtocolRows`, Autosave). **Kindtabellen statt JSON:** jede Eingabe ist eine Zeile, zwei Geräte gleichzeitig ergeben zwei Zeilen, nie ein Überschreiben; Löschen ist Soft-Delete mit Wer/Wann.

- **Vitalwerte** `enotf_vitals` (`parameter` = Code aus `Support\VitalparameterCatalog`, `value` decimal oder `value_text`, `unit`, `measured_at`, `deleted_at`/`deleted_by_user_id`, `legacy`): `GET/POST/DELETE /enotf/p/{enr}/vitals[/{vital}]` (`scopeBindings`, fremde Zeile 404). Serverzeit, außer manueller Wanduhr (nicht in der Zukunft). "ng"/"nm" als Text, **nie 0**; Blutzucker gespeichert in mg/dl, Anzeige nach `enotf.bz_unit`. UI `components/enotf/vitals/{Keypad,History,Chart}.vue` (Diagramm als SVG ohne Bibliothek), Farbstufen in `resources/js/lib/enotfVitals.ts`.
- **Medikamente:** Katalog `enotf_medications` (47 Seeds in der Migration, Pflege `/settings/medications` mit Recht `enotf.settings`); Gaben `enotf_medications_given` (`medication_id` oder nur Name, Dosis, Einheit, Zugang, `given_at`): `GET/POST/DELETE /enotf/p/{enr}/medications[/{medication}]`, nur aktive Katalogeinträge, Einheiten/Zugänge aus dem Altkatalog (`MedicationGiven::UNITS|ROUTES`).
- **"Keine Medikamente gegeben":** Formularfeld `medis_keine` (bool, Abschnitt `massnahmen`, `data`), in `MedicationsSection.vue` neben den Zeilen. Die Plausibilitätsregel `medis` ist erfüllt durch **aktive Zeilen oder `medis_keine = true`** (Alt `'0'`); eine leere Liste allein nicht. Umsetzung in `ConditionsService::legacy()` und dem TS-Zwilling, Fälle E9 bis E12 der Fixture.
- **Schreibweg:** Kindzeilen nur bei offenem Protokoll unter dessen Zeilensperre, `update`-Policy auf der gesperrten Zeile (Besatzung des Fahrzeugs; QM/Super-Admin lesen nur). Die Routen liegen außerhalb von `crew:enotf` (QM liest); die PIN-Sperre (`EnsurePinUnlocked`) liegt auf der ganzen Gruppe `/enotf`.
- **POIs/Ziele:** `pois.legacy_identifier` (eindeutig), `Poi::hospitals()` (`Krankenhaus` und `Klinik`; MANV nutzt es), Pflege `/settings/pois` (Gates `poi.view`/`poi.manage`, Rechte `pois.*`). Suche `GET /enotf/pois/search?q=` (`crew:enotf`, max. 20 aktive), Adressen `PUT /enotf/p/{enr}/address/{transport|target}` (validiertes JSON oder POI; POI setzt `destination_poi_id`, kopiert die Adresse, leert `destination_code`); ein gewählter POI zählt in der Plausibilität wie Alt-`poi_<id>` (Code 0, keine 2/21/22-Zusätze).
- **Tests:** `tests/Feature/Enotf/*` (Vitals, Medications, PoiAddress, Lifecycle), `tests/Feature/EnotfClinicalDataRightsMatrixTest.php` (Kindzeilen/Adresse/POI-Suche/Katalog × Gast, ohne Sitzung, Besatzung eigenes/fremdes Fahrzeug, QM, Super-Admin mit Code und Nachzustand), Vitest `tests/js/enotfVitals.test.ts`, `enotfConditions.test.ts`, Import Gruppe `legacy`.

## eNOTF: Geräte und Sitzungen

Aufbau auf Protokollkern und Fachdaten. Zustandsänderungen sind bedingte `UPDATE … WHERE`-Schritte oder laufen unter Zeilensperre, nie check-then-act.

- **Besatzung** (`CrewService`, `CrewController`): `POST /enotf/crew/join|leave|end`; Besatzung = Akten (Position 1 bis 3), eine aktive Besatzung je Person (`active_key`, unique), besetzte Position 422. Außerdienst des Fahrzeugs beendet die Besatzung (`VehicleOutOfService` → `EndCrewsOutOfService`); `enotf:prune` (täglich) beendet Besatzungen nach 12 h ohne Aktivität.
- **PIN-Sperre** (`EnsurePinUnlocked`, `LockController`): Sitzungs-PIN je Besatzung (`Hash::make`, Pflicht beim Login/Beitritt solange `enotf.pin_enabled`), Sperre nach `enotf.pin_timeout` Minuten ohne Eingabe (`POST /enotf/pin-activity`) oder von Hand; Web → Redirect `/enotf/lockscreen` (Rücksprung nach dem Entsperren), JSON → **423** `{locked, redirect}`. Die Middleware liegt auf **allen** Routen des Moduls (auch Show/PATCH/Druck/Kindzeilen außerhalb von `crew:enotf`); Ausnahmen: Login/Logout, Sperrbild, `POST /enotf/unlock` (10/min je Besatzung), `GET /enotf/status`. 5 falsche PINs (atomarer Zähler) melden die Besatzung ab (`ended_reason = pin_failed`), die Meldung verrät nie, was falsch war. Wiederanmeldung während der Sperre ist möglich (PIN = Gerätesperre, keine zweite Anmeldung).
- **Geräteabgleich:** `GET /enotf/status` (alle 10 s, pausiert bei unsichtbarem Tab) liefert Besatzung, Sperre, Revision je offenem Protokoll und offene Teilanfragen; neue Revision → `GET /enotf/p/{enr}/changes?since=` (Policy `view`, PIN) holt die fremden Änderungen, der Client übernimmt sie; ein 409 beim Autosave lässt ungespeicherte Eingaben sichtbar ("nicht gespeichert"). `POST /enotf/p/{enr}/patient-sync`: `none → pending` (bedingtes UPDATE, Name nötig, Policy `update`), Abnehmer ist der EMD-Sync.
- **Teilen** (`ShareService`, `ShareController`, `enotf_share_requests`): `POST /enotf/p/{enr}/share` (20/min, Ziel = anderes Fahrzeug mit aktiver Besatzung, Ablauf 30 min), `…/share/{id}/accept|reject|cancel`. Zustandsautomat `pending → accepted|rejected|cancelled|expired` per bedingtem UPDATE (zwei Annahmen: genau eine, die zweite 409); Annehmen/Ablehnen nur vom Zielfahrzeug, Zurücknehmen nur vom Absender, fremde IDs 404/403. Annahme in **einer** Transaktion: `ProtocolMerger` füllt nur **leere** Felder des **eigenen offenen** Protokolls (fremdes 403) mit der Allowlist `SHARE_FIELDS` (Abschnitte 2 bis 5, `eart`, `transp_poi`, `transp_adresse`; nie `medis_keine`, Vitalwerte-/Medikamentenzeilen, Ziel, Abschluss), ohne Ziel-ENR entsteht ein neues Protokoll (nächste freie `_n`-ENR). `enotf:share-expire` markiert Abgelaufene.
- **Import:** Sitzungen und Teilanfragen des Altsystems werden nicht importiert, nur gezählt (import.md, Teil 3).
- **Tests:** `tests/Feature/Enotf/{CrewLifecycle,PinLock,DeviceSync,Share}Test.php`, `tests/Feature/EnotfDevicesRightsMatrixTest.php` (Status/Änderungen/Patientenabgleich/Teilen/Beenden × Gast, Konto ohne Sitzung, Besatzung eigen/gesperrt/fremd, QM, Super-Admin mit Code und Nachzustand), `tests/Feature/EnotfDevicesIntegrationTest.php`, Import Gruppe `legacy`.

## eNOTF: Klinik, QM, Abrechnung

Aufbau auf den Abschnitten oben. Patientendaten sehen nur Besatzung, QM und die per Klinikcode gebundene Klinik; Audit "eNOTF" ohne Patientendaten. Echtzeit: Fahrzeugkanal `enotf.vehicle.{id}` (Abschnitt "Echtzeit"); Klinikportal und Ankunftstafel bleiben Polling (Gerät ohne Konto).

- **Abrechnung** (`BillingController`): `POST /api/enotf/billing` (ef_bridge postet `api/enotf/billing.php`, `NormalizeLegacyPath` entfernt `.php`; Alias `/api/enotf-billing`), Middleware `api.key` (konstantzeitig, Schlüssel im Body `intraRP_API_Key` oder Header), Drossel 6/min, keine Sitzung. Vertrag unverändert: `{timestamp}` → `{success, count, protocols[{name, birthdate, transport, missionNumber, protocolType, vehicleCallsign}]}`, 400/403/500 wie Alt. Auswahl (freigegeben, nicht ausgeblendet/zurückgezogen, `billed_at` leer, angelegt bis `timestamp`, max. 500) und Markierung `billed_at`/`billing_batch` (ULID) in **einer** Transaktion; zwei parallele Abrufe liefern disjunkte Mengen. At-most-once (ef_bridge bestätigt nicht). `transport` aus dem Ziel (Codes 2/21/22 oder Klinik-POI). Importierte `billing_sent` werden nie erneut geliefert.
- **QM** (`QmController`): `GET /enotf/qm/{enr}` (Policy `viewQm`), `PATCH …/status` (0 bis 4), `POST …/comments`, `POST …/reopen` (Policy `review` = echtes `edivi.edit`/`admin`, nie Super-Admin). Rücknahme nur freigegeben **und** nicht abgerechnet, unter Zeilensperre (sonst 409). Log `enotf_review_log` (`kind` status|comment|reopen, from/to, Text ohne HTML, Konto + Namensschnappschuss); Glocke an die Besatzungskonten über `enotf_protocol_crew.personnel_id → users.personnel_id`. Alt-URLs `/enotf/qm/{actions,log}/{id}` 301 über `IdMap enotf_protocol`.
- **Kliniken**: `enotf_hospital_departments` (Verfügbarkeit als Spalten `status` not_staffed|available|partially_available|full, `status_updated_at`, `status_updated_by`, `note`), Pflege `/settings/pois/{poi}/departments` (`poi.manage`); Zugangscode `enotf_hospital_access_codes` (nur `code_hash`), `POST/DELETE /settings/pois/{poi}/access-code`: 16 Zeichen serverseitig, **einmal** in der JSON-Antwort, Rotation ersetzt den Hash und meldet alle Geräte ab. Portal `GET/POST /enotf/schnittstelle/login` (Klinik wählen + Code, 5/min je IP, neue Sitzungs-ID, Abmelden per POST), `GET /enotf/schnittstelle/availability`, `PUT …/availability/{department}` nur Abteilungen der Sitzungsklinik (fremde 404). Sitzungsschlüssel `enotf_hospital` = POI + Fingerabdruck des Hashes. Besatzungssicht `GET /enotf/hospitals` (nur lesen).
- **Klinikcode** (`ClinicCodeController`): `POST /enotf/p/{enr}/clinic-code` (Policy `handover`: Besatzung des Fahrzeugs, offen oder freigegeben, nicht ausgeblendet, nie Super-Admin) erzeugt `<id>-<10 Zeichen>` (gehasht, 1 h, ersetzt alte Codes des Protokolls). Einlösen `GET/POST /enotf/schnittstelle/klinikcode` (10/min je IP, gleiche Antwort für falsche ID/Geheimnis), danach `GET /enotf/schnittstelle/protocol` liest **nur** dieses Protokoll (Druckansicht), kein PIN-Bypass, kein Konto.
- **Voranmeldung und Tafel** (`PreregistrationController`): `GET/POST /enotf/p/{enr}/prereg` (+ `DELETE …/{prereg}`, Policy `handover`, Einstellung `enotf.prereg_enabled`), Discord `EnotfPrereg` ohne Diagnose/Patientendaten. Tafel `GET /enotf/schnittstelle` + Poll `GET /enotf/schnittstelle/prereg` nur für das per Zugangscode angemeldete Klinikgerät; GET ändert nie Daten, `PUT …/prereg/{id}` markiert gesehen/angekommen. Aus der Tafel 60 min nach der Ankunftszeit, `enotf:prune` löscht nach 24 h und abgelaufene Klinikcodes.
- **Lesezugriff ohne Sitzung:** Policy `view`/`print` erlaubt einem Konto, dessen Akte in der Besatzung eines **freigegebenen**, nicht ausgeblendeten Protokolls steht, das Lesen ohne Besatzungssitzung (Links aus Widget, Glocke, `/me/protocols`). Offene Protokolle brauchen weiter die Sitzung des Fahrzeugs; eine gesperrte Sitzung fängt `EnsurePinUnlocked`; Schreibrechte unverändert.
- **Alt-API:** übrige `/api/enotf/*`, `/api/klinik/*`, `/api/hospital*`, `/api/generate-klinikcode`, `/api/pois/departments-sort` → 410.
- **Import:** QM-Log, Abteilungen/Verfügbarkeit, Zugangscodes (gehasht); Klinikcodes/Voranmeldungen nicht (import.md, Teil 4).
- **Tests:** `tests/Feature/Enotf/{Billing,Qm,HospitalPortal,ClinicHandover,ProtocolPolicy,LegacyEnotfUrls}Test.php`, `tests/Feature/EnotfClinicBillingRightsMatrixTest.php` (QM/Portal/Klinikcode/Tafel/Übergabe × Gast, Konto ohne Sitzung, Besatzung eigen/fremd, QM, Super-Admin, Klinikgerät, Klinikcode mit Code und Nachzustand; Abrechnung mit/ohne Schlüssel), `tests/Feature/EnotfClinicBillingIntegrationTest.php`, Import Gruppe `legacy`.

## eNOTF: Protokoll-Oberfläche

Das Protokoll (`/enotf/p/{enr}/{seite}`) sieht aus und bedient sich wie das Alt-Tablet. Daten und Speicherwege sind dieselben wie in den Abschnitten oben: Widgets kennen nur Schlüssel, Typen/Kataloge/Grenzen/Regeln kommen aus `FieldSchema`, `enotfCatalogs.json`, `enotfConditions.ts`.

- **Aufbau:** `Pages/Enotf/Protocol.vue` hält Zustand und Datenfluss (Autosave, Geräteabgleich, `medRows`/`vitalRows`, Freigabe, Teilen, Patientenabgleich) und stellt `provide('enotfForm', { values, set, flush, readonly, invalid, conflicts, clock, timezone })` sowie `provide('enotfWizard')` bereit. `components/enotf/ProtocolShell.vue` = Kopfleiste (Start, Voranmeldung, Art ändern, Teilen, Druck, QM, Besatzung, Statuscluster Leitstelle/Patientenabgleich/Autosave, Serveruhr), Abschnittsleiste mit Nummer und Füllstandsstreifen, Hinweise, `chromeless` für Fokusansichten. Je Seite eine Datei `components/enotf/pages/{Rettdaten,Erstbefund,Anamnese,Diagnose,Verlauf,Massnahmen,Abschluss}.vue` (Themen, Schritte, Spalten als Konstanten).
- **Widget-Bibliothek** `components/enotf/ui/`: `TileColumn` (Radio/Mehrfach, `exclusive`, `green`, erneuter Tipp leert), `TileLink` (Themen-/Unterthemenkachel mit Pflichtstreifen, `covers`), `TileToggle` (bool), `SummaryBox` (Übersichtsbox, ganz antippbar), `QuickFill` (mehrere Felder in einem Tick = ein PATCH), `GcsPicker`, `BodyMap` (SVG-Pfad gebunden, `clip-path` mit absoluter URL + ID je Instanz, schreibt beim Ansehen nichts), `NumberPad` (Bereichsleiste), `MaskedDate`/`MaskedTime` (Text + `inputmode=numeric`), `StatusTime` (leer antippen = Serverzeit), `SelectSheet`/`Suggest` (Panel per `Teleport` + `position:fixed`, ersetzt `<select>`/`<datalist>`), `TriState`, `TextBlocks`, `ReaTree`, `FocusFooter`. Reine Logik (`tileState`, `pageStatus`, `toggleExclusive`, `parseDate`, `nowWall`, `gcsSum`, `severityWrites`, `zugangToggle`, `medTime`, `reaPanel`, `releaseBlock`, `diagSearch`, …) in `resources/js/lib/enotfUi.ts`. **Keine nativen Popups** (`<select>`, `type=date|time|datetime-local`, `<datalist>`) und kein `v-html` in `components/enotf` und `Protocol.vue` (CEF/DUI, `docs/dev/cef.md`).
- **Platzierung** `resources/js/lib/enotfLayout.json`: `pages[]` (`slug`, `label`, `keys` in Alt-Reihenfolge), `pseudo` (`medis`, `rea_details`), `unplaced` (Schlüssel ⇒ Begründung), `diagnose_tree` (Kategoriebaum der Diagnose). Daraus kommen Füllstände (`pageStatus`), Sprung aus der Plausibilität (`pageOf`) und der **Vertragstest** `tests/Feature/Enotf/LayoutContractTest.php`: jeder `FieldSchema::keys()` genau einmal platziert (oder begründet in `unplaced`), jeder platzierte Schlüssel ∈ Schema ∪ `pseudo`, der Diagnosebaum deckt genau die `diagnose`-Codes.
- **Fokus-URLs** (wie Alt): `?t=<thema>&q=<schritt>` per `history.replaceState` (`useWizard`), ohne `q` kein Schritt offen. Chromelose Fokusansichten (`FOCUS` in `Protocol.vue`): `rettdaten?t=von|ziel` (Adresse/POI), `erstbefund?t=messwerte`, `anamnese?t=anamnese`, `massnahmen?t=medimaske`, `verlauf?t=add`, `abschluss?t=freigabe`. Sie halten Werte lokal bis "speichern" (`set` je geändertem Feld + `flush()` bzw. Zeilen-/Adress-API), "zurück" verwirft; schreibgeschützt fallen sie auf die Übersicht zurück. Weitere Tiefenlinks z. B. `erstbefund?t=erweitern&q=1`, `diagnose?t=haupt&q=8`, `massnahmen?t=zugang&q=2`, `verlauf?t=list`, `abschluss?t=rea`.
- **Feld hinzufügen:** (1) Schlüssel in `FieldSchema` (Typ, Abschnitt, Katalog; Katalog in `enotfCatalogs.json` mit Etiketten auf Deutsch); (2) in `enotfLayout.json` der Seite zuordnen, auf der das Alt-Formular ihn zeigt; (3) in der Seitenkomponente mit einem Widget aus `ui/` binden (`field`/`set`, nie eigener `fetch`); (4) Etikett `enotf.f.<key>` und neue Texte unter `enotf.ui.*` in `modules/Enotf/lang/{de,en}.json`; (5) Pflichtregel nur über `ConditionsService` + `enotfConditions.ts` (Fixture). `LayoutContractTest` schlägt fehl, solange (2) fehlt. Druck zeigt jedes Schemafeld automatisch (`Print.vue`).
- **Tests:** Pest `LayoutContractTest`, `tests/Feature/Enotf/*`; Vitest `tests/js/enotf{Ui,SelectSheet,BodyMap,AnamneseDiagnose,Massnahmen,Vitals,Abschluss}.test.ts`; Chromium-103-Probe der Protokollseiten (`docs/dev/cef.md`, Abschnitt "eNOTF-Protokoll").

## Update per Klick und Module

Ein Update läuft im Wartungsmodus und in eigenen Prozessen; Module dürfen es nicht stören:

- **Wartungsmodus:** Listener, Routen und Befehle eines Moduls rechnen damit, dass die App während des Updates mit 503 antwortet. Nur die Systemseite, `GET /settings/system/update/status`, `POST /settings/system/update/rollback` (und `/healthz`, `/api/health`) bleiben erreichbar. Kein Modul darf `php artisan up/down` aufrufen oder `storage/framework/down` anfassen.
- **Scheduler und Warteschlange:** Während der Wartung laufen geplante Aufgaben und Worker nicht weiter an der Datenbank. Eigene Aufgaben (`schedule()`) und Jobs dürfen den Ablauf nicht aufhalten (keine langen Sperren, keine Schreibzugriffe auf Dateien des Codes) und müssen nach dem Neustart des Workers wieder sauber anlaufen (Jobs sind wiederholbar).
- **Datenbank:** Migrationen eines Moduls laufen mit der Haupt-Migration im Update und müssen auf der Wegwerf-Kopie (Probelauf) und in der echten Datenbank durchgehen; ein Fehler dort macht das Update rückgängig (Wiederherstellung der Sicherung).
- **Dateien:** Nichts in `app/`, `config/`, `vendor/`, `modules/`, `public/build` oder `database/` zur Laufzeit schreiben (das Update tauscht diese Ordner aus, `modules/` steht in `FlatSwapper::CODE`); veränderliche Daten gehören nach `storage/`. Neue Ordner auf oberster Ebene kann ein Klick-Update nicht ausliefern.
- **Listener auf Update-Ereignisse** gibt es nicht; der Ablauf ist [update-recovery.md](update-recovery.md) und `INSTALL.md` "Aktualisieren" beschrieben.

## Route-Cache

`php artisan route:cache` (im Docker-Image über `optimize` beim Start) friert die Routen ein; ist der Cache aktiv, bindet `ModuleProvider` die Routendateien nicht mehr ein. Da alle Module immer registriert sind, stehen ihre Routen samt Gate im Cache: **Ein- und Ausschalten braucht keinen Neustart und kein `route:clear`.** Nur wer Routen eines Moduls ändert oder ein Modul hinzufügt, muss den Cache neu bauen (`php artisan optimize` bzw. Container neu starten).

## Altsystem-Zuordnung

Für den Import der Altkonfiguration: `intra_plugins.enabled` je Plugin-ID wird zu `modules.<name>.enabled`. Plugin-IDs mit Bindestrich bekommen einen Ordner ohne ihn (PHP-Namespace), der Modulname ist dessen Kleinschreibung:

| Plugin-ID (Alt) | Ordner | Modulname | Gruppe |
|---|---|---|---|
| `enotf` | `Enotf` | `enotf` | einsatz |
| `firetab` | `Firetab` | `firetab` | einsatz |
| `manv-board` | `ManvBoard` | `manvboard` | einsatz |
| `logbook` | `Logbook` | `logbook` | einsatz |
| `forms` | `Forms` | `forms` | verwaltung |
| `calendar` | `Calendar` | `calendar` | verwaltung |
| `mail` | `Mail` | `mail` | verwaltung |
| `knowledge-base` | `KnowledgeBase` | `knowledgebase` | verwaltung |

Ein Schalter für ein (noch) nicht vorhandenes Modul darf gespeichert werden; er wirkt, sobald das Modul installiert ist.

## Tests

Muster: `tests/Fixtures/modules/Demo` nutzt jeden Hook (Routen web und API, Migration `demo_things`, Suchquelle, Widget, Benachrichtigungstyp, `schedule()`, `listen()`, Befehl `demo:hello`, Einstellungsgruppe, Abhängigkeit auf `Beta` und das nicht installierte `gamma`; `Alpha` ist ein Kernmodul). Die Ordnersuche läuft einmal je Prozess; Tests, die Module anlegen, rufen `ModuleRegistry::flushDiscovery()`. Tests: `tests/Feature/ModulesTest.php` (Schalter über die Seite, ohne Neustart) und `tests/Unit/Modules/ModuleRegistryTest.php`. Dort wird `ModuleRegistry` mit dem Fixture-Ordner konstruiert, als Instanz gebunden (`$this->app->instance(ModuleRegistry::class, …)`, das Gate und die Registries lesen sie aus dem Container) und mit `registerAll($this->app)` registriert.

## Echtzeit

Opt-in: ohne `BROADCAST_CONNECTION=reverb` (Standard `null`) ändert sich nichts, kein pusher-js wird geladen, alle Seiten pollen wie zuvor. Betrieb: [INSTALL.md](../../INSTALL.md) und [operations.md](operations.md).

- **Regel "nur Thema + ID":** `App\Realtime\Realtime::pulse($channel, $topic, $id = null)` sendet das Ereignis `App\Realtime\Pulse` mit `{topic, id}`, nie Inhalte oder Patientendaten. Die Seite lädt über ihre eigenen, policy-geprüften Endpunkte nach (`router.reload({only})`). Aufruf nur über `Realtime::pulse`.
- **Versand:** nach Commit (`afterCommit`), nach der Antwort (`defer`, `always: true`: auch bei 4xx, wenn schon committet wurde), entprellt je Request (Schlüssel `pulse:kanal:thema:id`), über `event()` (`Event::fake([Pulse::class])` geht). Fehler still, Warnung höchstens alle 5 min, nur die Exception-Klasse. **Schutzschalter:** nach dem ersten Fehler setzt `send` den Cache-Schlüssel `realtime.down` (30 s); solange er steht, sendet kein Prozess (ein hängendes Reverb blockiert sonst je Puls einen PHP-Worker bis zum Timeout, jetzt 1 s). Kanal-IDs kommen als String an: Callbacks typisieren `string` und prüfen `ctype_digit` (sonst 500 statt 403).
- **Kanäle** (alle privat; der Callback prüft zusätzlich `ModuleRegistry::active`, auch bei Policies ohne `NoSuperAdminBypass`; Module registrieren ihre Kanäle im ServiceProvider, der Kern in `routes/channels.php`; `/broadcasting/auth`: `web, auth, fivem, throttle:broadcast-auth` 60/min je Konto):

| Kanal | Autorisierung | Themen (Auslöser) |
|---|---|---|
| `user.{id}` | eigenes Konto | `inbox` (Glocke, `NotificationSent` database), `mail` (`MailType::notify`) |
| `firetab.incident.{id}` | Policy `view` (Besatzung/QM, Super-Admin per Policy) | `incident` (`FireIncidentUpdated`/`Finalized`), `asu` (ASÜ-Ereignis, Trupp speichern/löschen, void), `map` (`MapChanged`) |
| `firetab.vehicle.{id}` | eigene fireTab-Sitzung auf diesem Fahrzeug | `status` (FMS-Status geändert), `incidents` (Fahrzeug einem Einsatz zugeordnet, Einsatz abgeschlossen) |
| `enotf.vehicle.{id}` | aktive Besatzung (auch gesperrt) auf diesem Fahrzeug | `status` (jede Protokoll-Speicherung, Besatzung, Teilen), `rows` (Vitalwerte/Medikamente, siehe unten) |
| `manv` | `viewAny` | `list` (Lage gespeichert) |
| `manv.{id}` | `view` | `board` (`ManvLog::record`) |

- **Client** (`resources/js/lib/realtime.ts`): `usePulse(channel | getter, topics, onPulse, {pollMs, safetyMs = 60 s, whileHidden})` liefert `{live}` und besitzt den Takt: `live ? safetyMs : pollMs`; `pollMs: 0` = kein Timer. Pulse werden 300 ms entprellt, ein verborgener Tab merkt sich einen verpassten Puls und lädt beim Sichtbarwerden einmal. Reconnect (zweites `subscription_succeeded`) lädt einmal nach zufälligen 0 bis 5 s (sonst laden nach einem Reverb-Neustart alle Seiten gleichzeitig). Seiten mit eigenem In-flight-Schutz (Glocke, Karte, FMS-Status, ASÜ-`poll`) verwerfen einen Puls während einer Anfrage nicht, sondern laden danach genau einmal nach; ASÜ-`poll` ist single-flight (keine überholten Antworten). Die eNOTF-Sitzung (`/enotf/status`) bleibt auch verbunden bei 10 s (`safetyMs: 10_000`): die PIN-Sperre wird nur bei einer Anfrage ausgewertet. Die Protokollseite liefert ihre teuren Props als Closures, der `rows`-Reload baut nur `vitals`/`medications`. Kanäle sind referenzgezählt (Glocke + Mail auf `user.{id}` teilen eines). `echo(cfg)` ist ein Singleton und lädt `laravel-echo`/`pusher-js` lazy (eigene Chunks).
- **Rückfall:** `subscription_error` oder Status ungleich `connected` setzt `live = false`, die Seite pollt im alten Takt (Status 5 s, ASÜ 3 s, eNOTF 10 s, Karte 15 s, MANV 20 s, Liste 30 s, Glocke 30 s). Fällt Reverb aus, merkt der Nutzer nur den älteren Takt.
- **Vital- und Medikamentenlisten:** Vitalwerte/Medikamente erhöhen keine Protokollrevision (`WritesProtocolRows`), ein `status`-Puls brächte nur ein 304. Darum das eigene Thema `rows` auf `enotf.vehicle.*` (aus `writeLocked`); `status` würde bei jedem Autosave die Listen neu laden. Gleicher Kanal; einen eigenen Kanal je Protokoll gibt es nicht, Fahrzeugkanal und Revisionen in `/enotf/status` decken den Abgleich ab.
- **Ohne Puls (Sicherheitsabruf deckt ab):** Ablauf von Freigaben (`enotf:share-expire`), Zeilen-Update `FireIncidentVehicle` über `DispatchIncidents::applyStatus` (der FMS-Teil pulst über `Vehicle`). Klinik-Tafel und Verfügbarkeit bleiben Polling.
- **Neues Thema anlegen:** im Modul-Provider `ModuleProvider::listen(...)` auf das Ereignis oder `eloquent.saved`, dort `Realtime::pulse`; in der Seite `usePulse` mit dem Thema und `only`-Reload; Kanal ggf. mit `Broadcast::channel` samt Modulprüfung. Tests: `tests/Feature/RealtimeChannelAuthTest.php`, `tests/Feature/Realtime/*`, `tests/js/realtime.test.ts`.

## Vernetzung

Modul `Federation` (`modules/Federation`, Gruppe `verwaltung`, **Standard aus**, nicht im Installer vorausgewählt). Zwei oder mehr ignis-Instanzen sehen gegenseitig Personal, freigegebene eNOTF-Protokolle und Feuerwehr-Einsätze, nur lesend, als lokaler Cache (Pull). Umfang und Felder 1:1 wie Alt (fiktive Spieldaten, Patient/Eigentümer/Anrufer in `data`; nie in Log, Audit, Discord). Betrieb: [operations.md](operations.md), [INSTALL.md](../../INSTALL.md).

- **Regel "ein Weg nach außen":** jede Anfrage an einen Peer geht über `Modules\Federation\Support\PeerHttp` (nur `https`, Zertifikatsprüfung, keine Redirects, alle aufgelösten IPs öffentlich und per `CURLOPT_RESOLVE` gepinnt, Port 443 außer Allowlist, 2 MB, Timeouts 5/20 s), jede eingehende durch `VerifyPeerSignature`. Kein anderer HTTP-Client für Peers. Ausnahmen nur per Umgebung: `FEDERATION_ALLOWED_HOSTS` (gesetzt = nur diese Hosts), `FEDERATION_ALLOW_PRIVATE` (nur die IP-Prüfung aus, nie https/Redirects).
- **Protokoll v2:** Kopfzeilen `X-Ignis-Federation: 2`, `X-Ignis-Instance` (eigene Instanz-ID), `X-Ignis-Timestamp` (Unix-Sekunden, ±300 s), `X-Ignis-Nonce` (32 Hex), `X-Ignis-Signature` (Hex HMAC-SHA256). Kanonischer Text `v2\n{METHODE}\n{Pfad relativ zur Peer-Basis}\n{Query nach Schlüssel sortiert, RFC 3986}\n{ts}\n{nonce}\n{sha256(body)}`; Schlüssel = `secret_outgoing` des Senders = `secret_incoming` des Empfängers (beide `encrypted`, `$hidden`). Prüfreihenfolge: Modul an (sonst 404) → Fehlschlag-Drossel je IP 30/min (429) → Link per Instanz-ID aktiv → Zeitfenster (`clock_skew`) → Signatur `hash_equals` → Nonce `Cache::add` 600 s (erst nach der Signatur) → `confirmed_at`. Antworten sind unsigniert (TLS genügt). Keine v1-Kompatibilität.
- **Fehlercodes** (UI, Lauf-Log, Link-Status; nie Antwortinhalt): eingehend `protocol_v2_required`, `clock_skew`, `unauthorized` (Signatur/Replay/unbekannt/inaktiv nicht unterscheidbar), `invalid_code` (pair), `not_provided` (403), `module_off` (404), `bad_cursor` (422); ausgehend `invalid_url`, `blocked_address`, `unreachable`, `tls`, `redirect`, `too_large`, `http_<status>`, `bad_json`, `peer_v1`, `clock_skew`, `unauthorized`, `internal`; Kopplung zusätzlich `invalid_token`, `self`, `already_linked`. Übersetzungen `federation.error.<code>` (Modul-JSON).
- **Endpunkte** (`routes/api.php`): `POST /api/federation/pair` (unsigniert, Einmal-Code, `throttle:10,1`), signiert + `throttle:federation-peer` (120/min je Link): `GET handshake`, `personnel`, `enotf`, `fire-incidents` mit `?after=<Cursor>&limit≤200`, Antwort `{success, instance_id, data, cursor, next}`; Feldlisten als Konstanten `FIELDS` der Controller (Test prüft die Schlüsselmenge), Tombstones `{id, visible:false}`.
- **Kopplung** (`Support\Pairing`): A erzeugt eine Einladung (`POST /settings/federation/invite`, JSON `{token}` einmal, `Cache-Control: no-store`; Code als SHA-256 in `federation.invite`, 15 min, eine offene), B fügt den Token ein (`POST /settings/federation/pair`), postet Code + neuen Schlüssel an A, speichert den Link und bestätigt per signiertem `handshake`. Code wird vor der Arbeit verbraucht, halbe Kopplung ersetzbar, bestätigte 409. Entkoppeln löscht nur die eigene Seite (Caches per FK).
- **Abnehmer:** `Jobs\SyncLink` (unique je Link 600 s, 3 Versuche 60/300/900 s, Seiten in je einer Transaktion: Upsert, Tombstone-Delete, Cursor), `federation:sync` alle 5 min (fällige Links), `federation:prune` 04:00 (Cache älter `federation.cache_days`, Lauf-Log 30 Tage, abgelaufene Einladung). Personal: Vollabzug mit Lauf-Kennung, Löschen erst nach vollständigem Lauf.
- **Oberfläche:** `/settings/federation` (`system.admin`, Menü nur bei aktivem Modul; Name und Cache-Tage im Konfig-Reiter `federation`), Nur-lesen-Seiten `/federation/protocols` (eNOTF-QM-Recht, Modul eNOTF) und `/federation/incidents` (`fire.incident.qm`, Modul FireTab), verlinkt als "Verbund (n)" aus den QM-Listen. Audit (`Federation`, global) nur mit `link_id`/`instance_id` und Instanzname.
- **FireTab-Leiter:** die Leitersuche liefert bei aktivem Modul bis 10 Treffer `fed:{link}:{remote}` aus `Support\Directory`; gespeichert wird der Schnappschuss `fire_incidents.leader_external_name` ("Name [Instanz]", `leader_personnel_id` leer). Anzeige über `FireIncident::leaderName()`. FireTab hängt nur über `ModuleRegistry::active('federation')` an. eNOTF-Besatzung bleibt lokal.
- **Tests:** `tests/Unit/Federation/PeerHttpTest.php`, `tests/Feature/Federation/{Signature,Pairing,Sync,RightsMatrix,Flow}Test.php`, Import `tests/Feature/Import/FederationImportTest.php` (Gruppe `legacy`).

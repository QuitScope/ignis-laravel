# Tests

## Testgruppen

CI führt keine Tests aus, nur Pint, PHPStan, den Build mit Typprüfung und den Docker-Smoke-Test. Die Tests laufen lokal und gezielt.

| Gruppe | Inhalt | Wo sie läuft |
|---|---|---|
| (keine) | der Rest der Suite | lokal (SQLite) |
| `slow` | Tests, die Prozesse starten oder ganze Updates durchspielen (`tests/Feature/Update/{EndToEndUpdate,UpdaterPipeline,RevertScript,TrialMigration,MariaDbDumper,ReleaseTools,UpdateLauncher}Test`, `Import/ImportCommandTest`, `Unit/Update/UpdateLockTest`) | lokal, bei Bedarf |
| `mariadb` | kleine kuratierte Auswahl (rund 140 Fälle): Schema (`Mail/SchemaTest`), Sperrpfade mit `lockForUpdate` (eNOTF Crew/Billing/Share/Medikamente, FireTab ASÜ/Einsatz/Statuswarteschlange/Karte, Mail Entwürfe/Sendelimits, MANV Patientennummer, Vernetzung, Mängel, Fahrzeugregister, Kontoverknüpfung, Einladung, Logo-Upload) und dialektabhängige Abfragen (`allowed_jobs`, globale Suche, Benachrichtigungen); große Dateien nur mit einzelnen Tests | lokal gegen eine MariaDB 11, zusammen mit `legacy` |
| `legacy` | Altschema und Importer gegen eine echte Alt-MariaDB (siehe unten) | lokal gegen eine Alt-MariaDB |

Lokal: `php artisan test --exclude-group=slow --exclude-group=legacy` ist der übliche Lauf, `--group=mariadb` mit
`--configuration=phpunit.mariadb.xml` der MariaDB-Lauf (über `vendor/bin/pest`: `artisan test` setzt selbst schon
`--configuration=phpunit.xml`, die doppelte Option ist eine PHPUnit-Warnung und Exit-Code 1). Mehrere Gruppen als wiederholte Option angeben; die Kommaform
(`--group=a,b`) filtert in dieser PHPUnit-Version nichts. Echte Nebenläufigkeit prüft keine Gruppe (eine Verbindung je
Test), dafür bleibt nur ein Test von Hand mit zwei Fenstern gleichzeitig.

## Altschema laden und die Testgruppe `legacy`

`tools/legacy-schema/load.php` spielt die Phinx-Migrationen des Altsystems (Kern und Plugins) mit einem Phinx-Ersatz in eine leere MariaDB ein. Die Pest-Gruppe `legacy` (`tests/Feature/LegacySchemaTest.php`, Hilfsklasse `tests/Support/LegacyDatabase.php`) nutzt das für spätere Import-Tests und wird übersprungen, wenn `LEGACY_TEST_DB_HOST` nicht gesetzt ist.

Wegwerf-Datenbank und Lauf:

```
docker run -d --name ignis-legacy-db -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=legacy_test -p 127.0.0.1:3307:3306 mariadb:11

LEGACY_PATH=/pfad/zu/ignis LEGACY_TEST_DB_HOST=127.0.0.1 LEGACY_TEST_DB_PORT=3307 \
LEGACY_TEST_DB_USERNAME=root LEGACY_TEST_DB_PASSWORD=root LEGACY_TEST_DB_DATABASE=legacy_test \
php artisan test --group=legacy

docker rm -f ignis-legacy-db
```

Variablen: `LEGACY_PATH` (Standard `../ignis` neben dem Projekt), `LEGACY_TEST_DB_HOST`, `LEGACY_TEST_DB_PORT` (3306), `LEGACY_TEST_DB_USERNAME` (root), `LEGACY_TEST_DB_PASSWORD`, `LEGACY_TEST_DB_DATABASE` (`legacy_test`). Die Tests löschen die Datenbank vor dem Einspielen und akzeptieren nur Namen, die mit `legacy_test` beginnen (nie die Standardverbindung der Anwendung). Die Variablen `LEGACY_DB_*` gehören dem echten Import (`ignis:import-legacy`, Quelle = Altsystem) und werden von den Tests nie benutzt; `phpunit.xml` leert `LEGACY_DB_HOST`. Der CLI-Lader liest dieselben `LEGACY_TEST_DB_*`-Variablen und akzeptiert ebenfalls nur Namen mit `legacy_test`.

Ohne Pest: `php tools/legacy-schema/load.php --legacy=../ignis --database=legacy_test` (Verbindung über die `LEGACY_TEST_DB_*`-Variablen; zeigt auf eine Wegwerf-Datenbank, der Lader löscht sie).

### Pin des Alt-Commits

Das Altsystem (`EmergencyForge/ignis`) gehört für diese Gruppe auf einen festen Commit (derzeit `31feee70a65182393c004805c98a60872936009a`), weil dessen Migrationen als PHP ausgeführt werden. Den Wert nur bewusst erhöhen, wenn sich die Alt-Migrationen geändert haben und danach die Gruppe `legacy` erneut laufen lassen.

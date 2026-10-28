# Fahrzeugregister-Annahme (EMD → Warteschlange)

ef_bridge sendet seine Tabelle `emd_vehicles`, wenn eine Sync-Antwort sie anfordert (`ef_bridge/server/emd_sync.lua`,
`SendVehicleRegistry`). `App\Vehicles\VehicleRegistryIntake::accept()` legt sie als `pending`-Einträge in
`vehicle_registry_entries` ab; Admins entscheiden darüber unter `/vehicles/registry` (`vehicle.manageImport`).

Angebunden: `POST api/emd/sync` ruft den Service auf (siehe unten). Vertrags-Fixture:
`tests/Fixtures/emd/vehicle_registry.json`, Vertragstest `tests/Unit/VehicleRegistryIntakeTest.php`.

## Payload

```json
{
  "intraRP_API_Key": "…",
  "timestamp": 1760870400,
  "vehicle_registry": [
    {"id": 1, "department": "Rettungsdienst", "value": "RTW 1/83-1", "valuelong": "Rettungswagen 1/83-1",
     "job": "ambulance", "grade": 0, "image": "rtw.png", "type": "RTW", "locked": 0, "funkkanal": "RD-1"}
  ]
}
```

| Zeilenschlüssel | Spalte | Regel |
|---|---|---|
| `value` | `name` | Pflicht: getrimmt, NUL entfernt, nicht leer, auf 255 gekürzt; sonst wird die Zeile übersprungen |
| (aus `value`) | `identifier` | `strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', name))`, bytegenau (ö → `__`), auf 255 gekürzt |
| `type` | `type`, `service` | NEF/NAW/ITW/RTH/ITH → 1, RTW/KTW/NTW/N-KTW/NKTW/S-RTW → 2, sonst 0 (nie 3); ohne Beachtung der Groß- und Kleinschreibung, getrimmt |
| `id` | `emd_vehicle_id` | vorzeichenlose Ganzzahl (oder Ziffernfolge als String), sonst null |
| `department`, `valuelong`, `job` | `department`, `value_long`, `job` | Text, auf 255 gekürzt; leer → null |
| `image`, `funkkanal` | `image`, `radio_channel` | Text, auf 100 bzw. 50 gekürzt |
| ganze Zeile | `payload` | die Zeile so, wie sie gesendet wurde (JSON) |

Zahlen in Textfeldern werden als Text gespeichert; Arrays und Objekte darin zählen als leer. `grade` und `locked` stehen
nur in `payload`.

## Grenzen

- Mehr als **5000 Zeilen** (`MAX_ROWS`): Es wird nichts gespeichert, die Warteschlange und das Anforderungs-Flag bleiben
  bestehen; `IntakeResult::$error = 'too_many_rows'`.
- Eine Zeile wird **übersprungen** (in `skipped` gezählt), wenn sie kein Array ist, kein brauchbares `value` hat,
  ungültiges UTF-8 enthält, als JSON größer als **16 KB** ist (`MAX_ROW_BYTES`) oder tiefer als 8 Ebenen verschachtelt ist.
- Ein Lauf löscht zuerst alle `pending`-Einträge (in einer Transaktion); `accepted` und `rejected` bleiben. Eine leere
  Liste leert die Warteschlange, wie bisher.
- Nach einem Lauf: Die Einstellung `vehicles.registry_requested_at` wird entfernt, `emd.last_sync_at` = jetzt (ISO 8601, UTC).
- Jedes INSERT enthält höchstens 500 Zeilen und etwa 1 MB (`MAX_INSERT_BYTES`).

`accept()` gibt `IntakeResult{received, stored, skipped, error}` zurück. Datenbankfehler gehören **nicht** zu diesem
Vertrag: Sie werden geworfen (der ganze Lauf wird zurückgerollt, die alten Pending-Einträge und das Anforderungs-Flag
bleiben) und der Aufrufer antwortet mit 500, ohne den Text der Exception.

**Zu großes Register:** Bei `too_many_rows` setzt `EmdSync` `vehicles.registry_requested_at` zurück, löst
bei allen mit `vehicle.manageImport` die Glocke aus (Art `system`) und antwortet mit 422, damit ef_bridge das
zu große Register nicht erneut sendet. Ein Admin fordert es unter `/vehicles/registry` erneut an, nachdem die Quelle korrigiert ist.

## Anbindung

`POST /api/emd/sync` (`routes/api.php`, zustandslos) durchläuft die globale Middleware `App\Http\Middleware\EmdBodyLimit` (2 MB, JSON-Tiefe 32)
**vor** jeder Dekodierung des Bodys (TrimStrings, `api.key`), danach `throttle:emd-sync`. `App\Emd\EmdSync` reicht einen Body mit `vehicle_registry` an
`VehicleRegistryIntake::accept()` weiter und antwortet in der alten Kurzform `{success, message, vehicle_registry_received}`;
jede Heartbeat-Antwort enthält `request_vehicle_registry` (Einstellung `vehicles.registry_requested_at` gesetzt). Datenbankfehler
werden mit 500 beantwortet, ohne den Text der Exception. Tests: `tests/Feature/Emd/MixedDispatchRegressionTest.php` (Kurzantwort).

## Unterschiede zum Altsystem

- `value` `'0'` wird gespeichert (das Altsystem übersprang es wegen `empty()`); `value` und `type` werden getrimmt, NUL wird entfernt.
- Texte werden auf ihre Spaltenlänge gekürzt, statt das INSERT scheitern zu lassen; Zeilen mit ungültigem UTF-8 werden übersprungen.
- Eine Float-`id` wie `12.0` ergibt `emd_vehicle_id` null (das Altsystem speicherte 12); Ziffernfolgen als String werden akzeptiert.
- Import und Überschreiben akzeptieren auch Überschreibungen von Name und Identifier (Altsystem: nur Typ, Service, erlaubte Jobs). Ein überschriebener
  Identifier wird so gespeichert, wie er eingegeben wurde, ohne die Bereinigung `[^a-zA-Z0-9_-]`.
- Der EMD-Text `job` wird wie im Fahrzeugformular aufgeteilt (`SaveVehicleRequest::parseJobs()`) und auf dessen Grenzen gekürzt
  (≤ 50 Jobs, jeweils ≤ 100 Zeichen; längere fallen weg).
- "Alle ignorieren" lehnt nur Einträge bis zum neuesten ab, den die Seite angezeigt hat; eine Anforderung kann zurückgenommen werden.

## API-Key

`api.key` (`App\Http\Middleware\ApiKeyMiddleware`) akzeptiert, der erste gefundene String gewinnt: Header `X-API-Key`, Query `api_key`,
JSON-Body `intraRP_API_Key` (das sendet ef_bridge), JSON-Body `api_key`. Nimm bevorzugt den Header; die Query-Variante landet in den
Access-Logs. Eine Sitzung ist nicht beteiligt.

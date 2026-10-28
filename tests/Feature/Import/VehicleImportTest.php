<?php

use App\Models\LoadoutCategory;
use App\Models\LoadoutItem;
use App\Models\Poi;
use App\Models\TacticalSymbolTemplate;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Models\VehicleRegistryEntry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const VEHICLE_ENTITIES = ['poi', 'vehicle', 'vehicle_defect', 'vehicle_defect_event', 'loadout', 'tactical_symbol_template', 'vehicle_registry', 'vehicle_audit_context'];

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    LegacySeed::vehicleQuirks();
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'Europe/Berlin', 'ignis.import.legacy_db_timezone' => 'UTC']);
});

afterEach(function () {
    if (LegacyDatabase::available()) {
        LegacyDatabase::connection()->statement("SET GLOBAL time_zone = 'SYSTEM'");
    }
});

function seedVehicleCases(): void
{
    LegacySeed::users([['id' => 1], ['id' => 7, 'fullname' => 'Ex Konto', 'discord_id' => null]]);
    LegacySeed::pois([
        ['id' => 11, 'name' => 'Wache Mitte', 'strasse' => 'Hauptstr.', 'hnr' => '1', 'ortsteil' => ' ', 'typ' => 'Rettungswache'],
        ['id' => 12, 'name' => 'Feuerwache Nord', 'typ' => 'Feuerwache', 'active' => 0],
    ]);
    LegacySeed::vehicles([
        ['id' => 1, 'name' => 'RTW 1', 'identifier' => 'rtw1', 'priority' => 5, 'kennzeichen' => '', 'allowed_jobs' => 'ems, fire', 'stationierung_poi_id' => 11,
            'current_status' => '2', 'status_updated_at' => '2024-07-15 12:00:00', 'status_source' => 'manual',
            'grundzeichen' => 'anhaenger', 'organisation' => 'feuerwehr', 'fachaufgabe' => 'betreuung', 'einheit' => 'trupp', 'symbol' => 'drehleiter',
            'typ' => 'einsatz', 'text' => 'RTW', 'tz_name' => 'RTW 1/83-1'],
        ['id' => 2, 'name' => 'RTW 1', 'identifier' => 'RTW1', 'rd_type' => 5, 'active' => 0, 'stationierung_poi_id' => 99,
            'current_status' => '7', 'status_updated_at' => '2024-07-15 12:00:00', 'status_source' => 'incident',
            'fachaufgabe' => 'versorgung', 'einheit' => 'verband', 'symbol' => 'angriff', 'text' => str_repeat('x', 120)],
        ['id' => 3, 'name' => 'HLF 1', 'veh_type' => 'HLF', 'rd_type' => 3, 'kennzeichen' => 'HH-1', 'allowed_jobs' => ' , ,',
            'fachaufgabe' => 'werkstatt', 'einheit' => 'groesserer-verband', 'symbol' => 'bereitstellung', 'text' => " ]]>Text\0 "],
        ['id' => 4, 'symbol' => 'boot', 'stationierung_poi_id' => 0],
        ['id' => 5, 'symbol' => 'erkunden'],
        ['id' => 6, 'allowed_jobs' => "ems\nfire"],
        ['id' => 7, 'allowed_jobs' => str_repeat('j', 150)],
    ]);
    LegacySeed::defects([
        ['id' => 1, 'vehicle_id' => 2, 'title' => 'Bremse', 'category' => 'bremsen', 'vehicle_operable' => 0, 'reported_by' => 0],
        ['id' => 2, 'vehicle_id' => 1, 'category' => 'quatsch', 'assigned_to' => 7, 'status' => 'resolved', 'resolved_by' => 1,
            'resolved_at' => '2024-07-16 08:00:00', 'resolution_note' => 'Erledigt'],
        ['id' => 3, 'vehicle_id' => 3, 'reported_by' => 7, 'category' => 'windschutzscheibe', 'resolved_by' => 1,
            'resolved_at' => '2024-07-16 08:00:00', 'resolution_note' => 'Alt'],
        ['id' => 4, 'vehicle_id' => 99],
        ['id' => 5, 'vehicle_id' => 1, 'status' => 'resolved', 'resolved_by' => 1, 'resolved_at' => '2024-07-16 08:00:00', 'resolution_note' => ''],
    ]);
    $log = [['id' => 1, 'defect_id' => 1, 'user_id' => 0, 'action' => 'created', 'details' => 'Defekt gemeldet: Bremse (Gemeldet durch: Max Crew)'],
        ['id' => 2, 'defect_id' => 1, 'user_id' => 0, 'action' => 'vehicle_disabled', 'details' => 'Fahrzeug als nicht einsatzfähig markiert'],
        ['id' => 3, 'defect_id' => 3, 'user_id' => 7, 'action' => 'created']];
    for ($i = 4; $i <= 2500; $i++) {
        $log[] = ['id' => $i, 'defect_id' => $i > 2490 ? 4 : 2];
    }
    LegacySeed::defectLog($log);
    LegacySeed::loadoutCategories([
        ['id' => 1, 'title' => 'Notfallrucksack', 'type' => 0, 'priority' => 3],
        ['id' => 2, 'title' => 'Außenfach', 'type' => 5, 'priority' => 1, 'veh_type' => 'HLF'],
        ['id' => 3, 'title' => 'Alt', 'veh_type' => null],
    ]);
    LegacySeed::loadoutTiles([
        ['id' => 1, 'category' => 1, 'amount' => 2, 'sort_order' => 4],
        ['id' => 2, 'category' => 2, 'amount' => -1],
        ['id' => 3, 'category' => 9],
    ]);
    LegacySeed::symbolTemplates([
        ['id' => 1, 'name' => 'Löschfahrzeug', 'grundzeichen' => 'anhaenger', 'organisation' => 'feuerwehr', 'created_by' => 1],
        ['id' => 2, 'name' => 'LOSCHFAHRZEUG', 'grundzeichen' => 'anhaenger'],
        ['id' => 3, 'name' => 'Nur Text', 'text' => 'Abc', 'created_by' => 7],
        ['id' => 4, 'name' => 'Unbekannt', 'symbol' => 'angriff'],
    ]);
    LegacySeed::registryQueue([
        ['id' => 1],
        ['id' => 2, 'status' => 'accepted', 'processed_by' => 1, 'processed_at' => '2024-07-16 09:00:00', 'raw_data' => '{"value":"RTW 2","type":"RTW"}'],
        ['id' => 3, 'status' => 'rejected', 'processed_by' => 7, 'raw_data' => 'not json', 'rd_type' => 9],
    ]);
    LegacySeed::audit([
        ['id' => 1, 'user' => 1, 'module' => 'Fahrzeuge', 'action' => 'Fahrzeug erstellt [ID: 1]', 'details' => null, 'context' => '{"id":1}', 'global' => 1, 'timestamp' => '2024-01-01 00:00:00'],
        ['id' => 2, 'user' => 1, 'module' => 'Fahrzeuge', 'action' => 'Defekt gemeldet [ID: 1]', 'details' => null, 'context' => '{"id":2,"defect_id":1}', 'global' => 1, 'timestamp' => '2024-01-01 00:00:00'],
        ['id' => 3, 'user' => 1, 'module' => 'Fahrzeuge', 'action' => 'Fahrzeug gelöscht [ID: 99]', 'details' => null, 'context' => '{"id":99,"defect_id":4}', 'global' => 1, 'timestamp' => '2024-01-01 00:00:00'],
        ['id' => 4, 'user' => 1, 'module' => 'Fahrzeuge', 'action' => 'TZ-Vorlage gespeichert: X', 'details' => null, 'context' => null, 'global' => 1, 'timestamp' => '2024-01-01 00:00:00'],
        ['id' => 5, 'user' => 1, 'module' => 'Personal', 'action' => 'x', 'details' => null, 'context' => '{"id":1}', 'global' => 1, 'timestamp' => '2024-01-01 00:00:00'],
        ['id' => 6, 'user' => 1, 'module' => 'Fahrzeuge', 'action' => 'Fahrzeug aktualisiert [ID: 3]', 'details' => null, 'context' => null, 'global' => 1, 'timestamp' => '2024-01-01 00:00:00'],
    ]);
}

function newId(string $entity, int|string $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

/** @param array<string, mixed> $json */
function vehicleWarnings(array $json): string
{
    return implode("\n", $json['warnings']);
}

/** @return array<string, int> */
function vehicleCounts(): array
{
    return collect(['pois', 'vehicles', 'vehicle_defects', 'vehicle_defect_events', 'loadout_categories', 'loadout_items', 'tactical_symbol_templates',
        'vehicle_registry_entries', 'legacy_id_map'])->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

/** @return array<string, mixed> context of the imported audit row */
function auditContext(int $legacyId): array
{
    return json_decode((string) DB::table('activity_log')->where('legacy_id', $legacyId)->value('properties'), true)['context'] ?? [];
}

it('imports POIs and vehicles with mappings, per-column clocks and counted warnings', function () {
    seedVehicleCases();

    $json = LegacySeed::run(['vehicle']);

    $one = Vehicle::find(newId('vehicle', 1));
    $two = Vehicle::find(newId('vehicle', 2));
    $three = Vehicle::find(newId('vehicle', 3));
    $station = Poi::find(newId('poi', 11));
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['poi'])->toBe(['read' => 2, 'written' => 2, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['vehicle'])->toBe(['read' => 7, 'written' => 7, 'skipped' => 0, 'failed' => 0])
        ->and($station->street)->toBe('Hauptstr.')->and($station->district)->toBeNull()->and($station->type)->toBe('Rettungswache')
        ->and(Poi::find(newId('poi', 12))->is_active)->toBeFalse()
        ->and($one->station_poi_id)->toBe($station->id)
        ->and($one->sort_order)->toBe(5)->and($one->service)->toBe(2)->and($one->license_plate)->toBeNull()
        ->and($one->allowed_jobs)->toBe(['ems', 'fire'])
        ->and($one->tactical_symbol)->toBe(['grundzeichen' => 'anhaenger', 'organisation' => 'feuerwehr', 'fachaufgabe' => 'betreuung',
            'einheit' => 'trupp', 'symbol' => 'drehleiter', 'typ' => 'einsatz', 'text' => 'RTW'])
        ->and($one->tactical_symbol_name)->toBe('RTW 1/83-1')
        ->and($one->fms_status)->toBe('2')->and($one->fms_status_source)->toBe('manual')
        ->and(DB::table('vehicles')->where('id', $one->id)->value('fms_status_at'))->toBe('2024-07-15 10:00:00')
        ->and(DB::table('vehicles')->where('id', $one->id)->value('created_at'))->toBe('2024-07-15 12:00:00')
        ->and(DB::table('vehicles')->where('id', $two->id)->value('fms_status_at'))->toBe('2024-07-15 12:00:00')
        ->and($two->fms_status)->toBe('7')->and($two->service)->toBe(0)->and($two->station_poi_id)->toBeNull()
        ->and($two->is_active)->toBeFalse()
        ->and($two->tactical_symbol)->toBe(['text' => str_repeat('x', 100)])
        ->and($three->service)->toBe(3)->and($three->license_plate)->toBe('HH-1')->and($three->allowed_jobs)->toBeNull()
        ->and($three->tactical_symbol)->toBe(['text' => 'Text'])
        ->and(Vehicle::find(newId('vehicle', 4))->tactical_symbol)->toBeNull()
        ->and(Vehicle::find(newId('vehicle', 6))->allowed_jobs)->toBe(['ems', 'fire'])
        ->and(Vehicle::find(newId('vehicle', 7))->allowed_jobs)->toBeNull()
        ->and(Vehicle::where('name', 'RTW 1')->count())->toBe(2);
    $warnings = vehicleWarnings($json);
    foreach (['fachaufgabe \'versorgung\'' => 2, 'fachaufgabe \'werkstatt\'' => 3, 'einheit \'verband\'' => 2, 'einheit \'groesserer-verband\'' => 3,
        'symbol \'angriff\'' => 2, 'symbol \'bereitstellung\'' => 3, 'symbol \'boot\'' => 4, 'symbol \'erkunden\'' => 5] as $what => $legacy) {
        expect($warnings)->toContain("1 vehicle(s): tactical symbol {$what} is not a known library value, dropped (legacy ids: {$legacy})");
    }
    expect($warnings)
        ->toContain('1 vehicle(s): service (rd_type) 5 is outside 0-3, set to 0 (legacy ids: 2)')
        ->toContain('1 vehicle(s): station POI was not imported (or no longer exists), imported without station (legacy ids: 2)')
        ->toContain('1 vehicle(s): FMS status outside 0-6, kept as text (legacy ids: 2)')
        ->toContain('1 vehicle(s): allowed_jobs had spaces around the commas')
        ->toContain('2 vehicle(s): allowed_jobs had no usable job after cleaning, imported as open to all jobs (legacy ids: 3, 7)')
        ->toContain('1 vehicle(s): allowed_jobs had line breaks, split into separate jobs (the legacy FIND_IN_SET read them as one job) (legacy ids: 6)')
        ->toContain('1 vehicle(s): text longer than 100 characters, cut (legacy ids: 2)')
        ->toContain('2 vehicle(s) share their name with another vehicle, all kept (legacy ids: 1, 2)')
        ->toContain('2 vehicle(s) share their identifier with another vehicle, all kept (legacy ids: 1, 2)')
        ->and(implode("\n", $json['notes']))
        ->toContain('1 inactive vehicle(s) have an open blocking defect')->toContain('(legacy ids: 2)')
        ->toContain('NOW()-written DATETIME columns read in UTC (IGNIS_IMPORT_LEGACY_DB_TIMEZONE)');
});

it('converts TIMESTAMP columns per value, independent of the legacy server zone', function () {
    LegacyDatabase::connection()->statement("SET time_zone = '+00:00'");
    LegacySeed::users([['id' => 1]]);
    LegacySeed::vehicles([['id' => 1, 'created_at' => '2024-01-15 12:00:00'], ['id' => 2, 'created_at' => '2024-07-15 12:00:00']]);
    LegacySeed::defects([
        ['id' => 1, 'vehicle_id' => 1, 'created_at' => '2024-01-15 12:00:00', 'updated_at' => '2024-01-15 12:00:00',
            'status' => 'resolved', 'resolved_at' => '2024-01-15 12:00:00'],
        ['id' => 2, 'vehicle_id' => 2, 'created_at' => '2024-07-15 12:00:00', 'updated_at' => '2024-07-15 12:00:00',
            'status' => 'resolved', 'resolved_at' => '2024-07-15 12:00:00'],
    ]);
    LegacySeed::defectLog([['id' => 1, 'defect_id' => 1, 'created_at' => '2024-01-15 12:00:00'], ['id' => 2, 'defect_id' => 2, 'created_at' => '2024-07-15 12:00:00']]);
    LegacyDatabase::connection()->statement("SET GLOBAL time_zone = 'Europe/Berlin'");
    config(['ignis.import.legacy_db_timezone' => null]);

    $json = LegacySeed::run(['vehicle_defect_event']);

    $at = fn (string $table, string $entity, int $legacy, string $column) => DB::table($table)->where('id', newId($entity, $legacy))->value($column);
    expect($json['exit'])->toBe(0)
        ->and($at('vehicles', 'vehicle', 1, 'created_at'))->toBe('2024-01-15 12:00:00')
        ->and($at('vehicles', 'vehicle', 2, 'created_at'))->toBe('2024-07-15 12:00:00')
        ->and($at('vehicle_defects', 'vehicle_defect', 1, 'updated_at'))->toBe('2024-01-15 12:00:00')
        ->and($at('vehicle_defects', 'vehicle_defect', 2, 'created_at'))->toBe('2024-07-15 12:00:00')
        ->and(DB::table('vehicle_defect_events')->orderBy('legacy_id')->pluck('created_at')->all())->toBe(['2024-01-15 12:00:00', '2024-07-15 12:00:00'])
        ->and($at('vehicle_defects', 'vehicle_defect', 1, 'resolved_at'))->toBe('2024-01-15 11:00:00')
        ->and($at('vehicle_defects', 'vehicle_defect', 2, 'resolved_at'))->toBe('2024-07-15 10:00:00');
    expect(vehicleWarnings($json))->toContain('IGNIS_IMPORT_LEGACY_DB_TIMEZONE is not set: NOW()-written DATETIME columns are read as Europe/Berlin')
        ->toContain('@@global.time_zone = Europe/Berlin')->toContain('@@system_time_zone = ')->toContain('restored dump');
});

it('reads NOW()-written DATETIME columns in IGNIS_IMPORT_LEGACY_DB_TIMEZONE, TIMESTAMP columns stay instants', function () {
    LegacySeed::users([['id' => 1]]);
    LegacySeed::vehicles([
        ['id' => 1, 'created_at' => '2024-01-15 12:00:00', 'status_updated_at' => '2024-01-15 12:00:00', 'status_source' => 'incident'],
        ['id' => 2, 'created_at' => '2024-07-15 12:00:00', 'status_updated_at' => '2024-07-15 12:00:00', 'status_source' => 'incident'],
    ]);
    LegacySeed::registryQueue([['id' => 1, 'status' => 'accepted', 'processed_at' => '2024-01-15 12:00:00']]);
    config(['ignis.import.legacy_db_timezone' => 'Europe/Berlin']);

    $json = LegacySeed::run(['vehicle', 'vehicle_registry']);

    $at = fn (string $table, string $entity, int $legacy, string $column) => DB::table($table)->where('id', newId($entity, $legacy))->value($column);
    expect($json['exit'])->toBe(0)
        ->and($at('vehicles', 'vehicle', 1, 'fms_status_at'))->toBe('2024-01-15 11:00:00')
        ->and($at('vehicles', 'vehicle', 2, 'fms_status_at'))->toBe('2024-07-15 10:00:00')
        ->and($at('vehicles', 'vehicle', 2, 'created_at'))->toBe('2024-07-15 12:00:00')
        ->and($at('vehicle_registry_entries', 'vehicle_registry', 1, 'processed_at'))->toBe('2024-01-15 11:00:00')
        ->and(vehicleWarnings($json))->not->toContain('IGNIS_IMPORT_LEGACY_DB_TIMEZONE is not set');

    config(['ignis.import.legacy_db_timezone' => 'Mars/Olympus']);
    expect(LegacySeed::run(['vehicle'])['exit'])->toBe(1)->and(Vehicle::count())->toBe(2);
});

it('skips POIs with a note when the legacy table does not exist', function () {
    LegacySeed::vehicles([['id' => 1, 'stationierung_poi_id' => 11]]);
    $db = LegacyDatabase::connection();
    $db->statement('RENAME TABLE intra_edivi_pois TO intra_edivi_pois_hidden');
    try {
        $json = LegacySeed::run(['vehicle']);
    } finally {
        $db->statement('RENAME TABLE intra_edivi_pois_hidden TO intra_edivi_pois');
    }

    expect($json['exit'])->toBe(0)->and($json['entities']['vehicle']['written'])->toBe(1)
        ->and(implode("\n", $json['notes']))->toContain('intra_edivi_pois does not exist')
        ->and(Vehicle::first()->station_poi_id)->toBeNull();
});

it('imports defects and their history in blocks with reporter names and counted orphans', function () {
    seedVehicleCases();

    $json = LegacySeed::run(['vehicle_defect_event']);

    $crew = VehicleDefect::find(newId('vehicle_defect', 1));
    $resolved = VehicleDefect::find(newId('vehicle_defect', 2));
    $former = VehicleDefect::find(newId('vehicle_defect', 3));
    $user = newId('user', 1);
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['vehicle_defect'])->toBe(['read' => 5, 'written' => 4, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['vehicle_defect_event'])->toBe(['read' => 2500, 'written' => 2490, 'skipped' => 10, 'failed' => 0])
        ->and($crew->vehicle_id)->toBe(newId('vehicle', 2))->and($crew->category)->toBe('brakes')->and($crew->is_operable)->toBeFalse()
        ->and($crew->reported_by)->toBeNull()->and($crew->reporter_name)->toBe('Max Crew')
        ->and($resolved->category)->toBe('other')->and($resolved->status)->toBe('resolved')->and($resolved->reported_by)->toBe($user)
        ->and($resolved->assigned_to)->toBeNull()->and($resolved->resolved_by)->toBe($user)->and($resolved->resolution_note)->toBe('Erledigt')
        ->and(DB::table('vehicle_defects')->where('id', $resolved->id)->value('resolved_at'))->toBe('2024-07-16 08:00:00')
        ->and(DB::table('vehicle_defects')->where('id', $resolved->id)->value('updated_at'))->toBe('2024-07-15 13:00:00')
        ->and($former->reported_by)->toBeNull()->and($former->reporter_name)->toBe('Ex Konto')->and($former->category)->toBe('windscreen')
        ->and($former->resolved_by)->toBeNull()->and($former->resolved_at)->toBeNull()->and($former->resolution_note)->toBeNull()
        ->and(VehicleDefect::find(newId('vehicle_defect', 5))->resolution_note)->toBeNull()
        ->and($crew->events()->orderBy('legacy_id')->pluck('action')->all())->toBe(['created', 'vehicle_disabled'])
        ->and($crew->events()->whereNotNull('user_id')->count())->toBe(0)
        ->and($former->events()->value('user_id'))->toBeNull()
        ->and($resolved->events()->count())->toBe(2487)->and($resolved->events()->value('user_id'))->toBe($user)
        ->and(Vehicle::find(newId('vehicle', 2))->is_operable)->toBeFalse()
        ->and(implode("\n", $json['notes']))->toContain('vehicle_defect_event: 3 chunks of up to 1000 rows');
    expect(vehicleWarnings($json))
        ->toContain("1 defect(s): unknown category 'quatsch', set to other (legacy ids: 2)")
        ->toContain('1 defect(s): reported without an account, reporter name kept (legacy ids: 1)')
        ->toContain('1 defect(s): reporter account not imported, reporter name kept (legacy ids: 3)')
        ->toContain('1 defect(s): assigned account not imported, left unassigned (legacy ids: 2)')
        ->toContain('1 defect(s) of vehicles that were not imported were skipped (legacy ids: 4)')
        ->toContain('10 defect history entry(ies) of defects that were not imported were skipped (legacy ids: 2491, 2492')
        ->toContain('1 defect history entry(ies) by an account that was not imported, imported without user (legacy ids: 3)');
});

it('imports loadout lists and follows an item into another category on the next run', function () {
    seedVehicleCases();

    $json = LegacySeed::run(['loadout']);

    $kit = LoadoutCategory::find(newId('loadout_category', 1));
    $outside = LoadoutCategory::find(newId('loadout_category', 2));
    $item = LoadoutItem::find(newId('loadout_item', 1));
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['loadout_category'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['loadout_item'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0])
        ->and($kit->compartment)->toBe(0)->and($kit->sort_order)->toBe(3)->and($kit->vehicle_type)->toBe('RTW')
        ->and($outside->compartment)->toBe(2)->and($outside->vehicle_type)->toBe('HLF')
        ->and(LoadoutCategory::find(newId('loadout_category', 3))->vehicle_type)->toBeNull()
        ->and($item->loadout_category_id)->toBe($kit->id)->and($item->quantity)->toBe(2)->and($item->sort_order)->toBe(4)
        ->and(LoadoutItem::find(newId('loadout_item', 2))->quantity)->toBe(0);
    expect(vehicleWarnings($json))
        ->toContain('1 loadout categor(y/ies) with a compartment outside 0-2, clamped (legacy ids: 2)')
        ->toContain('1 loadout categor(y/ies) without vehicle type: they apply to every type, as in the legacy eNOTF vehicle info (the legacy admin vehicle page showed them on none) (legacy ids: 3)')
        ->toContain('1 loadout item(s) with a negative amount, set to 0 (legacy ids: 2)')
        ->toContain('1 loadout item(s) of categories that were not imported were skipped (legacy ids: 3)');

    LegacyDatabase::connection()->table('intra_fahrzeuge_beladung_tiles')->where('id', 1)->update(['category' => 2]);
    LegacySeed::run(['loadout']);

    expect($item->fresh()->loadout_category_id)->toBe($outside->id)->and(LoadoutItem::count())->toBe(2);
});

it('imports symbol templates de-duplicated by name regardless of case and accents', function () {
    seedVehicleCases();
    $existing = TacticalSymbolTemplate::factory()->create(['name' => 'nur text', 'symbol' => ['grundzeichen' => 'anhaenger']]);

    $json = LegacySeed::run(['tactical_symbol_template']);

    $fire = TacticalSymbolTemplate::find(newId('tactical_symbol_template', 1));
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['tactical_symbol_template'])->toBe(['read' => 4, 'written' => 3, 'skipped' => 1, 'failed' => 0])
        ->and(TacticalSymbolTemplate::count())->toBe(3)
        ->and($fire->name)->toBe('Löschfahrzeug')->and($fire->symbol)->toBe(['grundzeichen' => 'anhaenger', 'organisation' => 'feuerwehr'])
        ->and($fire->created_by)->toBe(newId('user', 1))
        ->and(newId('tactical_symbol_template', 3))->toBe($existing->id)
        ->and($existing->fresh()->name)->toBe('Nur Text')->and($existing->fresh()->symbol)->toBe(['text' => 'Abc'])
        ->and($existing->fresh()->created_by)->toBeNull()
        ->and(TacticalSymbolTemplate::find(newId('tactical_symbol_template', 4))->symbol)->toBe([])
        ->and(newId('tactical_symbol_template', 2))->toBeNull()
        ->and(implode("\n", $json['notes']))->toContain('adopted existing template Nur Text');
    expect(vehicleWarnings($json))
        ->toContain('1 template(s) with an empty name or a name that differs from an earlier one only in case or accents were skipped (legacy ids: 2)')
        ->toContain('1 template(s) without any known symbol value, imported with an empty symbol (legacy ids: 4)')
        ->toContain("1 template(s): tactical symbol symbol 'angriff' is not a known library value, dropped (legacy ids: 4)")
        ->toContain('1 template(s) by an account that was not imported, imported without creator (legacy ids: 3)');
});

it('imports only decided registry entries and discards pending ones', function () {
    seedVehicleCases();

    $json = LegacySeed::run(['vehicle_registry']);

    $accepted = VehicleRegistryEntry::find(newId('vehicle_registry', 2));
    $rejected = VehicleRegistryEntry::find(newId('vehicle_registry', 3));
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['vehicle_registry'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0])
        ->and(newId('vehicle_registry', 1))->toBeNull()
        ->and($accepted->status)->toBe('accepted')->and($accepted->payload)->toBe(['value' => 'RTW 2', 'type' => 'RTW'])
        ->and($accepted->processed_by)->toBe(newId('user', 1))->and($accepted->service)->toBe(2)->and($accepted->job)->toBe('ambulance')
        ->and(DB::table('vehicle_registry_entries')->where('id', $accepted->id)->value('processed_at'))->toBe('2024-07-16 09:00:00')
        ->and($rejected->status)->toBe('rejected')->and($rejected->payload)->toBeNull()->and($rejected->processed_by)->toBeNull()
        ->and($rejected->service)->toBe(0)
        ->and(implode("\n", $json['notes']))->toContain('1 pending entr(y/ies) discarded');
    expect(vehicleWarnings($json))
        ->toContain('1 registry entr(y/ies) with invalid raw_data JSON, imported without payload (legacy ids: 3)')
        ->toContain('1 registry entr(y/ies) with rd_type outside 0-3, service set to 0 (legacy ids: 3)')
        ->toContain('1 registry entr(y/ies) processed by an account that was not imported, imported without processor (legacy ids: 3)');
});

it('remaps the vehicle and defect ids of imported Fahrzeuge audit entries as numbers, the same on a re-run', function () {
    seedVehicleCases();

    $json = LegacySeed::run(['vehicle_audit_context']);
    $first = DB::table('activity_log')->whereNotNull('legacy_id')->orderBy('legacy_id')->pluck('properties', 'legacy_id')->all();
    $second = LegacySeed::run(['vehicle_audit_context']);

    $vehicle = newId('vehicle', 2);
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['vehicle_audit_context'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0])
        ->and(auditContext(1))->toBe(['id' => newId('vehicle', 1), 'legacy_id' => 1])
        ->and(auditContext(2))->toBe(['id' => $vehicle, 'defect_id' => newId('vehicle_defect', 1), 'legacy_id' => 2, 'legacy_defect_id' => 1])
        ->and(auditContext(3))->toBe(['legacy_id' => 99, 'legacy_defect_id' => 4])
        ->and(auditContext(5))->toBe(['id' => 1])
        ->and(DB::table('activity_log')->where('log_name', 'Fahrzeuge')->where('properties->context->id', $vehicle)->pluck('legacy_id')->all())->toBe([2])
        ->and($second['entities']['vehicle_audit_context'])->toBe($json['entities']['vehicle_audit_context'])
        ->and(DB::table('activity_log')->whereNotNull('legacy_id')->orderBy('legacy_id')->pluck('properties', 'legacy_id')->all())->toBe($first);
    expect(vehicleWarnings($json))
        ->toContain('1 Fahrzeuge audit entry(ies) refer to a vehicle that was not imported; they keep only context.legacy_id and do not show on a vehicle page (legacy ids: 3)')
        ->toContain('1 Fahrzeuge audit entry(ies) refer to a defect that was not imported; they keep only context.legacy_defect_id (legacy ids: 3)');
});

it('imports everything a second time with identical counts and results', function () {
    seedVehicleCases();

    $first = LegacySeed::run(VEHICLE_ENTITIES);
    $afterFirst = vehicleCounts();
    $vehicles = DB::table('vehicles')->orderBy('id')->get()->map(fn ($v) => (array) $v)->all();
    $second = LegacySeed::run(VEHICLE_ENTITIES);

    expect($first['exit'])->toBe(0)->and($second['exit'])->toBe(0)
        ->and(vehicleCounts())->toBe($afterFirst)
        ->and($second['entities'])->toBe($first['entities'])
        ->and($second['warnings'])->toBe(array_values(array_filter($first['warnings'], fn (string $w) => ! str_contains($w, 'permission(s) unknown'))))
        ->and(DB::table('vehicles')->orderBy('id')->get()->map(fn ($v) => (array) $v)->all())->toBe($vehicles);
});

it('leaves no trace on a dry run', function () {
    seedVehicleCases();
    $before = vehicleCounts();
    $audit = DB::table('activity_log')->count();

    $json = LegacySeed::run(VEHICLE_ENTITIES, dry: true);

    expect($json['exit'])->toBe(0)->and($json['dry_run'])->toBeTrue()
        ->and($json['entities']['vehicle']['written'])->toBe(7)
        ->and(vehicleCounts())->toBe($before)->and(DB::table('activity_log')->count())->toBe($audit);
});

it('retries a failing block of defect history row by row', function () {
    seedVehicleCases();
    DB::table('legacy_id_map')->insert(['entity' => 'vehicle_defect', 'legacy_id' => '4', 'new_id' => 999999]);

    $json = LegacySeed::run(['vehicle_defect_event']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['vehicle_defect_event'])->toBe(['read' => 2500, 'written' => 2490, 'skipped' => 0, 'failed' => 10])
        ->and(DB::table('vehicle_defect_events')->count())->toBe(2490)
        ->and(implode('
', $json['failures']))->toContain('legacy defect log 2491');
});

it('warns when Fahrzeuge audit entries were imported without remapping their context', function () {
    seedVehicleCases();

    $audit = LegacySeed::run(['audit']);
    $full = LegacySeed::run(['audit', 'vehicle_audit_context']);

    expect(vehicleWarnings($audit))->toContain('vehicle_audit_context did not run')
        ->and(vehicleWarnings($full))->not->toContain('vehicle_audit_context did not run');
});

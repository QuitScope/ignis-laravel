<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Logbook\Models\LogbookEntry;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const LOGBOOK_ENTITIES = ['vehicle', 'logbook'];

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

function seedLogbookCases(): void
{
    LegacySeed::users([['id' => 1], ['id' => 7, 'discord_id' => null]]);
    LegacySeed::vehicles([['id' => 1, 'identifier' => 'rtw1']]);
    LegacySeed::logbook([
        ['id' => 1, 'vehicle_id' => 1, 'vehicle_identifier' => 'rtw1', 'fahrttyp' => 'einsatzfahrt', 'source' => 'enotf', 'created_by' => 1,
            'datum' => '2024-07-15', 'abfahrt' => '23:30:00', 'ankunft' => '00:15:00', 'grund' => 'Nachts', 'kilometer' => 8.3],
        ['id' => 2, 'vehicle_id' => null, 'vehicle_identifier' => 'alt9', 'fahrttyp' => 'quatsch', 'created_by' => 7,
            'kilometer' => -4, 'stationierungsort' => '', 'ankunft' => null],
        ['id' => 3, 'vehicle_id' => 99, 'vehicle_identifier' => 'weg', 'fahrttyp' => 'uebungsfahrt'],
        ['id' => 4, 'vehicle_id' => 1, 'fahrttyp' => 'werkstattfahrt', 'created_by' => 55, 'kilometer' => null],
        ['id' => 5, 'vehicle_id' => 1, 'fahrttyp' => 'bewegungsfahrt', 'source' => 'firetab'],
        ['id' => 6, 'vehicle_id' => 1, 'fahrttyp' => 'sonstige'],
    ]);
}

/** @return array<string, int> */
function logbookCounts(): array
{
    return collect(['logbook_entries', 'legacy_id_map'])->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

function logbookEntry(int $legacyId): LogbookEntry
{
    return LogbookEntry::findOrFail(DB::table('legacy_id_map')->where(['entity' => 'logbook', 'legacy_id' => (string) $legacyId])->value('new_id'));
}

function mappedId(string $entity, int $legacyId): int
{
    return (int) DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');
}

it('imports trips with wall clock, mapped keys, vehicle and account links and counted warnings', function () {
    seedLogbookCases();

    $json = LegacySeed::run(LOGBOOK_ENTITIES);

    $a = logbookEntry(1);
    $b = logbookEntry(2);
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['logbook'])->toBe(['read' => 6, 'written' => 6, 'skipped' => 0, 'failed' => 0])
        ->and($a->vehicle_id)->toBe(mappedId('vehicle', 1))->and($a->vehicle_identifier)->toBe('rtw1')->and($a->created_by)->toBe(mappedId('user', 1))
        ->and($a->trip_type)->toBe('einsatz')->and($a->source)->toBe('enotf')->and($a->driver_name)->toBe('Max Fahrer')
        ->and($a->station_name)->toBe('Wache Mitte')->and($a->reason)->toBe('Nachts')->and($a->distance_km)->toBe(8.3)
        ->and($a->trip_date)->toBe('2024-07-15')->and($a->departed_at)->toBe('23:30:00')->and($a->arrived_at)->toBe('00:15:00')
        ->and($a->created_at->utc()->format('H:i'))->toBe('12:00')->and($a->updated_at->utc()->format('H:i'))->toBe('13:00')
        ->and($b->vehicle_id)->toBeNull()->and($b->vehicle_identifier)->toBe('alt9')->and($b->created_by)->toBeNull()
        ->and($b->trip_type)->toBe('sonstige')->and($b->distance_km)->toBe(0.0)
        ->and($b->station_name)->toBeNull()->and($b->arrived_at)->toBeNull()
        ->and(logbookEntry(3)->vehicle_id)->toBeNull()->and(logbookEntry(3)->trip_type)->toBe('uebung')
        ->and(logbookEntry(4)->trip_type)->toBe('werkstatt')->and(logbookEntry(4)->distance_km)->toBe(0.0)
        ->and(logbookEntry(5)->trip_type)->toBe('bewegung')->and(logbookEntry(5)->source)->toBe('firetab')
        ->and(logbookEntry(6)->trip_type)->toBe('sonstige')->and(logbookEntry(6)->source)->toBe('admin');

    expect(implode("\n", $json['warnings']))
        ->toContain('2 logbook entr(y/ies): vehicle gone or not imported, kept without vehicle (legacy ids: 2, 3)')
        ->toContain("1 logbook entr(y/ies): unknown trip type 'quatsch', set to sonstige (legacy ids: 2)")
        ->toContain('1 logbook entr(y/ies): negative distance, set to 0 (legacy ids: 2)')
        ->toContain('2 logbook entr(y/ies): creating account not imported, left empty (legacy ids: 2, 4)');
});

it('sets an unknown source to admin with a warning', function () {
    LegacySeed::users([['id' => 1]]);
    LegacySeed::logbook([['id' => 1]]);
    LegacyDatabase::connection()->statement('ALTER TABLE intra_fahrtenbuch MODIFY source VARCHAR(20) NOT NULL');
    LegacyDatabase::connection()->table('intra_fahrtenbuch')->update(['source' => 'tablet']);

    try {
        $json = LegacySeed::run(['logbook']);
    } finally {
        LegacyDatabase::connection()->table('intra_fahrtenbuch')->delete();
        LegacyDatabase::connection()->statement("ALTER TABLE intra_fahrtenbuch MODIFY source ENUM('enotf','firetab','admin') NOT NULL DEFAULT 'admin'");
    }

    expect(logbookEntry(1)->source)->toBe('admin')
        ->and(implode("\n", $json['warnings']))->toContain("1 logbook entr(y/ies): unknown source 'tablet', set to admin (legacy ids: 1)");
});

it('imports a second time with identical counts and results', function () {
    seedLogbookCases();
    $first = LegacySeed::run(LOGBOOK_ENTITIES);
    $before = logbookCounts();
    $rows = DB::table('logbook_entries')->orderBy('id')->get()->toArray();

    $second = LegacySeed::run(LOGBOOK_ENTITIES);

    expect($second['exit'])->toBe(0)->and($second['entities'])->toBe($first['entities'])
        ->and(logbookCounts())->toBe($before)
        ->and(DB::table('logbook_entries')->orderBy('id')->get()->toArray())->toEqual($rows);
});

it('leaves no trace on a dry run', function () {
    seedLogbookCases();
    $before = logbookCounts();

    $json = LegacySeed::run(LOGBOOK_ENTITIES, dry: true);

    expect($json['exit'])->toBe(0)->and($json['dry_run'])->toBeTrue()
        ->and($json['entities']['logbook']['written'])->toBe(6)
        ->and(logbookCounts())->toBe($before);
});

it('brings an entry deleted in the new instance back', function () {
    seedLogbookCases();
    LegacySeed::run(LOGBOOK_ENTITIES);
    LogbookEntry::query()->whereKey(mappedId('logbook', 5))->delete();

    $json = LegacySeed::run(['logbook']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['logbook']['written'])->toBe(6)
        ->and(DB::table('logbook_entries')->count())->toBe(6)
        ->and(logbookEntry(5)->trip_type)->toBe('bewegung');
});

it('records a row that cannot be stored as failed and goes on', function () {
    seedLogbookCases();
    DB::table('legacy_id_map')->insert(['entity' => 'user', 'legacy_id' => '55', 'new_id' => 999999]);

    $json = LegacySeed::run(['logbook']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['logbook'])->toBe(['read' => 6, 'written' => 5, 'skipped' => 0, 'failed' => 1])
        ->and(implode("\n", $json['failures']))->toContain('legacy logbook entry 4');
});

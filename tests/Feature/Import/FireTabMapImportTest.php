<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
});

function fireTabMapImportSeed(): void
{
    LegacySeed::users([['id' => 1]]);
    LegacySeed::personnel([['id' => 1, 'fullname' => 'Lea Leiter']]);
    LegacySeed::vehicles([['id' => 1, 'name' => 'HLF 1']]);
    LegacySeed::fire('incidents', [['id' => 1]]);
    LegacySeed::fire('incident_map_markers', [
        ['id' => 1, 'pos_x' => 12.34, 'pos_y' => 67.89, 'description' => 'Brand', 'grundzeichen' => 'ohne', 'symbol' => 'vollbrand', 'text' => 'EL]]>',
            'created_by' => 1, 'operator_id' => 1, 'vehicle_id' => 1],
        ['id' => 2, 'marker_type' => 'foo', 'symbol' => 'feuer', 'pos_x' => -5, 'pos_y' => 120, 'created_by' => 50, 'vehicle_id' => 8, 'operator_id' => 9],
        ['id' => 3, 'incident_id' => 77],
        ['id' => 4, 'marker_type' => 'vehicle_1'],
    ]);
    LegacySeed::fire('incident_map_zones', [
        ['id' => 1, 'points' => '[{"x":10.123,"y":10},{"x":20,"y":10},{"x":"20","y":20}]', 'color' => '#ABCDEF', 'created_by' => 1, 'vehicle_id' => 1],
        ['id' => 2, 'points' => '[{"x":1,"y":2}]', 'color' => 'red'],
        ['id' => 3, 'points' => 'kaputt'],
    ]);
    DB::table('legacy_id_map')->insert([
        ['entity' => 'user', 'legacy_id' => '50', 'new_id' => 999999],
        ['entity' => 'vehicle', 'legacy_id' => '8', 'new_id' => 999999],
    ]);
}

function fmiRow(string $table, string $entity, int $legacyId): object
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return DB::table($table)->where('id', $id)->first();
}

function fireTabMapImportId(string $entity, int $legacyId): int
{
    return (int) DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');
}

/** @return array<string, mixed> */
function fmiDump(): array
{
    return collect(['fire_map_markers', 'fire_map_zones', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
}

it('imports markers and zones with maps, clamping, hidden invalid zones and counted warnings', function () {
    fireTabMapImportSeed();
    $json = LegacySeed::run(['firetab']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['fire_map_marker'])->toBe(['read' => 4, 'written' => 3, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['fire_map_zone'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0]);

    $m = fmiRow('fire_map_markers', 'fire_map_marker', 1);
    expect([(int) $m->fire_incident_id, $m->marker_type, (float) $m->pos_x, (float) $m->pos_y, $m->description, $m->grundzeichen, $m->symbol, $m->text,
        (int) $m->created_by_user_id, (int) $m->personnel_id, (int) $m->vehicle_id, $m->created_at])
        ->toBe([fireTabMapImportId('fire_incident', 1), 'Einsatzort', 12.34, 67.89, 'Brand', 'ohne', 'vollbrand', 'EL',
            fireTabMapImportId('user', 1), fireTabMapImportId('personnel', 1), fireTabMapImportId('vehicle', 1), '2026-07-15 10:00:00']);
    $m2 = fmiRow('fire_map_markers', 'fire_map_marker', 2);
    expect([$m2->symbol, $m2->marker_type, (float) $m2->pos_x, (float) $m2->pos_y, $m2->created_by_user_id, $m2->personnel_id, $m2->vehicle_id])
        ->toBe([null, 'custom', 0.0, 100.0, null, null, null])
        ->and(fmiRow('fire_map_markers', 'fire_map_marker', 4)->marker_type)->toBe('vehicle_'.fireTabMapImportId('vehicle', 1));

    $z = fmiRow('fire_map_zones', 'fire_map_zone', 1);
    expect([json_decode($z->points, true), $z->color, (bool) $z->is_valid, (int) $z->created_by_user_id, (int) $z->vehicle_id])
        ->toBe([[['x' => 10.12, 'y' => 10], ['x' => 20, 'y' => 10], ['x' => 20, 'y' => 20]], '#abcdef', true, fireTabMapImportId('user', 1), fireTabMapImportId('vehicle', 1)]);
    $z2 = fmiRow('fire_map_zones', 'fire_map_zone', 2);
    expect([json_decode($z2->points, true), $z2->color, (bool) $z2->is_valid])->toBe([[['x' => 1, 'y' => 2]], '#dc3545', false])
        ->and([json_decode(fmiRow('fire_map_zones', 'fire_map_zone', 3)->points), (bool) fmiRow('fire_map_zones', 'fire_map_zone', 3)->is_valid])->toBe(['kaputt', false]);

    foreach ([
        'fire_map_marker(s) skipped: incident not imported' => '(legacy ids: 3)',
        'fire_map_marker(s): unknown marker type' => '(legacy ids: 2)',
        'fire_map_marker(s): position outside 0-100' => '(legacy ids: 2)',
        'fire_map_marker(s): creating account not imported' => '(legacy ids: 2)',
        'fire_map_marker(s): vehicle not imported' => '(legacy ids: 2)',
        'fire_map_marker(s): operator (personnel file) not imported' => '(legacy ids: 2)',
        'fire_map_zone(s): unusable points' => '(legacy ids: 2, 3)',
        "fire_map_marker(s): tactical symbol symbol 'feuer' is not a known library value, dropped" => '(legacy ids: 2)',
        'fire_map_zone(s): colour not #rrggbb' => '(legacy ids: 2)',
        'fire_map_zone(s): colour outside the 9 form colours' => '(legacy ids: 1)',
    ] as $needle => $ids) {
        expect((string) collect($json['warnings'])->first(fn (string $w) => str_contains($w, $needle)))->toContain($ids);
    }
});

it('gives the same map on a second run and leaves no traces on a dry run', function () {
    fireTabMapImportSeed();
    $before = fmiDump();
    $dry = LegacySeed::run(['firetab'], dry: true);
    expect($dry['entities']['fire_map_zone']['written'])->toBe(3)->and(fmiDump())->toBe($before);

    LegacySeed::run(['firetab']);
    $dump = fmiDump();
    $this->travel(2)->hours();
    LegacySeed::run(['firetab']);

    expect(fmiDump())->toBe($dump);
});

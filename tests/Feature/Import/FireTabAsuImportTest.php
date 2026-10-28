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

function fasSeed(): void
{
    LegacySeed::personnel([['id' => 1, 'fullname' => 'Lea Leiter']]);
    LegacySeed::vehicles([['id' => 1, 'name' => 'HLF 1']]);
    LegacySeed::fire('incidents', [['id' => 1], ['id' => 2]]);
    LegacySeed::fire('asu_trupps', [
        ['id' => 1, 'name' => 'AT 1', 'vehicle_id' => 1, 'task' => 'Innenangriff', 'expected_minutes' => 25],
        ['id' => 2, 'role' => 'xx', 'vehicle_id' => 8],
        ['id' => 3, 'incident_id' => 77],
    ]);
    LegacySeed::fire('asu_members', [
        ['id' => 1, 'name' => 'Weber, M', 'mitarbeiter_id' => 1],
        ['id' => 2, 'position' => 'tm1', 'mitarbeiter_id' => 9],
        ['id' => 3, 'trupp_id' => 3],
    ]);
    LegacySeed::fire('asu_events', [
        ['id' => 1, 'type' => 'angeschlossen', 'member_id' => 1, 'bar' => 300, 'device' => 'HLF 1', 'client_uuid' => 'AbC-1'],
        ['id' => 2, 'member_id' => 99, 'bar' => 250, 'created_at' => '2026-07-15 10:03:00'],
        ['id' => 3, 'type' => 'mayday', 'note' => '{"reason":"Atemnot"}', 'voided_at' => '2026-07-15 10:05:00'],
        ['id' => 4, 'trupp_id' => 3, 'incident_id' => 77],
        ['id' => 5, 'trupp_id' => 50],
        ['id' => 6, 'type' => 'foo'],
        ['id' => 7, 'incident_id' => 2],
    ]);
    LegacySeed::fire('incident_asu', [
        ['id' => 1, 'supervisor' => 'Max', 'mission_location' => 'Hauptstr. 1', 'mission_date' => '2025-03-05', 'timestamp' => '2025-04-13 06:38:43',
            'data' => '{"supervisor":"Max","trupps":[{"name":"T1","x":[1,2]}]}'],
        ['id' => 2, 'incident_id' => 77],
    ]);
    DB::table('legacy_id_map')->insert(['entity' => 'vehicle', 'legacy_id' => '8', 'new_id' => 999999]);
}

function fasId(string $entity, int $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

function fasRow(string $table, string $entity, int $legacyId): object
{
    return DB::table($table)->where('id', fasId($entity, $legacyId))->first();
}

/** @return array<string, mixed> */
function fasDump(): array
{
    return collect(['fire_asu_trupps', 'fire_asu_members', 'fire_asu_events', 'fire_asu_protocols', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
}

it('imports Trupps, members, events and old protocols with maps, voids, orphans and failing rows', function () {
    fasSeed();
    $json = LegacySeed::run(['firetab']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['fire_asu_trupp'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['fire_asu_member'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['fire_asu_event'])->toBe(['read' => 7, 'written' => 4, 'skipped' => 2, 'failed' => 1])
        ->and($json['entities']['fire_asu_protocol'])->toBe(['read' => 2, 'written' => 1, 'skipped' => 1, 'failed' => 0])
        ->and(implode(' ', $json['failures']))->toContain("legacy fire_asu_event 6: RuntimeException: unknown event type 'foo'");

    $t = fasRow('fire_asu_trupps', 'fire_asu_trupp', 1);
    expect([(int) $t->fire_incident_id, $t->name, $t->role, $t->task, (int) $t->expected_minutes, (int) $t->vehicle_id])
        ->toBe([fasId('fire_incident', 1), 'AT 1', 'angriff', 'Innenangriff', 25, fasId('vehicle', 1)]);
    $t2 = fasRow('fire_asu_trupps', 'fire_asu_trupp', 2);
    expect([$t2->role, $t2->vehicle_id])->toBe(['angriff', null]);
    $m = fasRow('fire_asu_members', 'fire_asu_member', 1);
    expect([$m->position, $m->name, (int) $m->personnel_id])->toBe(['tf', 'Weber, M', fasId('personnel', 1)])
        ->and(fasRow('fire_asu_members', 'fire_asu_member', 2)->personnel_id)->toBeNull();

    $e = fasRow('fire_asu_events', 'fire_asu_event', 1);
    expect([$e->type, (int) $e->bar, (int) $e->member_id, $e->client_uuid, $e->device, $e->occurred_at, $e->received_at, $e->voided_at])
        ->toBe(['angeschlossen', 300, fasId('fire_asu_member', 1), 'AbC-1', 'HLF 1', '2026-07-15 10:00:00', '2026-07-15 10:00:00', null]);
    expect(fasRow('fire_asu_events', 'fire_asu_event', 2))->member_id->toBeNull()->occurred_at->toBe('2026-07-15 10:03:00');
    $may = fasRow('fire_asu_events', 'fire_asu_event', 3);
    expect([$may->note, $may->voided_at, $may->voided_by])->toBe(['{"reason":"Atemnot"}', '2026-07-15 10:05:00', null])
        ->and((int) fasRow('fire_asu_events', 'fire_asu_event', 7)->fire_incident_id)->toBe(fasId('fire_incident', 2));

    $p = fasRow('fire_asu_protocols', 'fire_asu_protocol', 1);
    expect([$p->supervisor, $p->mission_location, substr((string) $p->mission_date, 0, 10), $p->recorded_at])
        ->toBe(['Max', 'Hauptstr. 1', '2025-03-05', '2025-04-13 06:38:43'])
        ->and(json_decode($p->payload, true))->toBe(['supervisor' => 'Max', 'trupps' => [['name' => 'T1', 'x' => [1, 2]]]]);

    foreach ([
        'fire_asu_trupp(s) skipped: incident not imported' => '(legacy ids: 3)',
        'fire_asu_trupp(s): unknown role' => '(legacy ids: 2)',
        'fire_asu_trupp(s): vehicle not imported' => '(legacy ids: 2)',
        'fire_asu_member(s) skipped: Trupp not imported' => '(legacy ids: 3)',
        'fire_asu_member(s): personnel file not imported' => '(legacy ids: 2)',
        'fire_asu_event(s) skipped: incident not imported' => '(legacy ids: 4)',
        'fire_asu_event(s) skipped: Trupp not imported (orphan)' => '(legacy ids: 5)',
        'fire_asu_event(s): member not imported' => '(legacy ids: 2)',
        'fire_asu_protocol(s) skipped: incident not imported' => '(legacy ids: 2)',
    ] as $needle => $ids) {
        expect((string) collect($json['warnings'])->first(fn (string $w) => str_contains($w, $needle)))->toContain($ids);
    }
});

it('gives the same result on a second run and leaves no traces on a dry run', function () {
    fasSeed();
    $before = fasDump();
    $dry = LegacySeed::run(['firetab'], dry: true);
    expect($dry['entities']['fire_asu_event']['written'])->toBe(4)->and(fasDump())->toBe($before);

    LegacySeed::run(['firetab']);
    $dump = fasDump();
    $this->travel(2)->hours();
    LegacySeed::run(['firetab']);

    expect(fasDump())->toBe($dump);
});

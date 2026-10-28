<?php

use App\Import\NotificationImporter;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'America/New_York', 'ignis.import.legacy_db_timezone' => 'Europe/Berlin']);
});

function ftiSeed(): void
{
    LegacySeed::personnel([['id' => 1, 'fullname' => 'Lea Leiter'], ['id' => 2]]);
    LegacySeed::users([['id' => 1, 'aktenid' => 1], ['id' => 2]]);
    LegacySeed::vehicles([['id' => 1, 'name' => 'HLF 1'], ['id' => 2, 'name' => 'DLK']]);
    LegacySeed::fire('incidents', [
        ['id' => 1, 'incident_number' => 'BITTE ÄNDERN!', 'leader_id' => 1, 'status' => 2, 'location_x' => '1.500000000', 'caller_name' => 'Max',
            'finalized' => 1, 'finalized_at' => '2026-07-15 09:00:00', 'finalized_by' => 2, 'archived' => 1, 'archived_by' => 50,
            'owner_type' => 'halter', 'notes' => 'Notiz', 'created_by' => 1, 'updated_by' => 0, 'updated_at' => '2026-07-15 11:00:00'],
        ['id' => 2, 'status' => 7, 'leader_id' => 9],
        ['id' => 3, 'status' => 4, 'created_by' => 9, 'incident_number' => '   ', 'keyword' => ' B3 ', 'caller_name' => ' Eva '],
    ]);
    LegacySeed::fire('incident_vehicles', [
        ['id' => 1, 'vehicle_id' => 1, 'current_status' => '3', 'status_updated_at' => '2026-07-15 08:10:00'],
        ['id' => 2, 'vehicle_id' => 1, 'vehicle_name' => 'HLF 1', 'radio_name' => 'Florian 1', 'current_status' => '4', 'status_updated_at' => '2026-07-15 08:20:00'],
        ['id' => 3, 'vehicle_id' => 1, 'current_status' => 'C'],
        ['id' => 4, 'vehicle_id' => 9, 'vehicle_name' => 'LF Fremd'],
        ['id' => 5, 'from_other_org' => 1, 'vehicle_name' => 'RTW Nachbar', 'radio_name' => 'Rotkreuz 1'],
        ['id' => 6, 'incident_id' => 77],
    ]);
    LegacySeed::fire('incident_sitreps', [
        ['id' => 1, 'vehicle_radio_name' => 'Florian 1'],
        ['id' => 2, 'incident_id' => 2, 'source' => 'leitstelle', 'synced' => 1, 'report_time' => '2026-01-15 08:30:00'],
        ['id' => 3, 'source' => 'xx'],
    ]);
    LegacySeed::fire('incident_log', [
        ['id' => 1, 'incident_id' => 2, 'action_type' => 'created', 'action_description' => 'Einsatz automatisch durch Sync erstellt'],
        ['id' => 2, 'action_type' => 'vehicle_added', 'vehicle_id' => 1, 'operator_id' => 1, 'created_by' => 1],
        ['id' => 3, 'action_type' => 'foo', 'vehicle_id' => 9, 'operator_id' => 9, 'created_by' => 0],
        ['id' => 4, 'created_by' => 9],
    ]);
    LegacySeed::fire('status_queue', [['id' => 1, 'delivered' => 1], ['id' => 2], ['id' => 3]]);
}

function ftiId(string $entity, int $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

function ftiRow(string $table, string $entity, int $legacyId): object
{
    return DB::table($table)->where('id', ftiId($entity, $legacyId))->first();
}

/** @return array<string, mixed> */
function ftiDump(): array
{
    return collect(['fire_incidents', 'fire_incident_vehicles', 'fire_sitreps', 'fire_incident_log', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
}

/** @param array<string, mixed> $json */
function ftiWarning(array $json, string $needle): string
{
    return (string) collect($json['warnings'])->first(fn (string $w) => str_contains($w, $needle));
}

it('imports incidents, vehicles, sitreps and the log with the mapping and clock rules', function () {
    ftiSeed();
    DB::table('legacy_id_map')->insert(['entity' => 'user', 'legacy_id' => '50', 'new_id' => 999999]);
    $json = LegacySeed::run(['firetab']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['fire_incident'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['fire_incident_vehicle'])->toBe(['read' => 6, 'written' => 3, 'skipped' => 3, 'failed' => 0])
        ->and($json['entities']['fire_sitrep'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['fire_incident_log'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0]);

    $i = ftiRow('fire_incidents', 'fire_incident', 1);
    expect($i->number)->toBe('BITTE ÄNDERN!')->and($i->status)->toBe('approved')->and((int) $i->leader_personnel_id)->toBe(ftiId('personnel', 1))
        ->and((float) $i->location_x)->toBe(1.5)->and($i->caller_name)->toBe('Max')
        ->and($i->started_at)->toBe('2026-07-15 08:00:00')
        ->and($i->created_at)->toBe('2026-07-15 10:00:00')->and($i->updated_at)->toBe('2026-07-15 11:00:00')
        ->and($i->finalized_at)->toBe('2026-07-15 09:00:00')->and((int) $i->finalized_by)->toBe(ftiId('user', 2))
        ->and($i->archived_at)->toBe('2026-07-15 11:00:00')->and($i->archived_by)->toBeNull()
        ->and((int) $i->created_by)->toBe(ftiId('user', 1))->and($i->updated_by)->toBeNull()
        ->and($i->notes)->toBe("Notiz\n\nArt Geschädigter/Eigentümer (Altbestand): halter");
    $emd = ftiRow('fire_incidents', 'fire_incident', 2);
    expect($emd->created_at)->toBe('2026-07-15 12:00:00')->and($emd->status)->toBe('new')->and($emd->leader_personnel_id)->toBeNull()
        ->and($emd->started_at)->toBe('2026-07-15 08:00:00');
    $i3 = ftiRow('fire_incidents', 'fire_incident', 3);
    expect([$i3->number, $i3->keyword, $i3->caller_name])->toBe([null, 'B3', 'Eva']);
    expect(ftiRow('fire_incidents', 'fire_incident', 3)->status)->toBe('hidden')->and(ftiRow('fire_incidents', 'fire_incident', 3)->created_at)->toBe('2026-07-15 10:00:00');

    $v = ftiRow('fire_incident_vehicles', 'fire_incident_vehicle', 2);
    expect((int) $v->vehicle_id)->toBe(ftiId('vehicle', 1))->and($v->fms_status)->toBe('4')->and($v->fms_status_at)->toBe('2026-07-15 08:20:00')
        ->and((bool) $v->is_external)->toBeFalse()->and($v->radio_name)->toBe('Florian 1')
        ->and(ftiId('fire_incident_vehicle', 1))->toBeNull()->and(ftiId('fire_incident_vehicle', 3))->toBeNull()
        ->and(DB::table('fire_incident_vehicles')->where('vehicle_id', ftiId('vehicle', 1))->count())->toBe(1);
    $ext = ftiRow('fire_incident_vehicles', 'fire_incident_vehicle', 4);
    expect([$ext->vehicle_id, (bool) $ext->is_external, $ext->name])->toBe([null, true, 'LF Fremd'])
        ->and((bool) ftiRow('fire_incident_vehicles', 'fire_incident_vehicle', 5)->is_external)->toBeTrue();

    $s = ftiRow('fire_sitreps', 'fire_sitrep', 1);
    expect($s->reported_at)->toBe('2026-07-15 08:30:00')->and($s->source)->toBe('local')->and($s->reporter_name)->toBe('Florian 1')
        ->and((bool) $s->is_important)->toBeFalse()
        ->and(ftiRow('fire_sitreps', 'fire_sitrep', 2)->source)->toBe('dispatch')->and(ftiRow('fire_sitreps', 'fire_sitrep', 2)->reported_at)->toBe('2026-01-15 08:30:00')
        ->and(ftiRow('fire_sitreps', 'fire_sitrep', 3)->source)->toBe('local')
        ->and(DB::table('fire_sitreps')->whereNull('synced_at')->count())->toBe(0);

    $l = ftiRow('fire_incident_log', 'fire_incident_log', 2);
    expect([$l->action, (int) $l->vehicle_id, (int) $l->personnel_id, (int) $l->user_id])->toBe(['vehicle_added', ftiId('vehicle', 1), ftiId('personnel', 1), ftiId('user', 1)]);
    $l = ftiRow('fire_incident_log', 'fire_incident_log', 3);
    expect([$l->action, $l->vehicle_id, $l->personnel_id, $l->user_id])->toBe(['foo', null, null, null]);

    expect(DB::table('fire_status_queue')->count())->toBe(0)
        ->and(implode(' ', $json['notes']))->toContain('1 incident(s) created by the EMD sync');
    foreach ([
        'fire_incident(s): unknown status \'7\'' => '(legacy ids: 2)',
        'fire_incident(s): leader (personnel file) not imported' => '(legacy ids: 2)',
        'fire_incident(s): editing account not imported' => '(legacy ids: 1)',
        'fire_incident(s): creating account not imported' => '(legacy ids: 3)',
        'fire_incident(s): archived without a time' => '(legacy ids: 1)',
        'fire_incident(s): archived by account not imported' => '(legacy ids: 1)',
        'fire_incident(s): owner type' => '(legacy ids: 1)',
        'fire_incident_vehicle(s) skipped: duplicate of (incident, vehicle), merged into legacy row 2' => '(legacy ids: 1, 3)',
        'fire_incident_vehicle(s) skipped: incident not imported' => '(legacy ids: 6)',
        'fire_incident_vehicle(s): vehicle not imported, kept as external' => '(legacy ids: 4)',
        'fire_sitrep(s): unknown source' => '(legacy ids: 3)',
        'fire_incident_log(s): unknown action type' => '(legacy ids: 3)',
        'fire_incident_log(s): vehicle not imported (orphan)' => '(legacy ids: 3)',
        'fire_incident_log(s): operator (personnel file) not imported' => '(legacy ids: 3)',
        'fire_incident_log(s): account not imported' => '(legacy ids: 4)',
        '2 undelivered status queue' => '',
    ] as $needle => $ids) {
        expect(ftiWarning($json, $needle))->toContain($ids)->not->toBe('');
    }
});

it('gives the same result on a second run (synced_at kept)', function () {
    ftiSeed();
    LegacySeed::run(['firetab']);
    $dump = ftiDump();
    $this->travel(2)->hours();
    LegacySeed::run(['firetab']);

    expect(ftiDump())->toBe($dump);
});

it('leaves no traces on a dry run', function () {
    ftiSeed();
    $before = ftiDump();
    $json = LegacySeed::run(['firetab'], dry: true);

    expect($json['exit'])->toBe(0)->and($json['entities']['fire_incident']['written'])->toBe(3)->and(ftiDump())->toBe($before);
});

it('refuses a legacy database without the timezone fix', function () {
    ftiSeed();
    LegacyDatabase::connection()->table('phinxlog')->where('version', 20250607000122)->delete();

    expect(LegacySeed::run(['firetab'])['exit'])->toBe(1)->and(DB::table('fire_incidents')->count())->toBe(0);
});

it('rewrites fire_protocol notification links to the imported incidents', function () {
    config(['ignis.import.legacy_base_path' => '/intra/']);
    ftiSeed();
    LegacySeed::notifications([
        ['id' => 1, 'type' => 'fire_protocol', 'link' => '/intra/firetab/view?id=1'],
        ['id' => 2, 'type' => 'fire_protocol', 'link' => '/firetab/view?id=1&tab=bericht'],
        ['id' => 3, 'type' => 'fire_protocol', 'link' => '/einsatz/view.php?id=2'],
        ['id' => 4, 'type' => 'fire_protocol', 'link' => '/intra/firetab/asu?incident_id=3'],
        ['id' => 5, 'type' => 'fire_protocol', 'link' => '/firetab/view?id=77'],
    ]);
    LegacySeed::run(['firetab', 'notification']);

    $link = fn (int $id) => json_decode((string) DB::table('notifications')->where('id', NotificationImporter::uuid($id))->value('data'), true)['link'];
    [$i1, $i2, $i3] = [ftiId('fire_incident', 1), ftiId('fire_incident', 2), ftiId('fire_incident', 3)];
    expect($link(1))->toBe("/firetab/incidents/{$i1}")->and($link(2))->toBe("/firetab/incidents/{$i1}?tab=bericht")
        ->and($link(3))->toBe("/firetab/incidents/{$i2}")->and($link(4))->toBe("/firetab/incidents/{$i3}?tab=atemschutz")
        ->and($link(5))->toBe('/firetab/list');
});

it('takes the SCBA settings and drops the guest switch', function () {
    LegacySeed::config(['FIRE_ASU_CONSUMPTION' => '12', 'FIRE_ASU_START_BAR' => '999', 'FIRE_INCIDENT_REQUIRE_USER_AUTH' => 'true']);
    $json = LegacySeed::run(['system_settings']);

    expect(app(Settings::class)->get('firetab.asu_consumption'))->toBe(12)->and(app(Settings::class)->get('firetab.asu_start_bar'))->toBe(300)
        ->and(implode(' ', $json['warnings']))->toContain('FIRE_ASU_START_BAR')
        ->and(implode(' ', $json['notes']))->toContain('FIRE_INCIDENT_REQUIRE_USER_AUTH nicht übernommen');
});

it('keeps the mapped duplicate row when the newest status moves to another legacy row', function () {
    ftiSeed();
    LegacySeed::run(['firetab']);
    $kept = ftiId('fire_incident_vehicle', 2);
    LegacyDatabase::connection()->table('intra_fire_incident_vehicles')->where('id', 1)->update(['current_status' => '7', 'status_updated_at' => '2026-07-15 09:00:00']);
    $json = LegacySeed::run(['firetab']);

    $rows = DB::table('fire_incident_vehicles')->where('vehicle_id', ftiId('vehicle', 1))->get();
    expect($json['entities']['fire_incident_vehicle']['failed'])->toBe(0)->and($rows)->toHaveCount(1)
        ->and((int) $rows[0]->id)->toBe($kept)->and($rows[0]->fms_status)->toBe('7')->and($rows[0]->fms_status_at)->toBe('2026-07-15 09:00:00')
        ->and(ftiId('fire_incident_vehicle', 1))->toBeNull()
        ->and(ftiWarning($json, 'merged into legacy row 2'))->toContain('(legacy ids: 1, 3)');
});

it('does not require the timezone fix when the legacy instance has no incidents', function () {
    LegacyDatabase::connection()->table('phinxlog')->where('version', 20250607000122)->delete();

    expect(LegacySeed::run(['firetab'])['exit'])->toBe(0);
});

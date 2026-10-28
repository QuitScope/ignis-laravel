<?php

use App\Models\User;
use App\Models\VehicleRegistryEntry;
use App\Support\Settings;
use App\Vehicles\VehicleRegistryIntake;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function vri_fixture(): array
{
    $json = json_decode((string) file_get_contents(base_path('tests/Fixtures/emd/vehicle_registry.json')), true, 32, JSON_THROW_ON_ERROR);

    return $json['vehicle_registry'];
}

function vri_row(array $over = []): array
{
    return ['id' => 9, 'department' => 'RD', 'value' => 'RTW 9', 'valuelong' => null, 'job' => null, 'grade' => 0,
        'image' => null, 'type' => 'RTW', 'locked' => 0, 'funkkanal' => null, ...$over];
}

it('stores the fixture as ef_bridge sends it (contract)', function () {
    $result = app(VehicleRegistryIntake::class)->accept(vri_fixture());

    expect($result->received)->toBe(9)->and($result->stored)->toBe(8)->and($result->skipped)->toBe(1)->and($result->error)->toBeNull();
    $rows = VehicleRegistryEntry::orderBy('id')->get()->keyBy(fn (VehicleRegistryEntry $e) => $e->emd_vehicle_id ?? 0);
    expect($rows->keys()->all())->toBe([1, 2, 3, 4, 6, 7, 8, 0]);

    expect($rows[1]->only(['name', 'identifier', 'type', 'service', 'department', 'value_long', 'job', 'image', 'radio_channel', 'status']))->toBe([
        'name' => 'RTW 1/83-1', 'identifier' => 'rtw_1_83-1', 'type' => 'RTW', 'service' => 2, 'department' => 'Rettungsdienst',
        'value_long' => 'Rettungswagen 1/83-1', 'job' => 'ambulance', 'image' => 'rtw.png', 'radio_channel' => 'RD-1', 'status' => 'pending',
    ]);
    expect($rows[2]->service)->toBe(1)->and($rows[2]->type)->toBe('nef');
    expect($rows[3]->service)->toBe(2)->and($rows[3]->value_long)->toBeNull();
    expect($rows[4]->service)->toBe(0);
    expect($rows[6]->service)->toBe(1);
    expect($rows[7]->service)->toBe(0)->and($rows[7]->type)->toBeNull()->and($rows[7]->identifier)->toBe('kdow_1_10-1');
    expect($rows[8]->only(['name', 'type', 'service', 'department', 'value_long', 'job', 'image', 'radio_channel']))->toBe([
        'name' => 'MTW 1/19-1', 'type' => 'MTW', 'service' => 0, 'department' => null, 'value_long' => null, 'job' => null, 'image' => null, 'radio_channel' => null,
    ])->and($rows[8]->payload)->toBe(['id' => 8, 'value' => 'MTW 1/19-1', 'type' => 'MTW', 'locked' => true]);
    expect($rows[0]->name)->toBe('ITW 1/84-1')->and($rows[0]->service)->toBe(1)->and($rows[0]->payload['locked'])->toBeFalse();
    expect($rows[3]->payload)->toBe(vri_fixture()[2]);
});

it('maps every listed type and nothing else', function (string $type, int $service) {
    app(VehicleRegistryIntake::class)->accept([vri_row(['type' => $type])]);

    expect(VehicleRegistryEntry::sole()->service)->toBe($service);
})->with([
    ['NEF', 1], ['NAW', 1], ['ITW', 1], ['RTH', 1], ['ITH', 1], ['naw', 1], [' ith ', 1],
    ['RTW', 2], ['KTW', 2], ['NTW', 2], ['N-KTW', 2], ['NKTW', 2], ['S-RTW', 2], ['s-rtw', 2],
    ['HLF', 0], ['FW', 0], ['', 0], ['RTW2', 0],
]);

it('replaces only pending entries and leaves decided ones', function () {
    $old = VehicleRegistryEntry::factory()->create();
    $accepted = VehicleRegistryEntry::factory()->create(['status' => 'accepted']);
    $rejected = VehicleRegistryEntry::factory()->create(['status' => 'rejected']);

    app(VehicleRegistryIntake::class)->accept([vri_row()]);

    expect(VehicleRegistryEntry::find($old->id))->toBeNull()
        ->and(VehicleRegistryEntry::find($accepted->id))->not->toBeNull()
        ->and(VehicleRegistryEntry::find($rejected->id))->not->toBeNull()
        ->and(VehicleRegistryEntry::where('status', 'pending')->count())->toBe(1);
});

it('clears the request flag and records the sync time after a run', function () {
    $settings = app(Settings::class);
    $settings->set('vehicles.registry_requested_at', '2026-10-19T10:00:00+00:00');
    $this->travelTo('2026-10-19 12:00:00');

    app(VehicleRegistryIntake::class)->accept([vri_row()]);

    expect($settings->has('vehicles.registry_requested_at'))->toBeFalse()
        ->and($settings->get('emd.last_sync_at'))->toBe(now()->toIso8601String());
});

it('cleans identifiers, trims and cuts texts to their columns', function () {
    app(VehicleRegistryIntake::class)->accept([
        vri_row(['id' => 1, 'value' => '  Löschzug "A" <script>  ']),
        vri_row(['id' => 2, 'value' => str_repeat('ä', 300), 'department' => str_repeat('d', 300), 'image' => str_repeat('i', 120), 'funkkanal' => str_repeat('ü', 60)]),
        vri_row(['id' => 3, 'value' => "RTW\0 3"]),
        vri_row(['id' => 4, 'value' => 42, 'type' => 7]),
    ]);

    $rows = VehicleRegistryEntry::all()->keyBy('emd_vehicle_id');
    expect($rows[1]->name)->toBe('Löschzug "A" <script>')
        ->and($rows[1]->identifier)->toBe('l__schzug__a___script_');
    expect(mb_strlen($rows[2]->name))->toBe(255)->and(strlen($rows[2]->identifier))->toBe(255)
        ->and(mb_strlen($rows[2]->department))->toBe(255)->and(mb_strlen($rows[2]->image))->toBe(100)
        ->and($rows[2]->radio_channel)->toBe(str_repeat('ü', 50));
    expect($rows[3]->name)->toBe('RTW 3');
    expect($rows[4]->name)->toBe('42')->and($rows[4]->type)->toBe('7');
});

it('skips rows it cannot store and counts them', function () {
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => ['j' => ['k' => ['l' => ['m' => ['n' => ['o' => ['p' => ['q' => 1]]]]]]]]]]]]]]]]];

    $result = app(VehicleRegistryIntake::class)->accept([
        'not a row', 17, null,
        vri_row(['value' => '']),
        vri_row(['value' => '   ']),
        vri_row(['value' => null]),
        vri_row(['value' => ['RTW']]),
        vri_row(['value' => "\xC3\x28"]),
        vri_row(['department' => "\xFF"]),
        vri_row(['grade' => str_repeat('x', 16 * 1024)]),
        vri_row(['grade' => $deep]),
        vri_row(['value' => 'RTW ok']),
    ]);

    expect($result->received)->toBe(12)->and($result->stored)->toBe(1)->and($result->skipped)->toBe(11)
        ->and(VehicleRegistryEntry::sole()->name)->toBe('RTW ok');
});

it('keeps only a valid unsigned EMD id', function () {
    app(VehicleRegistryIntake::class)->accept([
        vri_row(['value' => 'A', 'id' => '12']), vri_row(['value' => 'B', 'id' => -1]), vri_row(['value' => 'C', 'id' => 'x']),
        vri_row(['value' => 'D', 'id' => 99999999999]), vri_row(['value' => 'E', 'id' => 1.5]),
    ]);

    expect(VehicleRegistryEntry::orderBy('name')->pluck('emd_vehicle_id')->all())->toBe([12, null, null, null, null]);
});

it('refuses more than 5000 rows without touching the queue', function () {
    $pending = VehicleRegistryEntry::factory()->create();
    app(Settings::class)->set('vehicles.registry_requested_at', 'x');

    $result = app(VehicleRegistryIntake::class)->accept(array_fill(0, 5001, vri_row()));

    expect($result->error)->toBe('too_many_rows')->and($result->received)->toBe(5001)->and($result->stored)->toBe(0)
        ->and(VehicleRegistryEntry::sole()->id)->toBe($pending->id)
        ->and(app(Settings::class)->has('vehicles.registry_requested_at'))->toBeTrue();
});

it('stores exactly 5000 rows', function () {
    $rows = array_map(fn (int $i) => vri_row(['id' => $i, 'value' => 'RTW '.$i]), range(1, 5000));

    $result = app(VehicleRegistryIntake::class)->accept($rows);

    expect($result->stored)->toBe(5000)->and($result->error)->toBeNull()->and(VehicleRegistryEntry::count())->toBe(5000);
});

it('bounds every insert by payload size', function () {
    $rows = array_map(fn (int $i) => vri_row(['id' => $i, 'value' => 'RTW '.$i, 'grade' => str_repeat('g', 15 * 1024)]), range(1, 80));
    $inserts = [];
    DB::listen(function (QueryExecuted $q) use (&$inserts) {
        if (str_starts_with($q->sql, 'insert into "vehicle_registry_entries"') || str_starts_with($q->sql, 'insert into `vehicle_registry_entries`')) {
            $inserts[] = array_sum(array_map(fn ($b) => is_string($b) ? strlen($b) : 8, $q->bindings));
        }
    });

    $result = app(VehicleRegistryIntake::class)->accept($rows);

    expect($result->stored)->toBe(80)->and(VehicleRegistryEntry::count())->toBe(80)
        ->and(count($inserts))->toBeGreaterThan(1)
        ->and(max($inserts))->toBeLessThanOrEqual(VehicleRegistryIntake::MAX_INSERT_BYTES + 32 * 1024);
});

it('rolls the whole run back when an insert fails midway', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('trigger syntax is SQLite');
    }
    DB::statement("CREATE TRIGGER vri_boom BEFORE INSERT ON vehicle_registry_entries WHEN NEW.name = 'BOOM' BEGIN SELECT RAISE(ABORT, 'boom'); END");
    $pending = VehicleRegistryEntry::factory()->count(2)->create();
    app(Settings::class)->set('vehicles.registry_requested_at', 'x');
    $rows = array_map(fn (int $i) => vri_row(['value' => 'RTW '.$i]), range(1, 600));
    $rows[] = vri_row(['value' => 'BOOM']);

    expect(fn () => app(VehicleRegistryIntake::class)->accept($rows))->toThrow(QueryException::class);

    expect(VehicleRegistryEntry::orderBy('id')->pluck('id')->all())->toBe($pending->pluck('id')->all())
        ->and(app(Settings::class)->has('vehicles.registry_requested_at'))->toBeTrue()
        ->and(app(Settings::class)->has('emd.last_sync_at'))->toBeFalse();
});

it('empties the queue on an empty registry, as before', function () {
    VehicleRegistryEntry::factory()->count(2)->create();
    $e = VehicleRegistryEntry::factory()->create(['status' => 'accepted', 'processed_by' => User::factory()->create()->id]);

    $result = app(VehicleRegistryIntake::class)->accept([]);

    expect($result->stored)->toBe(0)->and($result->error)->toBeNull()->and(VehicleRegistryEntry::sole()->id)->toBe($e->id);
});

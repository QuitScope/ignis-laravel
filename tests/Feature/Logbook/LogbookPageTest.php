<?php

use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Logbook\Models\LogbookEntry;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'LM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['logbook.manage']);
    $this->manager = User::factory()->withRole($role)->create();
});

function pg_body(Vehicle $v, array $over = []): array
{
    return $over + ['vehicle_id' => $v->id, 'trip_date' => '2026-10-20', 'departed_at' => '08:30', 'arrived_at' => '09:15',
        'distance_km' => '12.5', 'reason' => 'Übung', 'trip_type' => 'uebung', 'driver_name' => '  Max Muster '];
}

function pg_entry(Vehicle $v, array $over = []): LogbookEntry
{
    $e = (new LogbookEntry)->forceFill($over + ['vehicle_id' => $v->id, 'vehicle_identifier' => $v->identifier, 'trip_date' => '2026-10-20',
        'departed_at' => '08:30', 'arrived_at' => '09:00', 'distance_km' => 10, 'trip_type' => 'dienst', 'driver_name' => 'A', 'source' => 'admin']);
    $e->save();

    return $e;
}

it('creates an entry with vehicle texts from the vehicle, source admin and the account', function () {
    $poi = Poi::factory()->create(['name' => 'Wache 1', 'city' => 'Musterstadt']);
    $v = Vehicle::factory()->create(['identifier' => 'RTW-01', 'station_poi_id' => $poi->id]);

    $this->actingAs($this->manager)->post('/logbook', pg_body($v))->assertRedirect();

    $e = LogbookEntry::sole();
    expect($e->vehicle_id)->toBe($v->id)->and($e->vehicle_identifier)->toBe('RTW-01')->and($e->station_name)->toBe('Wache 1 (Musterstadt)')
        ->and($e->driver_name)->toBe('Max Muster')->and($e->source)->toBe('admin')->and($e->created_by)->toBe($this->manager->id)
        ->and($e->trip_date)->toBe('2026-10-20')->and(substr($e->departed_at, 0, 5))->toBe('08:30')->and($e->distance_km)->toBe(12.5);
    $a = Activity::query()->where('log_name', 'Fahrtenbuch')->sole();
    expect($a->description)->toBe("Fahrtenbuch-Eintrag erstellt [ID: {$e->id}]");
});

it('ignores source, created_by and vehicle texts sent in the body', function () {
    $v = Vehicle::factory()->create(['identifier' => 'RTW-01']);
    $other = User::factory()->create();

    $this->actingAs($this->manager)->post('/logbook', pg_body($v, [
        'source' => 'enotf', 'created_by' => $other->id, 'vehicle_identifier' => 'FAKE', 'station_name' => 'Fake', 'id' => 999,
    ]))->assertRedirect();

    $e = LogbookEntry::sole();
    expect($e->source)->toBe('admin')->and($e->created_by)->toBe($this->manager->id)->and($e->vehicle_identifier)->toBe('RTW-01')
        ->and($e->station_name)->toBeNull()->and($e->id)->not->toBe(999);

    $this->actingAs($this->manager)->put("/logbook/{$e->id}", pg_body($v, ['source' => 'firetab', 'created_by' => $other->id, 'vehicle_identifier' => 'FAKE']))->assertRedirect();
    $e->refresh();
    expect($e->source)->toBe('admin')->and($e->created_by)->toBe($this->manager->id)->and($e->vehicle_identifier)->toBe('RTW-01');
});

it('updates an entry, keeps the vehicle snapshot for the same vehicle and refreshes it for another', function () {
    $v = Vehicle::factory()->create(['identifier' => 'RTW-01']);
    $e = pg_entry($v);
    $v->update(['identifier' => 'RTW-RENAMED']);

    $this->actingAs($this->manager)->put("/logbook/{$e->id}", pg_body($v, ['driver_name' => 'Neu', 'distance_km' => '3']))->assertRedirect();
    $e->refresh();
    expect($e->driver_name)->toBe('Neu')->and($e->distance_km)->toBe(3.0)->and($e->vehicle_identifier)->toBe('RTW-01');

    $w = Vehicle::factory()->create(['identifier' => 'KTW-02']);
    $this->actingAs($this->manager)->put("/logbook/{$e->id}", pg_body($w))->assertRedirect();
    expect($e->refresh()->vehicle_id)->toBe($w->id)->and($e->vehicle_identifier)->toBe('KTW-02');
    expect(Activity::query()->where('description', 'like', 'Fahrtenbuch-Eintrag bearbeitet%')->count())->toBe(2);
});

it('deletes an entry and audits it', function () {
    $e = pg_entry(Vehicle::factory()->create());

    $this->actingAs($this->manager)->delete("/logbook/{$e->id}")->assertRedirect();

    expect(LogbookEntry::count())->toBe(0);
    expect(Activity::query()->where('log_name', 'Fahrtenbuch')->where('description', 'like', 'Fahrtenbuch-Eintrag gelöscht%')->exists())->toBeTrue();
});

it('validates the form', function () {
    foreach ([
        'date' => [['trip_date' => '20.10.2026'], 'trip_date'],
        'impossible date' => [['trip_date' => '2026-02-31'], 'trip_date'],
        'no date' => [['trip_date' => ''], 'trip_date'],
        'departure format' => [['departed_at' => '8h30'], 'departed_at'],
        'departure hour' => [['departed_at' => '25:00'], 'departed_at'],
        'arrival format' => [['arrived_at' => 'later'], 'arrived_at'],
        'negative km' => [['distance_km' => '-1'], 'distance_km'],
        'km text' => [['distance_km' => 'abc'], 'distance_km'],
        'km decimals' => [['distance_km' => '1.25'], 'distance_km'],
        'km too big' => [['distance_km' => '100000'], 'distance_km'],
        'type' => [['trip_type' => 'einsatzfahrt'], 'trip_type'],
        'vehicle missing' => [['vehicle_id' => 99999], 'vehicle_id'],
        'vehicle empty' => [['vehicle_id' => ''], 'vehicle_id'],
        'driver empty' => [['driver_name' => '   '], 'driver_name'],
        'driver array' => [['driver_name' => ['x']], 'driver_name'],
        'reason array' => [['reason' => ['x']], 'reason'],
    ] as [$over, $field]) {
        $v = Vehicle::factory()->create();

        $this->actingAs($this->manager)->post('/logbook', pg_body($v, $over))->assertSessionHasErrors($field);

        expect(LogbookEntry::count())->toBe(0);
    }
});

it('accepts zero km, no arrival and no reason', function () {
    $v = Vehicle::factory()->create();

    $this->actingAs($this->manager)->post('/logbook', pg_body($v, ['distance_km' => '0', 'arrived_at' => null, 'reason' => null]))->assertSessionHasNoErrors();

    $e = LogbookEntry::sole();
    expect($e->arrived_at)->toBeNull()->and($e->reason)->toBeNull()->and($e->distance_km)->toBe(0.0);
});

it('stores a trip past midnight and flags it on the page', function () {
    $v = Vehicle::factory()->create();

    $this->actingAs($this->manager)->post('/logbook', pg_body($v, ['departed_at' => '23:30', 'arrived_at' => '00:20']))->assertSessionHasNoErrors();

    expect(substr(LogbookEntry::sole()->arrived_at, 0, 5))->toBe('00:20');
    $this->actingAs($this->manager)->get('/logbook')->assertInertia(fn (Assert $p) => $p
        ->where('entries.data.0.departed_at', '23:30')->where('entries.data.0.arrived_at', '00:20')->where('entries.data.0.past_midnight', true));
});

it('keeps the entry with its vehicle identifier when the vehicle is deleted', function () {
    $v = Vehicle::factory()->create(['identifier' => 'RTW-01']);
    $e = pg_entry($v);

    $v->delete();

    $e->refresh();
    expect($e->vehicle_id)->toBeNull()->and($e->vehicle_identifier)->toBe('RTW-01');
    $this->actingAs($this->manager)->get('/logbook')->assertInertia(fn (Assert $p) => $p->where('entries.data.0.vehicle_identifier', 'RTW-01'));
});

it('computes the statistics over the filtered set and filters by vehicle, type and dates', function () {
    $a = Vehicle::factory()->create();
    $b = Vehicle::factory()->create();
    pg_entry($a, ['trip_date' => '2026-10-01', 'distance_km' => 10.5, 'trip_type' => 'dienst']);
    pg_entry($a, ['trip_date' => '2026-10-10', 'distance_km' => 4.5, 'trip_type' => 'einsatz']);
    pg_entry($b, ['trip_date' => '2026-10-20', 'distance_km' => 20, 'trip_type' => 'einsatz']);
    $get = fn (string $qs) => $this->actingAs($this->manager)->get('/logbook'.$qs);

    $get('')->assertInertia(fn (Assert $p) => $p->where('stats.count', 3)->where('stats.km', 35)->where('filters.vehicle', ''));
    $get("?vehicle={$a->id}")->assertInertia(fn (Assert $p) => $p->where('stats.count', 2)->where('stats.km', 15)->has('entries.data', 2));
    $get('?type=einsatz')->assertInertia(fn (Assert $p) => $p->where('stats.count', 2)->where('stats.km', 24.5));
    $get('?from=2026-10-05&to=2026-10-15')->assertInertia(fn (Assert $p) => $p->where('stats.count', 1)->where('stats.km', 4.5));
    $get("?vehicle={$b->id}&type=dienst")->assertInertia(fn (Assert $p) => $p->where('stats.count', 0)->where('stats.km', 0)->has('entries.data', 0));
});

it('ignores malformed filters instead of failing', function () {
    pg_entry(Vehicle::factory()->create());

    $this->actingAs($this->manager)->get('/logbook?vehicle[]=1&type[]=x&from=nonsense&to=2026-13-45&vehicle=abc')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->where('stats.count', 1)->where('filters.from', '')->where('filters.type', ''));
});

it('paginates by 25, newest first, and keeps the totals over all pages', function () {
    $v = Vehicle::factory()->create();
    foreach (range(1, 30) as $i) {
        pg_entry($v, ['trip_date' => sprintf('2026-09-%02d', $i), 'distance_km' => 1]);
    }

    $this->actingAs($this->manager)->get('/logbook')->assertInertia(fn (Assert $p) => $p
        ->has('entries.data', 25)->where('entries.total', 30)->where('entries.data.0.trip_date', '2026-09-30')->where('stats.count', 30)->where('stats.km', 30));
    $this->actingAs($this->manager)->get('/logbook?page=2')->assertInertia(fn (Assert $p) => $p->has('entries.data', 5)->where('stats.count', 30));
});

it('offers the trip types and every vehicle for the form', function () {
    Vehicle::factory()->create(['name' => 'Florian 1', 'identifier' => 'F1']);
    Vehicle::factory()->inactive()->create();

    $this->actingAs($this->manager)->get('/logbook')->assertInertia(fn (Assert $p) => $p
        ->where('types', ['einsatz', 'bewegung', 'werkstatt', 'uebung', 'dienst', 'sonstige'])->has('vehicles', 2));
});

it('has matching de and en labels for every key', function () {
    $de = json_decode(file_get_contents(base_path('modules/Logbook/lang/de.json')), true);
    $en = json_decode(file_get_contents(base_path('modules/Logbook/lang/en.json')), true);

    expect(array_keys($de))->toEqualCanonicalizing(array_keys($en));
    foreach (LogbookEntry::TYPES as $t) {
        expect($de)->toHaveKey("logbook.type.$t");
    }
});

it('lets an entry without a vehicle be edited without picking one and keeps its snapshot', function () {
    $v = Vehicle::factory()->create(['identifier' => 'RTW-01']);
    $e = pg_entry($v, ['vehicle_id' => null, 'vehicle_identifier' => 'ALT-9', 'station_name' => 'Wache Alt']);

    $this->actingAs($this->manager)->put("/logbook/{$e->id}", pg_body($v, ['vehicle_id' => null, 'driver_name' => 'Neu']))->assertRedirect()->assertSessionHasNoErrors();
    $e->refresh();
    expect($e->vehicle_id)->toBeNull()->and($e->vehicle_identifier)->toBe('ALT-9')->and($e->station_name)->toBe('Wache Alt')->and($e->driver_name)->toBe('Neu');

    $this->actingAs($this->manager)->put("/logbook/{$e->id}", pg_body($v))->assertRedirect();
    expect($e->refresh()->vehicle_id)->toBe($v->id)->and($e->vehicle_identifier)->toBe('RTW-01');
});

it('still requires a vehicle when creating or editing an entry that has one', function () {
    $v = Vehicle::factory()->create();
    $e = pg_entry($v);

    $this->actingAs($this->manager)->post('/logbook', pg_body($v, ['vehicle_id' => null]))->assertSessionHasErrors('vehicle_id');
    $this->actingAs($this->manager)->put("/logbook/{$e->id}", pg_body($v, ['vehicle_id' => null]))->assertSessionHasErrors('vehicle_id');
});

it('answers PUT and DELETE of a missing entry with 404', function () {
    $v = Vehicle::factory()->create();

    $this->actingAs($this->manager)->put('/logbook/999999', pg_body($v))->assertNotFound();
    $this->actingAs($this->manager)->delete('/logbook/999999')->assertNotFound();
});

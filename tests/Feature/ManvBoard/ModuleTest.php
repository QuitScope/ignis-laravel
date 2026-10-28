<?php

use App\Auth\Permissions;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ManvBoard\Enums\ManvIncidentStatus;
use Modules\ManvBoard\Enums\ResourceStatus;
use Modules\ManvBoard\Enums\ResourceType;
use Modules\ManvBoard\Enums\Sex;
use Modules\ManvBoard\Enums\Triage;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;

uses(RefreshDatabase::class);

function mv_incident(array $over = []): ManvIncident
{
    $i = (new ManvIncident)->forceFill($over + ['incident_number' => 'L-1', 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

function mv_resource(ManvIncident $i, string $label = 'RTW 1', array $over = []): ManvResource
{
    $r = (new ManvResource)->forceFill($over + ['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => $label]);
    $r->save();

    return $r;
}

function mv_patient(ManvIncident $i, string $number = '1', array $over = []): ManvPatient
{
    $p = (new ManvPatient)->forceFill($over + ['manv_incident_id' => $i->id, 'number' => $number]);
    $p->save();

    return $p;
}

it('creates the four tables with the expected columns', function () {
    foreach (['manv_incidents', 'manv_patients', 'manv_resources', 'manv_log'] as $t) {
        expect(Schema::hasTable($t))->toBeTrue();
    }
    expect(Schema::getColumnListing('manv_incidents'))->toContain('incident_number', 'lna_personnel_id', 'orgl_name', 'status', 'ended_at', 'updated_by')
        ->and(Schema::getColumnListing('manv_patients'))->toContain('manv_resource_id', 'vehicle_location', 'destination_poi_id', 'triaged_by', 'departed_at')
        ->and(Schema::getColumnListing('manv_resources'))->toContain('vehicle_id', 'crew', 'departed_at')
        ->and(Schema::getColumnListing('manv_log'))->toContain('user_id', 'user_name', 'subject_type', 'subject_id');
});

it('defaults and casts the enums', function () {
    $i = mv_incident();
    $p = mv_patient($i);
    $r = mv_resource($i);

    expect($i->fresh()->status)->toBe(ManvIncidentStatus::Active)
        ->and($p->fresh()->sex)->toBe(Sex::Unknown)
        ->and($p->fresh()->triage)->toBeNull()
        ->and($r->fresh()->type)->toBe(ResourceType::Vehicle)
        ->and($r->fresh()->status)->toBe(ResourceStatus::Available)
        ->and(array_map(fn ($c) => $c->value, Triage::cases()))->toBe(['SK1', 'SK2', 'SK3', 'SK4', 'SK5', 'SK6'])
        ->and(Triage::SK3->transportable())->toBeTrue()
        ->and(Triage::SK4->transportable())->toBeFalse();
});

it('rejects an unknown triage value and a duplicate patient number or resource label within a Lage only', function () {
    $a = mv_incident();
    $b = mv_incident(['incident_number' => 'L-2']);
    mv_patient($a, '7');
    mv_resource($a, 'RTW 1');

    expect(fn () => mv_patient($a, '7'))->toThrow(QueryException::class);
    expect(fn () => mv_resource($a, 'RTW 1'))->toThrow(QueryException::class);
    expect(fn () => DB::table('manv_patients')->insert(['manv_incident_id' => $a->id, 'number' => '8', 'triage' => 'tot']))->toThrow(QueryException::class);
    mv_patient($b, '7');
    mv_resource($b, 'RTW 1');
    expect(ManvPatient::count())->toBe(2);
});

it('cascades Lage deletion and nulls the optional foreign keys', function () {
    $i = mv_incident();
    $r = mv_resource($i);
    $p = mv_patient($i, '1', ['manv_resource_id' => $r->id]);
    (new ManvLogEntry)->forceFill(['manv_incident_id' => $i->id, 'occurred_at' => now(), 'action' => 'x', 'description' => 'y'])->save();

    $r->delete();
    expect($p->fresh()->manv_resource_id)->toBeNull();

    $user = User::factory()->create();
    $file = Personnel::factory()->create();
    $i->forceFill(['lna_personnel_id' => $file->id, 'created_by' => $user->id])->save();
    $file->delete();
    $user->delete();
    expect($i->fresh()->lna_personnel_id)->toBeNull()->and($i->fresh()->created_by)->toBeNull();

    $i->delete();
    expect(DB::table('manv_patients')->count())->toBe(0)->and(DB::table('manv_log')->count())->toBe(0)->and(DB::table('manv_resources')->count())->toBe(0);
});

it('registers mci.view and mci.manage only while the module is on, and keeps them known', function () {
    expect(Permissions::groups())->toHaveKey('mci')
        ->and(Permissions::moduleOf('mci.manage'))->toBe('manvboard');
    app(Settings::class)->set('modules.manvboard.enabled', false);
    expect(Permissions::groups())->not->toHaveKey('mci')
        ->and(Permissions::known())->toContain('mci.view', 'mci.manage');
});

it('keeps the role permission while the module is off', function () {
    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'M'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['mci.manage']);
    app(Settings::class)->set('modules.manvboard.enabled', false);

    expect($role->fresh()->permissions->pluck('name')->all())->toContain('mci.manage');
});

it('answers 404 with the module off, guests included', function () {
    app(Settings::class)->set('modules.manvboard.enabled', false);

    $this->get('/mci')->assertNotFound();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/mci')->assertNotFound();
});

it('keeps de and en translations in parity with :name placeholders', function () {
    $de = json_decode(file_get_contents(base_path('modules/ManvBoard/lang/de.json')), true);
    $en = json_decode(file_get_contents(base_path('modules/ManvBoard/lang/en.json')), true);

    expect(array_keys($de))->toEqualCanonicalizing(array_keys($en));
    foreach (ManvIncidentStatus::cases() as $c) {
        expect($de)->toHaveKey("manvboard.status.{$c->value}");
    }
    foreach (Triage::cases() as $c) {
        expect($de)->toHaveKey("manvboard.triage.{$c->value}");
    }
    foreach (Sex::cases() as $c) {
        expect($de)->toHaveKey("manvboard.sex.{$c->value}");
    }
    foreach (ResourceType::cases() as $c) {
        expect($de)->toHaveKey("manvboard.resource_type.{$c->value}");
    }
    foreach (ResourceStatus::cases() as $c) {
        expect($de)->toHaveKey("manvboard.resource_status.{$c->value}");
    }
    foreach (array_merge($de, $en) as $text) {
        expect($text)->not->toMatch('/\{\w+\}/');
    }
});

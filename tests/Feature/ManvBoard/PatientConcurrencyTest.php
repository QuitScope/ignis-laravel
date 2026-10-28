<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Modules\ManvBoard\Support\PatientNumber;

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mvc_user(): User
{
    $role = Role::create(['name' => 'C'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['mci.manage']);

    return User::factory()->withRole($role)->create();
}

function mvc_incident(): ManvIncident
{
    $i = (new ManvIncident)->forceFill(['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

function mvc_patient(ManvIncident $i, string $number): ManvPatient
{
    $p = (new ManvPatient)->forceFill(['manv_incident_id' => $i->id, 'number' => $number]);
    $p->save();

    return $p;
}

it('gives two creations in a row different consecutive numbers', function () {
    $i = mvc_incident();
    $this->actingAs(mvc_user());
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm']);
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK2', 'sex' => 'm']);
    expect(ManvPatient::orderBy('id')->pluck('number')->all())->toBe(['MANV-001', 'MANV-002']);
});

it('counts on from the highest number, not the row count (legacy bug 1.4-5)', function () {
    $i = mvc_incident();
    mvc_patient($i, 'MANV-005');
    mvc_patient($i, 'Altbestand');
    mvc_patient(mvc_incident(), 'MANV-050');

    expect(DB::transaction(fn () => PatientNumber::next($i)))->toBe('MANV-006');
});

it('numbers each Lage on its own', function () {
    mvc_patient(mvc_incident(), 'MANV-001');
    expect(DB::transaction(fn () => PatientNumber::next(mvc_incident())))->toBe('MANV-001');
});

it('keeps the number unique per Lage in the database', function () {
    $i = mvc_incident();
    mvc_patient($i, 'MANV-001');
    mvc_patient(mvc_incident(), 'MANV-001');
    mvc_patient($i, 'MANV-001');
})->throws(UniqueConstraintViolationException::class);

it('assigns a vehicle to only one of two creations in a row', function () {
    $i = mvc_incident();
    $r = (new ManvResource)->forceFill(['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW 1']);
    $r->save();
    $this->actingAs(mvc_user());

    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm', 'manv_resource_id' => $r->id])->assertSessionHasNoErrors();
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm', 'manv_resource_id' => $r->id])->assertSessionHasErrors('manv_resource_id');
    expect(ManvPatient::where('manv_resource_id', $r->id)->count())->toBe(1);
});

it('re-checks the vehicle under lock when it departs between validation and assignment', function () {
    $i = mvc_incident();
    $r = (new ManvResource)->forceFill(['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW 1']);
    $r->save();
    $done = false;
    DB::listen(function ($q) use (&$done, $r) {
        if (! $done && str_contains($q->sql, 'count(*)') && str_contains($q->sql, 'manv_resources')) {
            $done = true;
            ManvResource::query()->whereKey($r->id)->update(['departed_at' => now()]);
        }
    });
    $this->actingAs(mvc_user());

    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm', 'manv_resource_id' => $r->id])->assertSessionHasErrors('manv_resource_id');
    expect($done)->toBeTrue()->and(ManvPatient::count())->toBe(0);
});

it('keeps an unchanged own vehicle that has departed meanwhile', function () {
    $i = mvc_incident();
    $r = (new ManvResource)->forceFill(['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW 1', 'departed_at' => now()]);
    $r->save();
    $p = (new ManvPatient)->forceFill(['manv_incident_id' => $i->id, 'number' => 'MANV-001', 'manv_resource_id' => $r->id, 'callsign' => 'RTW 1']);
    $p->save();

    $this->actingAs(mvc_user())->put("/mci/{$i->id}/patients/{$p->id}", ['sex' => 'm', 'manv_resource_id' => $r->id, 'last_name' => 'Neu'])->assertSessionHasNoErrors();
    expect($p->refresh()->manv_resource_id)->toBe($r->id)->and($p->last_name)->toBe('Neu');
});

<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ManvBoard\Enums\Triage;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mvt_user(string ...$perms): User
{
    $role = Role::create(['name' => 'T'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function mvt_patient(array $incident = [], array $over = []): ManvPatient
{
    $i = (new ManvIncident)->forceFill($incident + ['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();
    $p = (new ManvPatient)->forceFill($over + ['manv_incident_id' => $i->id, 'number' => 'MANV-001']);
    $p->save();

    return $p;
}

it('writes the triage with time, account and one log entry', function () {
    $p = mvt_patient(over: ['triage' => 'SK3']);
    $m = mvt_user('mci.manage');

    $this->actingAs($m)->patch("/mci/{$p->manv_incident_id}/patients/{$p->id}/triage", ['triage' => 'SK1'])->assertRedirect();
    $p->refresh();
    expect($p->triage)->toBe(Triage::SK1)->and($p->triaged_by)->toBe($m->id)->and($p->triaged_at)->not->toBeNull();
    $log = ManvLogEntry::sole();
    expect($log->action)->toBe('sichtung_geaendert')->and($log->subject_type)->toBe('patient')->and($log->subject_id)->toBe($p->id)
        ->and($log->description)->toContain('SK3')->toContain('SK1')->and($log->user_id)->toBe($m->id);
});

it('writes nothing when the triage does not change', function () {
    $at = now()->subHour()->startOfSecond();
    $p = mvt_patient(over: ['triage' => 'SK2', 'triaged_at' => $at]);
    $this->actingAs(mvt_user('mci.manage'))->patch("/mci/{$p->manv_incident_id}/patients/{$p->id}/triage", ['triage' => 'SK2'])->assertRedirect();
    expect(ManvLogEntry::count())->toBe(0)->and($p->refresh()->triaged_at->equalTo($at))->toBeTrue();
});

it('rejects unknown categories', function () {
    $p = mvt_patient();
    $this->actingAs(mvt_user('mci.manage'));
    $this->patchJson("/mci/{$p->manv_incident_id}/patients/{$p->id}/triage", ['triage' => 'tot'])->assertStatus(422);
    $this->patchJson("/mci/{$p->manv_incident_id}/patients/{$p->id}/triage", [])->assertStatus(422);
    expect($p->refresh()->triage)->toBeNull()->and(ManvLogEntry::count())->toBe(0);
});

it('never changes the triage on GET', function () {
    $p = mvt_patient(over: ['triage' => 'SK3']);
    $this->actingAs(mvt_user('mci.manage'));
    $this->get("/mci/{$p->manv_incident_id}/patients/{$p->id}?quick_sk=SK1")->assertOk();
    $this->get("/mci/{$p->manv_incident_id}/patients/{$p->id}/triage?triage=SK1")->assertStatus(405);
    expect($p->refresh()->triage)->toBe(Triage::SK3)->and(ManvLogEntry::count())->toBe(0);
});

it('allows triage only to managers on an active Lage and only under its own Lage', function () {
    $p = mvt_patient(over: ['triage' => 'SK3']);
    $this->actingAs(mvt_user('mci.view'))->patch("/mci/{$p->manv_incident_id}/patients/{$p->id}/triage", ['triage' => 'SK1'])->assertForbidden();

    $this->actingAs(mvt_user('mci.manage'));
    foreach (['closed', 'archived'] as $status) {
        $c = mvt_patient(['status' => $status], ['triage' => 'SK3']);
        $this->patch("/mci/{$c->manv_incident_id}/patients/{$c->id}/triage", ['triage' => 'SK1'])->assertForbidden();
        expect($c->refresh()->triage)->toBe(Triage::SK3);
    }
    $other = mvt_patient();
    $this->patch("/mci/{$other->manv_incident_id}/patients/{$p->id}/triage", ['triage' => 'SK1'])->assertNotFound();
    expect($p->refresh()->triage)->toBe(Triage::SK3)->and(ManvLogEntry::count())->toBe(0);
});

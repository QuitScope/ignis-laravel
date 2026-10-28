<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Support\ManvLog;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function lg_user(string ...$perms): User
{
    $role = Role::create(['name' => 'G'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function lg_incident(): ManvIncident
{
    $i = (new ManvIncident)->forceFill(['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

it('records the signed-in account and its personnel name, never input', function () {
    $p = Personnel::factory()->create(['full_name' => 'Dr. Echt']);
    $u = lg_user('mci.manage');
    $u->forceFill(['personnel_id' => $p->id])->save();
    $this->actingAs($u);
    $i = lg_incident();
    $patient = (new ManvPatient)->forceFill(['manv_incident_id' => $i->id, 'number' => '1']);
    $patient->save();

    ManvLog::record($i, 'patient_erfasst', 'Patient 1 erfasst', $patient);

    $e = ManvLogEntry::sole();
    expect($e->user_id)->toBe($u->id)->and($e->user_name)->toBe('Dr. Echt')->and($e->subject_type)->toBe('patient')
        ->and($e->subject_id)->toBe($patient->id)->and($e->occurred_at)->not->toBeNull();
});

it('falls back to the account name without a personnel file', function () {
    $u = lg_user('mci.manage');
    $this->actingAs($u);
    ManvLog::record(lg_incident(), 'x', 'y');

    expect(ManvLogEntry::sole()->user_name)->toBe($u->name);
});

it('shows the log newest first, paginated, to viewers only', function () {
    $i = lg_incident();
    foreach (range(1, 60) as $n) {
        (new ManvLogEntry)->forceFill(['manv_incident_id' => $i->id, 'occurred_at' => now()->subMinutes(100 - $n), 'action' => 'a', 'description' => "E{$n}", 'user_name' => 'U'])->save();
    }
    ManvLog::record(lg_incident(), 'fremd', 'andere Lage');

    $this->actingAs(lg_user('audit.view'))->get("/mci/{$i->id}/log")->assertForbidden();
    $this->actingAs(lg_user('mci.view'))->get("/mci/{$i->id}/log")->assertInertia(fn ($p) => $p->component('ManvBoard/Log')
        ->has('entries.data', 50)->where('entries.total', 60)->where('entries.data.0.description', 'E60'));
});

it('returns 404 for a missing Lage log', function () {
    $this->actingAs(lg_user('mci.view'))->get('/mci/9999/log')->assertNotFound();
});

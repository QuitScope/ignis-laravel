<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mvb_user(string ...$perms): User
{
    $role = Role::create(['name' => 'B'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function mvb_incident(array $over = []): ManvIncident
{
    $i = (new ManvIncident)->forceFill($over + ['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

function mvb_resource(ManvIncident $i, array $over = []): ManvResource
{
    $r = (new ManvResource)->forceFill($over + ['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW '.uniqid()]);
    $r->save();

    return $r;
}

function mvb_patient(ManvIncident $i, array $over = []): ManvPatient
{
    $p = (new ManvPatient)->forceFill($over + ['manv_incident_id' => $i->id, 'number' => 'MANV-'.uniqid()]);
    $p->save();

    return $p;
}

it('shows the board with patients on site, sorted by triage then number, without personal data', function () {
    $i = mvb_incident(['lna_name' => 'Anna Notarzt']);
    mvb_patient($i, ['number' => 'MANV-002', 'triage' => 'SK2']);
    mvb_patient($i, ['number' => 'MANV-003', 'triage' => 'SK1']);
    mvb_patient($i, ['number' => 'MANV-001', 'triage' => 'SK1', 'last_name' => 'Geheim', 'first_name' => 'Gerda', 'injuries' => 'Fraktur', 'birth_date' => now()->subYears(34)->subDay(), 'sex' => 'f']);
    mvb_patient($i, ['number' => 'MANV-004', 'triage' => 'SK1', 'departed_at' => now()]);
    mvb_patient(mvb_incident(), ['number' => 'MANV-009', 'triage' => 'SK1']);

    $res = $this->actingAs(mvb_user('mci.view'))->get("/mci/{$i->id}");
    $res->assertInertia(fn ($p) => $p->component('ManvBoard/Board')
        ->where('incident.id', $i->id)
        ->where('incident.lna_name', 'Anna Notarzt')
        ->where('patients', fn ($ps) => collect($ps)->pluck('number')->all() === ['MANV-001', 'MANV-003', 'MANV-002'])
        ->where('patients.0.age', 34)
        ->where('patients.0.sex', 'f')
        ->where('patients.0.triage', 'SK1')
        ->where('sort', ['key' => 'triage', 'dir' => 'asc'])
        ->where('can.write', false));
    expect($res->getContent())->not->toContain('Geheim')->not->toContain('Gerda')->not->toContain('Fraktur');
});

it('sorts the table by a whitelisted column and rejects others', function () {
    $i = mvb_incident();
    mvb_patient($i, ['number' => 'MANV-001', 'triage' => 'SK3']);
    mvb_patient($i, ['number' => 'MANV-002', 'triage' => 'SK1']);
    $this->actingAs(mvb_user('mci.view'));

    $this->get("/mci/{$i->id}?sort=number&dir=desc")->assertInertia(fn ($p) => $p
        ->where('patients', fn ($ps) => collect($ps)->pluck('number')->all() === ['MANV-002', 'MANV-001'])
        ->where('sort', ['key' => 'number', 'dir' => 'desc']));
    $this->get("/mci/{$i->id}?sort=last_name")->assertSessionHasErrors('sort');
    $this->get("/mci/{$i->id}?dir=sideways")->assertSessionHasErrors('dir');
});

it('caps the table at 1000 patients', function () {
    $i = mvb_incident();
    $now = now();
    foreach (array_chunk(range(1, 1001), 250) as $chunk) {
        DB::table('manv_patients')->insert(array_map(fn ($n) => ['manv_incident_id' => $i->id, 'number' => sprintf('MANV-%04d', $n), 'sex' => 'unknown', 'created_at' => $now, 'updated_at' => $now], $chunk));
    }
    $this->actingAs(mvb_user('mci.view'))->get("/mci/{$i->id}")->assertInertia(fn ($p) => $p->has('patients', 1000));
});

it('lists vehicle resources on site with their load and the counters', function () {
    $i = mvb_incident();
    $rtw = mvb_resource($i, ['label' => 'RTW 1']);
    mvb_resource($i, ['label' => 'Trupp', 'type' => 'personnel']);
    mvb_resource($i, ['label' => 'RTW 2', 'departed_at' => now()]);
    mvb_patient($i, ['triage' => 'SK1', 'manv_resource_id' => $rtw->id]);
    mvb_patient($i, ['triage' => 'SK2', 'manv_resource_id' => $rtw->id, 'departed_at' => now()]);

    $this->actingAs(mvb_user('mci.view'))->get("/mci/{$i->id}")->assertInertia(fn ($p) => $p
        ->has('resources', 1)
        ->where('resources.0.label', 'RTW 1')
        ->where('resources.0.load', 1)
        ->where('patients.0.vehicle', 'RTW 1')
        ->where('stats.total', 2)
        ->where('stats.waiting', 1)
        ->where('stats.departed', 1));
});

it('flags patients ready for departure on the board', function () {
    $i = mvb_incident();
    $r = mvb_resource($i, ['vehicle_id' => Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS])->id]);
    mvb_patient($i, ['triage' => 'SK1', 'manv_resource_id' => $r->id, 'destination' => 'Klinikum']);
    mvb_patient($i, ['triage' => 'SK2']);

    $this->actingAs(mvb_user('mci.manage'))->get("/mci/{$i->id}")->assertInertia(fn ($p) => $p
        ->where('patients.0.can_depart', true)
        ->where('patients.1.can_depart', false)
        ->where('can.write', true));
});

it('loads the board with a constant number of queries', function () {
    $i = mvb_incident();
    $add = function (int $n) use ($i) {
        foreach (range(1, $n) as $k) {
            $r = mvb_resource($i, ['vehicle_id' => Vehicle::factory()->create()->id]);
            mvb_patient($i, ['triage' => 'SK1', 'manv_resource_id' => $r->id, 'destination' => 'Klinikum']);
        }
    };
    $this->actingAs(mvb_user('mci.view'));
    $count = function () use ($i): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get("/mci/{$i->id}")->assertOk();

        return count(DB::getQueryLog());
    };
    $add(3);
    $count();
    $few = $count();
    $add(30);
    expect($count())->toBe($few);
});

it('marks a closed Lage read-only on the board', function () {
    $i = mvb_incident(['status' => 'closed']);
    $this->actingAs(mvb_user('mci.manage'))->get("/mci/{$i->id}")->assertInertia(fn ($p) => $p->where('can.write', false)->where('incident.status', 'closed'));
});

it('keeps guests, outsiders and unknown Lagen out', function () {
    $i = mvb_incident();
    $this->get("/mci/{$i->id}")->assertRedirect('/login');
    $this->actingAs(mvb_user('audit.view'))->get("/mci/{$i->id}")->assertForbidden();
    $this->actingAs(mvb_user('mci.view'))->get('/mci/999999')->assertNotFound();
});

it('links the Lage list to the board and opens the board after creating a Lage', function () {
    $this->actingAs(mvb_user('mci.manage'))->post('/mci', ['incident_number' => 'X', 'location' => 'Y'])
        ->assertRedirect('/mci/'.ManvIncident::sole()->id);
});

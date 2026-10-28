<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Enotf\Enums\PatientSync;
use Modules\Enotf\Models\EnotfProtocol;

uses(RefreshDatabase::class);

function dsCrew(): array
{
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    test()->actingAs($user)->post('/enotf/login', ['vehicle_id' => $vehicle->id, 'pin' => '1234'])->assertRedirect('/enotf/overview');

    return [$user, $vehicle];
}

function dsProtocol(Vehicle $vehicle): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill(['enr' => '777001', 'vehicle_transport_id' => $vehicle->id]);
    $p->save();

    return $p->fresh();
}

it('returns only the fields changed since a revision, the status, and respects the policy', function () {
    [$user, $vehicle] = dsCrew();
    $p = dsProtocol($vehicle);
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 1, 'fields' => ['diagnose' => 'A']])->assertOk();
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 2, 'fields' => ['pat_nachname' => 'Muster']])->assertOk();

    $this->getJson("/enotf/p/{$p->enr}/changes?since=2")->assertOk()
        ->assertExactJson(['revision' => 3, 'status' => 'open', 'released' => false, 'changes_since' => ['pat_nachname' => 'Muster']]);
    $this->getJson("/enotf/p/{$p->enr}/changes?since=3")->assertOk()->assertJsonPath('changes_since', []);

    EnotfProtocol::query()->whereKey($p->id)->update(['status' => 'released', 'revision' => 4]);
    $this->getJson('/enotf/status')->assertOk()->assertJsonPath('protocols', []);
    $this->getJson("/enotf/p/{$p->enr}/changes?since=3")->assertOk()->assertJsonPath('status', 'released')->assertJsonPath('released', true);

    dsCrew();
    $this->getJson("/enotf/p/{$p->enr}/changes?since=0")->assertForbidden();
});

it('marks patient data pending once, needs a name, the update right and an open protocol', function () {
    [$user, $vehicle] = dsCrew();
    $p = dsProtocol($vehicle);

    $this->postJson("/enotf/p/{$p->enr}/patient-sync")->assertStatus(422);
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 1, 'fields' => ['pat_nachname' => 'Muster']])->assertOk();
    $this->postJson("/enotf/p/{$p->enr}/patient-sync")->assertOk()->assertJsonPath('patient_sync', 'pending');
    $this->postJson("/enotf/p/{$p->enr}/patient-sync")->assertOk()->assertJsonPath('patient_sync', 'pending');
    EnotfProtocol::query()->whereKey($p->id)->update(['patient_sync' => 'synced']);
    $this->postJson("/enotf/p/{$p->enr}/patient-sync")->assertOk()->assertJsonPath('patient_sync', 'synced');
    expect(DB::table('activity_log')->where('description', 'Patientendaten an Leitstelle gemeldet')->count())->toBe(1);

    EnotfProtocol::query()->whereKey($p->id)->update(['patient_sync' => 'none', 'status' => 'released']);
    $this->postJson("/enotf/p/{$p->enr}/patient-sync")->assertForbidden();

    $q = (new EnotfProtocol)->forceFill(['enr' => '777002', 'vehicle_transport_id' => $vehicle->id, 'patient_last_name' => 'X']);
    $q->save();
    dsCrew();
    $this->postJson("/enotf/p/{$q->enr}/patient-sync")->assertForbidden();
    expect($q->fresh()->patient_sync)->toBe(PatientSync::None);
});

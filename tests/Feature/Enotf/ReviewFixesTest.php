<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;

uses(RefreshDatabase::class);

function rfUser(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function rfProtocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();

    return $p->fresh();
}

it('ignores a crew session that became invalid on the routes outside crew:enotf', function (string $how) {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user = rfUser();
    $p = rfProtocol(['vehicle_transport_id' => $vehicle->id]);
    $this->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');
    $this->get("/enotf/print/{$p->enr}")->assertOk();

    $how === 'vehicle' ? $vehicle->forceFill(['is_active' => false])->save() : $user->forceFill(['personnel_id' => null])->save();

    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 1, 'fields' => ['diagnose' => 'X']])->assertForbidden();
    $this->postJson("/enotf/p/{$p->enr}/release")->assertForbidden();
    $this->get("/enotf/print/{$p->enr}")->assertForbidden();
    expect($p->fresh()->revision)->toBe(1);
})->with(['vehicle', 'record']);

it('never lets a super-admin write, not even with an admin role', function () {
    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'Adm'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);
    $super = User::factory()->superAdmin()->withRole($role)->create();
    $p = rfProtocol();

    $this->actingAs($super)->patchJson("/enotf/p/{$p->enr}", ['revision' => 1, 'fields' => ['diagnose' => 'X']])->assertForbidden();
    $this->postJson("/enotf/p/{$p->enr}/release")->assertForbidden();
    $this->post('/enotf/admin/hide', ['ids' => [$p->id]])->assertForbidden();
    $p->refresh();
    expect($p->revision)->toBe(1)->and($p->status)->toBe(ProtocolStatus::Open)->and($p->hidden_at)->toBeNull();
});

it('does not lock a person out by naming them as trainee; a driver stays busy', function () {
    [$v1, $v2, $v3] = Vehicle::factory()->count(3)->create(['service' => Vehicle::SERVICE_EMS]);
    $a = rfUser();
    $b = rfUser();
    $this->actingAs($a)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $v1->id, 'trainee_id' => $b->personnel_id])->assertRedirect('/enotf/overview');

    $this->actingAs($b)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $v2->id])->assertRedirect('/enotf/overview');
    expect(DB::table('enotf_crew_members')->where('personnel_id', $b->personnel_id)->whereNull('left_at')->count())->toBe(1);

    $this->actingAs(rfUser())->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $v3->id, 'codriver_id' => $b->personnel_id])->assertSessionHasErrors('members');
});

it('prunes withdrawn protocols from their withdrawal time', function () {
    $names = ['patient_first_name' => 'Max', 'patient_last_name' => 'Muster', 'patient_birthdate' => '1980-01-01'];
    $old = rfProtocol($names + ['status' => ProtocolStatus::Withdrawn, 'withdrawn_at' => now()->subMonths(13)]);
    $recent = rfProtocol($names + ['status' => ProtocolStatus::Withdrawn, 'withdrawn_at' => now()->subMonths(1)]);
    app(Settings::class)->set('enotf.personal_data_months', 12);

    $this->artisan('enotf:prune')->expectsOutputToContain('1 protocol(s) cleared')->assertSuccessful();
    expect($old->fresh()->patient_last_name)->toBeNull()->and($recent->fresh()->patient_last_name)->toBe('Muster');
});

it('searches crew records only from two characters on', function () {
    Personnel::factory()->create(['full_name' => 'Anna Beispiel']);
    $this->actingAs(rfUser())->getJson('/enotf/personnel/search?q=A')->assertOk()->assertExactJson([]);
    $this->getJson('/enotf/personnel/search?q=An')->assertOk()->assertJsonFragment(['name' => 'Anna Beispiel']);
});

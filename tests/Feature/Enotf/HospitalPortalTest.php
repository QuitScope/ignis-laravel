<?php

use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\HospitalAccessCode;
use Modules\Enotf\Models\HospitalDepartment;

uses(RefreshDatabase::class);

function hp_manager(): User
{
    test()->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['pois.manage']);

    return User::factory()->withRole($role)->create();
}

function hp_hospital(): Poi
{
    return Poi::factory()->create(['type' => 'Klinik', 'is_active' => true]);
}

function hp_department(Poi $poi, string $name = 'ZNA'): HospitalDepartment
{
    $d = (new HospitalDepartment)->forceFill(['poi_id' => $poi->id, 'name' => $name]);
    $d->save();

    return $d;
}

it('stores the access code only hashed and rotating signs out the old code and its devices', function () {
    $poi = hp_hospital();
    $manager = hp_manager();
    $this->actingAs(User::factory()->create())->postJson("/settings/pois/{$poi->id}/access-code")->assertForbidden();

    $old = $this->actingAs($manager)->postJson("/settings/pois/{$poi->id}/access-code")->assertOk()->json('code');
    expect($old)->toMatch('/^([A-Z2-9]{4}-){3}[A-Z2-9]{4}$/')
        ->and(HospitalAccessCode::query()->sole()->code_hash)->not->toContain(str_replace('-', '', $old))->toStartWith('$2y$');
    $this->assertDatabaseHas('activity_log', ['description' => 'Klinik-Zugangscode erneuert']);
    $this->post("/settings/pois/{$poi->id}/departments", ['name' => 'ZNA'])->assertRedirect();
    $this->get("/settings/pois/{$poi->id}/departments")->assertOk()->assertInertia(fn ($page) => $page->component('Enotf/Settings/Departments')
        ->where('departments.0.status', 'not_staffed')->missing('access_code.code_hash'));
    $this->assertDatabaseMissing('activity_log', ['details' => $old]);
    auth()->logout();

    $this->post('/enotf/schnittstelle/login', ['poi_id' => $poi->id, 'code' => strtolower(str_replace('-', '', $old))])->assertRedirect('/enotf/schnittstelle');
    $this->get('/enotf/schnittstelle/availability')->assertOk();

    $this->actingAs($manager)->postJson("/settings/pois/{$poi->id}/access-code")->assertOk();
    auth()->logout();
    $this->get('/enotf/schnittstelle/availability')->assertRedirect('/enotf/schnittstelle/login');
    $this->post('/enotf/schnittstelle/login', ['poi_id' => $poi->id, 'code' => $old])->assertSessionHasErrors('code');
});

it('throttles the portal sign-in to 5 per minute per IP', function () {
    $poi = hp_hospital();
    foreach (range(1, 5) as $i) {
        $this->post('/enotf/schnittstelle/login', ['poi_id' => $poi->id, 'code' => 'WRONG'])->assertSessionHasErrors('code');
    }
    $this->post('/enotf/schnittstelle/login', ['poi_id' => $poi->id, 'code' => 'WRONG'])->assertStatus(429);
});

it('lets a clinic device change only its own departments; nothing without the device session', function () {
    [$own, $other] = [hp_hospital(), hp_hospital()];
    $mine = hp_department($own);
    $foreign = hp_department($other);
    (new HospitalAccessCode)->forceFill(['poi_id' => $own->id, 'code_hash' => Hash::make('ABCDEFGHJKLMNPQR'), 'rotated_at' => now()])->save();

    $this->putJson("/enotf/schnittstelle/availability/{$mine->id}", ['status' => 'full'])->assertForbidden();
    $this->post('/enotf/schnittstelle/login', ['poi_id' => $own->id, 'code' => 'ABCD-EFGH-JKLM-NPQR'])->assertRedirect('/enotf/schnittstelle');

    $this->putJson("/enotf/schnittstelle/availability/{$foreign->id}", ['status' => 'full'])->assertNotFound();
    $this->putJson("/enotf/schnittstelle/availability/{$mine->id}", ['status' => 'bogus'])->assertUnprocessable();
    $this->putJson("/enotf/schnittstelle/availability/{$mine->id}", ['status' => 'full', 'note' => 'CT defekt', 'updated_by' => 'Fake'])
        ->assertOk()->assertJson(['status' => 'full', 'updated_by' => 'Klinik-Zugang']);
    expect($foreign->fresh()->status)->toBe('not_staffed')->and($mine->fresh()->note)->toBe('CT defekt');

    $this->get('/enotf/overview')->assertRedirect('/login');
    $this->get("/settings/pois/{$own->id}/departments")->assertRedirect('/login');
    $this->getJson('/api/hospitals/availability-get')->assertStatus(410);
});

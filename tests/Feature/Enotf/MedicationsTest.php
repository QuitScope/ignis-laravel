<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\Medication;
use Modules\Enotf\Models\MedicationGiven;

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(fn () => $this->seed(RoleSeeder::class));

function md_crew(): Vehicle
{
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');

    return $vehicle;
}

function md_protocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();

    return $p->fresh();
}

function md_give(int $id = 0): array
{
    return ['medication_id' => $id ?: Medication::query()->where('substance', 'Heparin')->value('id'), 'dose' => '5000', 'unit' => 'IE', 'route' => 'i.v.'];
}

it('seeds the 47 legacy catalog entries with doses as trimmed lists', function () {
    expect(Medication::query()->count())->toBe(47)
        ->and(Medication::query()->where('substance', 'Heparin')->sole()->doses)->toBe(['5000 IE', '10000 IE'])
        ->and(Medication::query()->where('substance', 'Humanblut')->sole()->doses)->toBe([]);
});

it('stores two simultaneous gives as two rows, soft-deletes with who/when and keeps deleted rows out of list and print', function () {
    $vehicle = md_crew();
    $p = md_protocol(['vehicle_transport_id' => $vehicle->id]);
    $med = Medication::query()->where('substance', 'Heparin')->sole();
    $raced = false;
    DB::listen(function (QueryExecuted $q) use (&$raced, $p, $med) {
        if (! $raced && str_contains(str_replace('`', '"', $q->sql), 'insert into "enotf_medications_given"')) {
            $raced = true;
            DB::table('enotf_medications_given')->insert(['enotf_protocol_id' => $p->id, 'medication_id' => $med->id, 'name' => 'Heparin', 'dose' => '10000', 'unit' => 'IE', 'route' => 'i.v.', 'given_at' => now()]);
        }
    });

    $this->postJson("/enotf/p/{$p->enr}/medications", md_give())->assertCreated()->assertJsonCount(2, 'medications');
    expect(MedicationGiven::query()->pluck('dose')->all())->toEqualCanonicalizing(['5000', '10000']);

    $mine = MedicationGiven::query()->where('dose', '5000')->sole();
    expect($mine->name)->toBe('Heparin')->and($mine->created_by_user_id)->toBe(auth()->id());
    $this->deleteJson("/enotf/p/{$p->enr}/medications/{$mine->id}")->assertOk()->assertJsonCount(1, 'medications');
    expect($mine->fresh()->deleted_by_user_id)->toBe(auth()->id());
    $this->get("/enotf/print/{$p->enr}")->assertOk()->assertInertia(fn ($page) => $page->has('medications', 1)->where('medications.0.dose', '10000'));

    $med->forceFill(['is_active' => false])->save();
    $this->postJson("/enotf/p/{$p->enr}/medications", md_give($med->id))->assertJsonValidationErrors('medication_id');
    $this->postJson("/enotf/p/{$p->enr}/medications", ['route' => 'rektal'] + md_give(Medication::query()->where('is_active', true)->value('id')))->assertJsonValidationErrors('route');

    $other = md_protocol(['vehicle_transport_id' => $vehicle->id]);
    $this->deleteJson("/enotf/p/{$other->enr}/medications/".MedicationGiven::query()->whereNull('deleted_at')->value('id'))->assertNotFound();
    $p->forceFill(['status' => 'released'])->save();
    $this->postJson("/enotf/p/{$p->enr}/medications", md_give(Medication::query()->where('is_active', true)->value('id')))->assertStatus(409);
});

it('keeps the catalog settings behind enotf.settings', function () {
    $this->actingAs(User::factory()->create())->get('/settings/medications')->assertForbidden();
    $this->post('/settings/medications', ['substance' => 'X', 'sort' => 1, 'is_active' => true])->assertForbidden();

    $role = Role::create(['name' => 'Med', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['enotf.settings']);
    $this->actingAs(User::factory()->withRole($role)->create());
    $this->get('/settings/medications')->assertOk()->assertInertia(fn ($page) => $page->component('Enotf/Settings/Medications')->has('medications', 47));
    $this->post('/settings/medications', ['substance' => 'Ketamin', 'brand' => '', 'doses' => ' 10 mg , ,20 mg', 'sort' => 48, 'is_active' => true])->assertRedirect();
    $new = Medication::query()->where('substance', 'Ketamin')->sole();
    expect($new->doses)->toBe(['10 mg', '20 mg'])->and($new->brand)->toBeNull();
    $this->post('/settings/medications', ['substance' => 'Ketamin', 'sort' => 1, 'is_active' => true])->assertSessionHasErrors('substance');
    $this->put("/settings/medications/{$new->id}", ['substance' => 'Ketamin', 'sort' => 1, 'is_active' => false])->assertRedirect();
    expect($new->fresh()->is_active)->toBeFalse();
    $this->delete("/settings/medications/{$new->id}")->assertRedirect();
    expect(Medication::query()->count())->toBe(47);
    $this->get('/settings/medikamente/index.php')->assertRedirect('/settings/medications');
});

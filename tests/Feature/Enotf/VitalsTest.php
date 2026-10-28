<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\VitalSign;
use Modules\Enotf\Support\VitalparameterCatalog;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vt_crew(?User $user = null): Vehicle
{
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user ??= User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');

    return $vehicle;
}

function vt_protocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();

    return $p->fresh();
}

it('stores one row per value at server time, keeps ng/nm as text (never 0), soft-deletes with who/when', function () {
    $vehicle = vt_crew();
    $p = vt_protocol(['vehicle_transport_id' => $vehicle->id]);

    $this->postJson("/enotf/p/{$p->enr}/vitals", ['values' => ['spo2' => '97', 'temp' => '36,8', 'bz' => 'ng', 'herzfreq' => '']])
        ->assertCreated()->assertJsonCount(3, 'vitals')->assertJsonPath('bz_unit', 'mg/dl');
    $bz = VitalSign::query()->where('parameter', 'bz')->sole();
    expect($bz->value)->toBeNull()->and($bz->value_text)->toBe('ng')
        ->and((float) VitalSign::query()->where('parameter', 'temp')->value('value'))->toBe(36.8)
        ->and($bz->measured_at->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and($bz->created_by_user_id)->toBe(auth()->id());

    $this->postJson("/enotf/p/{$p->enr}/vitals", ['values' => ['spo2' => '90'], 'measured_at' => now()->subHour()->setTimezone(config('ignis.timezone'))->format('Y-m-d\TH:i')])->assertCreated();
    $this->postJson("/enotf/p/{$p->enr}/vitals", ['values' => ['spo2' => '90'], 'measured_at' => now()->addDay()->format('Y-m-d H:i')])->assertJsonValidationErrors('measured_at');
    $this->postJson("/enotf/p/{$p->enr}/vitals", ['values' => ['spo2' => '9x']])->assertJsonValidationErrors('values.spo2');
    $this->postJson("/enotf/p/{$p->enr}/vitals", ['values' => ['puls' => '9']])->assertJsonValidationErrors('values');

    $this->deleteJson("/enotf/p/{$p->enr}/vitals/{$bz->id}")->assertOk()->assertJsonCount(3, 'vitals');
    expect($bz->fresh()->deleted_at)->not->toBeNull()->and($bz->fresh()->deleted_by_user_id)->toBe(auth()->id());
    $this->deleteJson("/enotf/p/{$p->enr}/vitals/{$bz->id}")->assertNotFound();
    $this->get("/enotf/print/{$p->enr}")->assertOk()->assertInertia(fn ($page) => $page->has('vitals', 3));
});

it('converts blood sugar between mg/dl and mmol/l, storage always mg/dl', function () {
    expect(VitalparameterCatalog::bzToMgdl(5.5, 'mmol/l'))->toBe(99.0)
        ->and(VitalparameterCatalog::bzFromMgdl(99, 'mmol/l'))->toBe(5.5)
        ->and(VitalparameterCatalog::bzFromMgdl(99.4, 'mg/dl'))->toBe(99.0)
        ->and(VitalparameterCatalog::parse('bz', '5,5', 'mmol/l'))->toBe([99.0, null])
        ->and(VitalparameterCatalog::parse('bz', 'NM', 'mmol/l'))->toBe([null, 'nm'])
        ->and(VitalparameterCatalog::parse('spo2', '1.234'))->toBeNull();

    app(Settings::class)->set('enotf.bz_unit', 'mmol/l');
    $vehicle = vt_crew();
    $p = vt_protocol(['vehicle_transport_id' => $vehicle->id]);
    $this->postJson("/enotf/p/{$p->enr}/vitals", ['values' => ['bz' => '5,5']])->assertCreated()
        ->assertJsonPath('bz_unit', 'mmol/l')->assertJsonPath('vitals.0.display', '5,5')->assertJsonPath('vitals.0.unit', 'mmol/l');
    expect((float) VitalSign::query()->sole()->value)->toBe(99.0);
});

it('answers 404 for a foreign protocol row, 409 once closed, 403 for a super-admin and other vehicles', function () {
    $vehicle = vt_crew();
    $p = vt_protocol(['vehicle_transport_id' => $vehicle->id]);
    $other = vt_protocol(['vehicle_transport_id' => $vehicle->id]);
    $this->postJson("/enotf/p/{$other->enr}/vitals", ['values' => ['spo2' => '95']])->assertCreated();
    $foreignRow = VitalSign::query()->sole();

    $this->deleteJson("/enotf/p/{$p->enr}/vitals/{$foreignRow->id}")->assertNotFound();
    $this->getJson('/enotf/p/'.vt_protocol()->enr.'/vitals')->assertForbidden();

    $p->forceFill(['status' => 'released'])->save();
    $this->postJson("/enotf/p/{$p->enr}/vitals", ['values' => ['spo2' => '95']])->assertStatus(409);

    $this->flushSession();
    $super = User::factory()->create(['is_super_admin' => true, 'personnel_id' => Personnel::factory()->create()->id]);
    $own = vt_crew($super);
    $open = vt_protocol(['vehicle_transport_id' => $own->id]);
    $this->getJson("/enotf/p/{$open->enr}/vitals")->assertOk();
    $this->postJson("/enotf/p/{$open->enr}/vitals", ['values' => ['spo2' => '95']])->assertForbidden();
    expect(VitalSign::query()->count())->toBe(1);
});

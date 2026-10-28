<?php

use App\Auth\ApiKey;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\FieldSchema;
use Modules\Enotf\Support\ProtocolMerger;

uses(RefreshDatabase::class);

it('bills a released protocol exactly once and refuses the QM reopen afterwards', function () {
    $this->seed(RoleSeeder::class);
    $key = app(ApiKey::class)->current();
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS, 'name' => 'RTW 1']);
    $crew = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $this->actingAs($crew)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');
    $p = (new EnotfProtocol)->forceFill(['enr' => '9101', 'vehicle_transport_id' => $vehicle->id, 'vehicle_transport_label' => 'RTW 1',
        'patient_last_name' => 'Muster', 'created_at' => now()->subHour()]);
    $p->save();
    $full = json_decode((string) file_get_contents(base_path('tests/Fixtures/enotf-conditions.json')), true)['full'];
    unset($full['medis']);
    $checked = FieldSchema::validate(['awsicherung_neu' => 1, 'prot_by' => 0] + $full);
    app(ProtocolMerger::class)->apply($p, $p->revision, $checked['values']);
    $bill = fn () => test()->call('POST', '/api/enotf/billing.php', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        json_encode(['intraRP_API_Key' => $key, 'timestamp' => time()]))->assertOk()->json('protocols.*.missionNumber');

    expect($bill())->toBe([]);
    $this->postJson("/enotf/p/{$p->enr}/release")->assertSuccessful();
    expect($p->fresh()->status->value)->toBe('released')
        ->and($bill())->toBe(['9101'])->and($bill())->toBe([]);

    $qm = User::factory()->create();
    $qm->assignRole(tap(Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']), fn ($r) => $r->syncPermissions(['edivi.edit'])));
    $this->actingAs($qm)->postJson("/enotf/qm/{$p->enr}/reopen")->assertStatus(409);
    expect($p->fresh()->status->value)->toBe('released')->and($p->fresh()->billed_at)->not->toBeNull();
});

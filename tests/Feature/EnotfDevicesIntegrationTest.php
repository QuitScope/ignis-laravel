<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;

uses(RefreshDatabase::class);

function p9ciUser(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

it('runs a crew from PIN login through join and lock to the 5th wrong PIN', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    [$a, $b] = [p9ciUser(), p9ciUser()];
    $p = (new EnotfProtocol)->forceFill(['enr' => '910001', 'vehicle_transport_id' => $vehicle->id]);
    $p->save();

    $this->actingAs($a)->post('/enotf/login', ['vehicle_id' => $vehicle->id])->assertSessionHasErrors('pin');
    $this->post('/enotf/login', ['vehicle_id' => $vehicle->id, 'pin' => '4711'])->assertRedirect('/enotf/overview');
    $this->actingAs($b)->post('/enotf/crew/join', ['vehicle_id' => $vehicle->id, 'position' => 2, 'pin' => '4711'])->assertRedirect('/enotf/overview');
    $crew = EnotfCrew::query()->where('vehicle_id', $vehicle->id)->sole();
    expect($crew->members()->whereNotNull('active_key')->count())->toBe(2);

    Carbon::setTestNow(now()->addMinutes(6));
    $this->patchJson('/enotf/p/910001', ['revision' => 1, 'fields' => ['diagnose' => 'X']])->assertStatus(423)->assertJsonPath('redirect', '/enotf/lockscreen');
    foreach (range(1, 4) as $i) {
        $this->post('/enotf/unlock', ['pin' => '0000'])->assertSessionHasErrors('pin');
    }
    $this->post('/enotf/unlock', ['pin' => '0000'])->assertRedirect('/enotf/loggedout');
    Carbon::setTestNow();

    expect($crew->fresh()->ended_at)->not->toBeNull()->and($crew->fresh()->ended_reason)->toBe('pin_failed')
        ->and($p->fresh()->revision)->toBe(1);
    $this->patchJson('/enotf/p/910001', ['revision' => 1, 'fields' => ['diagnose' => 'X']])->assertForbidden();
});

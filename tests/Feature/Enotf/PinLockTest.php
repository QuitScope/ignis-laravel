<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;

uses(RefreshDatabase::class);

/** @return array{User, Vehicle, EnotfProtocol} */
function plSetup(): array
{
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $p = (new EnotfProtocol)->forceFill(['enr' => '424242', 'vehicle_transport_id' => $vehicle->id]);
    $p->save();
    test()->actingAs($user)->post('/enotf/login', ['vehicle_id' => $vehicle->id])->assertSessionHasErrors('pin');
    test()->post('/enotf/login', ['vehicle_id' => $vehicle->id, 'pin' => '4711'])->assertRedirect('/enotf/overview');

    return [$user, $vehicle, $p->fresh()];
}

function plCrew(): EnotfCrew
{
    return EnotfCrew::query()->latest('id')->firstOrFail();
}

it('stores the PIN hashed and locks after the inactivity timeout, JSON included, outside crew:enotf too', function () {
    [, , $p] = plSetup();
    expect(plCrew()->pin_hash)->not->toBe('4711')->and(Hash::check('4711', plCrew()->pin_hash))->toBeTrue();

    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 1, 'fields' => ['diagnose' => 'A']])->assertOk();
    Carbon::setTestNow(now()->addMinutes(6));

    $this->getJson('/enotf/status')->assertOk()->assertJsonPath('locked', true);
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 2, 'fields' => ['diagnose' => 'B']])
        ->assertStatus(423)->assertJson(['locked' => true, 'redirect' => '/enotf/lockscreen']);
    $this->getJson("/enotf/print/{$p->enr}")->assertStatus(423);
    $this->get("/enotf/print/{$p->enr}")->assertRedirect('/enotf/lockscreen');
    $this->post('/enotf/pin-activity')->assertRedirect('/enotf/lockscreen');
    expect($p->fresh()->data['diagnose'] ?? null)->toBe('A');

    $this->post('/enotf/unlock', ['pin' => '4711'])->assertRedirect("/enotf/print/{$p->enr}");
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 2, 'fields' => ['diagnose' => 'B']])->assertOk();
    Carbon::setTestNow();
});

it('does not count the background changes sync as activity; the personnel search passes the lock', function () {
    [, , $p] = plSetup();
    $crew = plCrew();
    $crew->forceFill(['last_activity_at' => now()->subMinutes(2)])->save();
    $before = plCrew()->last_activity_at;

    $this->getJson("/enotf/p/{$p->enr}/changes?since=0")->assertOk();
    expect(plCrew()->last_activity_at->equalTo($before))->toBeTrue();
    $this->getJson("/enotf/p/{$p->enr}/plausibility")->assertOk();
    expect(plCrew()->last_activity_at->greaterThan($before))->toBeTrue();

    plCrew()->forceFill(['locked_at' => now()])->save();
    $this->getJson("/enotf/p/{$p->enr}/changes?since=0")->assertStatus(423);
    $this->getJson('/enotf/personnel/search?q=Zz')->assertOk();
});

it('resets the counter on success, says nothing about the input and ends the crew at the 5th failure', function () {
    [$user, $vehicle, $p] = plSetup();
    plCrew()->forceFill(['locked_at' => now()])->save();

    $messages = [];
    foreach (['1234', '47', 'abcd', '47110'] as $wrong) {
        $messages[] = $this->post('/enotf/unlock', ['pin' => $wrong])->assertSessionHasErrors('pin')->getSession()->get('errors')->first('pin');
    }
    expect(array_unique($messages))->toBe([__('enotf.pin.wrong')])->and(plCrew()->failed_attempts)->toBe(4);
    $this->post('/enotf/unlock', ['pin' => '4711'])->assertRedirect('/enotf/overview');
    expect(plCrew()->failed_attempts)->toBe(0)->and(plCrew()->locked_at)->toBeNull();

    plCrew()->forceFill(['locked_at' => now()])->save();
    foreach (range(1, 4) as $i) {
        $this->post('/enotf/unlock', ['pin' => '0000'])->assertSessionHasErrors('pin');
    }
    $this->post('/enotf/unlock', ['pin' => '0000'])->assertRedirect('/enotf/loggedout');
    expect(plCrew()->ended_reason)->toBe('pin_failed')->and(session()->has('crew.enotf'))->toBeFalse();
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => 1, 'fields' => ['diagnose' => 'X']])->assertForbidden();
});

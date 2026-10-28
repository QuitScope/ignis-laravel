<?php

use App\Auth\ApiKey;
use App\Models\FiveMCharacter;
use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class)->group('mariadb');

const CEF_UA = 'Mozilla/5.0 (Windows NT 10.0) Chrome/103.0 CitizenFX/1.0';

function cef_token(): ?string
{
    $token = null;
    same_session()->withHeader('User-Agent', CEF_UA)->get('/firetab/login')
        ->assertInertia(function (AssertableInertia $p) use (&$token) {
            $token = $p->toArray()['props']['fivem']['identifyToken'];
        });

    return $token;
}

/** ef_bridge server/main.lua: POST api/character/identify.php, key in the body. */
function identify(array $body, ?string $key = null)
{
    return test()->postJson('/api/character/identify.php', ['intraRP_API_Key' => $key ?? app(ApiKey::class)->current(), ...$body]);
}

it('keeps the ef_bridge connection test and drops get-session-id', function () {
    identify([])->assertStatus(422);
    identify(['session_id' => 'x'], 'wrong')->assertStatus(403);
    $this->get('/api/character/get-session-id')->assertStatus(410);
    $this->post('/api/character/get-session-id.php')->assertStatus(410);
});

it('hands a CEF session one token, redeemable once, bound to account and session', function () {
    $this->get('/firetab/login')->assertRedirect();
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $this->actingAs($user)->get('/firetab/login')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('fivem.identifyToken', null));

    $token = cef_token();
    expect($token)->toHaveLength(40)->and(cef_token())->toBe($token)
        ->and(FiveMCharacter::query()->sole()->token_hash)->toBe(hash('sha256', $token));

    $char = ['session_id' => $token, 'char_name' => 'Max Muster', 'char_job' => 'ambulance', 'char_id' => '7'];
    identify(['session_id' => 'unknown-token'] + $char)->assertNotFound();
    identify($char)->assertOk()->assertExactJson(['success' => true, 'message' => 'Charakter-Daten erfolgreich gesetzt', 'char_name' => 'Max Muster', 'char_job' => 'ambulance']);
    identify($char)->assertOk(); // ef_bridge after a restart
    identify(['char_name' => 'Someone Else'] + $char)->assertStatus(409);
    expect(FiveMCharacter::current()?->char_name)->toBe('Max Muster')
        ->and(cef_token())->toBe($token); // the used token again, ef_bridge skips it (state.linked)

    $this->actingAs(User::factory()->create());
    expect(FiveMCharacter::current())->toBeNull();
    $this->actingAs($user);
    $this->travel(13)->hours();
    expect(FiveMCharacter::current())->toBeNull();
    $fresh = cef_token();
    expect($fresh)->not->toBe($token);
    $this->travel(11)->minutes();
    identify(['session_id' => $fresh] + $char)->assertNotFound();
});

it('applies char_lock and job_filter to the vehicle login once a character is known', function () {
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create(['full_name' => 'Max  Muster'])->id]);
    $police = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'allowed_jobs' => ['police']]);
    $fire = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'allowed_jobs' => ['fire']]);
    app(Settings::class)->set('enotf.char_lock', true);
    app(Settings::class)->set('enotf.job_filter', true);
    $this->actingAs($user);

    same_session()->post('/firetab/login', ['vehicle_id' => $police->id])->assertRedirect('/firetab/list');

    $char = ['session_id' => cef_token(), 'char_name' => 'Other Person', 'char_job' => 'fire'];
    identify($char)->assertOk();
    same_session()->post('/firetab/login', ['vehicle_id' => $fire->id])->assertSessionHasErrors(['vehicle_id' => __('crew.char_mismatch')]);

    FiveMCharacter::query()->update(['char_name' => ' max muster ']);
    same_session()->post('/firetab/login', ['vehicle_id' => $police->id])->assertSessionHasErrors(['vehicle_id' => __('crew.vehicle_unavailable')]);
    same_session()->post('/firetab/login', ['vehicle_id' => $fire->id])->assertRedirect('/firetab/list');
});

function same_session()
{
    return test()->withCookie(config('session.cookie'), session()->getId());
}

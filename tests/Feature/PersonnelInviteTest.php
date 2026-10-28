<?php

use App\Models\Personnel;
use App\Models\RegistrationCode;
use App\Models\Role;
use App\Models\User;
use App\Personnel\AccountLink;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $s = app(Settings::class);
    $s->set('discord.client_id', '123456789012345678');
    $s->set('discord.client_secret', 'client-secret-value', encrypted: true);
    $s->set('auth.registration_mode', 'code');
});

function pi_user(string $role = 'SGL'): User
{
    return User::factory()->withRole(Role::findByName($role))->create();
}

function pi_register(RegistrationCode $code, string $discordId = '22222222222222222'): void
{
    $account = (new SocialiteUser)->setRaw(['id' => $discordId])
        ->map(['id' => $discordId, 'nickname' => 'newbie', 'name' => 'newbie', 'email' => null, 'avatar' => null]);
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($account);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    test()->withSession(['registration_code' => $code->code])->get('/auth/discord/callback?code=x&state=s')->assertRedirect('/');
}

// ----- POST /personnel/{personnel}/invite -----

it('creates a code bound to the record and reuses the open one', function () {
    $sgl = pi_user();
    $person = Personnel::factory()->create(['full_name' => 'Erika Muster']);

    $first = $this->actingAs($sgl)->postJson('/personnel/'.$person->id.'/invite')->assertOk();
    $code = RegistrationCode::sole();
    expect($first->json())->toBe(['url' => 'http://localhost/invite?code='.$code->code, 'existing' => false])
        ->and($code->personnel_id)->toBe($person->id)
        ->and($code->label)->toBe('Erika Muster')
        ->and($code->created_by)->toBe($sgl->id);

    $this->actingAs($sgl)->postJson('/personnel/'.$person->id.'/invite')->assertOk()
        ->assertExactJson(['url' => $first->json('url'), 'existing' => true]);
    expect(RegistrationCode::count())->toBe(1)
        ->and(Activity::where('description', 'Einladung erstellt')->sole()->properties['context'])
        ->toBe(['registration_code_id' => $code->id, 'personnel_id' => $person->id]);
});

it('does not reuse used or expired codes', function () {
    $sgl = pi_user();
    $person = Personnel::factory()->create();
    $used = RegistrationCode::generate(null, null, null);
    $used->forceFill(['personnel_id' => $person->id, 'used_at' => now()])->save();
    $expired = RegistrationCode::generate(null, null, now()->subDay());
    $expired->forceFill(['personnel_id' => $person->id])->save();

    $this->actingAs($sgl)->postJson('/personnel/'.$person->id.'/invite')->assertOk()->assertJsonPath('existing', false);
    expect(RegistrationCode::count())->toBe(3);
});

it('answers 422 for a record that has an account', function () {
    $person = Personnel::factory()->create();
    AccountLink::link(pi_user('Gast'), $person, null);

    $this->actingAs(pi_user())->postJson('/personnel/'.$person->id.'/invite')->assertUnprocessable()
        ->assertJsonPath('message', __('account_link.personnel_taken'));
    expect(RegistrationCode::count())->toBe(0);
});

it('needs personnel.edit and users.create', function () {
    $person = Personnel::factory()->create();

    $this->postJson('/personnel/'.$person->id.'/invite')->assertUnauthorized();
    $this->actingAs(pi_user('Gast'))->postJson('/personnel/'.$person->id.'/invite')->assertForbidden();
    $this->actingAs(pi_user('Personaler'))->postJson('/personnel/'.$person->id.'/invite')->assertForbidden();
    $this->actingAs(pi_user('Admin'))->postJson('/personnel/999999/invite')->assertNotFound();
    expect(RegistrationCode::count())->toBe(0);
    $this->actingAs(pi_user('Admin'))->postJson('/personnel/'.$person->id.'/invite')->assertOk();
});

// ----- invitations page -----

it('binds a code to a free record from the invitations page and lists the free ones', function () {
    $sgl = pi_user();
    $free = Personnel::factory()->create(['full_name' => 'Frei']);
    $taken = Personnel::factory()->create();
    AccountLink::link(pi_user('Gast'), $taken, null);

    $this->actingAs($sgl)->get('/users/invitations')->assertInertia(fn (Assert $p) => $p
        ->where('freePersonnel', [['id' => $free->id, 'full_name' => 'Frei', 'service_number' => $free->service_number]]));

    $this->actingAs($sgl)->post('/users/invitations', ['personnel_id' => $free->id])->assertSessionHasNoErrors();
    expect(RegistrationCode::sole()->personnel_id)->toBe($free->id);

    $this->actingAs($sgl)->get('/users/invitations')->assertInertia(fn (Assert $p) => $p->where('codes.0.personnel', 'Frei'));

    $this->actingAs($sgl)->post('/users/invitations', ['personnel_id' => $taken->id])->assertSessionHasErrors('personnel_id');
    $this->actingAs($sgl)->post('/users/invitations', ['personnel_id' => 999999])->assertSessionHasErrors('personnel_id');
    $this->actingAs($sgl)->post('/users/invitations', ['personnel_id' => 'x'])->assertSessionHasErrors('personnel_id');
    expect(RegistrationCode::count())->toBe(1);
});

// ----- redemption -----

it('links the new account on redemption and sets its name', function () {
    $person = Personnel::factory()->create(['full_name' => 'Erika Muster']);
    $code = RegistrationCode::generate(null, null, null);
    $code->forceFill(['personnel_id' => $person->id])->save();

    pi_register($code);

    $user = User::where('discord_id', '22222222222222222')->sole();
    expect($user->personnel_id)->toBe($person->id)->and($user->name)->toBe('Erika Muster')
        ->and(Activity::where('description', 'Mitarbeiter verknüpft [Benutzer-ID: '.$user->id.']')->sole()->details)->toEndWith('über die Einladung');
    $this->assertAuthenticatedAs($user);
});

it('registers without a link when the record got another account meanwhile, and audits it', function () {
    $person = Personnel::factory()->create();
    $code = RegistrationCode::generate(null, null, null);
    $code->forceFill(['personnel_id' => $person->id])->save();
    AccountLink::link(pi_user('Gast'), $person, null);

    pi_register($code);

    $user = User::where('discord_id', '22222222222222222')->sole();
    expect($user->personnel_id)->toBeNull()->and($code->fresh()->used_by)->toBe($user->id);
    $entry = Activity::where('description', 'Einladung ohne Mitarbeiterverknüpfung eingelöst [Benutzer-ID: '.$user->id.']')->sole();
    expect($entry->details)->toBe(__('account_link.personnel_taken').' (Akten-ID '.$person->id.')')
        ->and($entry->properties['context'])->toBe(['user_id' => $user->id, 'personnel_id' => $person->id]);
    $this->assertAuthenticatedAs($user);
});

it('registers normally when the record was deleted between invite and redemption', function () {
    $person = Personnel::factory()->create();
    $code = RegistrationCode::generate(null, null, null);
    $code->forceFill(['personnel_id' => $person->id])->save();
    $person->delete();

    pi_register($code);

    $user = User::where('discord_id', '22222222222222222')->sole();
    expect($user->personnel_id)->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('records a missing record in linkInvited without throwing', function () {
    $user = pi_user('Gast');

    AccountLink::linkInvited($user, 999999);
    AccountLink::linkInvited($user, null);

    expect($user->fresh()->personnel_id)->toBeNull()
        ->and(Activity::where('description', 'like', 'Einladung ohne Mitarbeiterverknüpfung%')->sole()->details)
        ->toBe(__('account_link.personnel_missing').' (Akten-ID 999999)');
});

it('reuses an open code only with no expiry or more than 24 h left', function () {
    $sgl = pi_user();
    $person = Personnel::factory()->create();
    $soon = RegistrationCode::generate(null, null, now()->addHours(23));
    $soon->forceFill(['personnel_id' => $person->id])->save();

    $this->actingAs($sgl)->postJson('/personnel/'.$person->id.'/invite')->assertOk()->assertJsonPath('existing', false);
    expect(RegistrationCode::count())->toBe(2);

    $other = Personnel::factory()->create();
    $later = RegistrationCode::generate(null, null, now()->addHours(25));
    $later->forceFill(['personnel_id' => $other->id])->save();
    $this->actingAs($sgl)->postJson('/personnel/'.$other->id.'/invite')->assertOk()
        ->assertExactJson(['url' => $later->url(), 'existing' => true]);
});

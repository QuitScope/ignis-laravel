<?php

use App\Events\PersonnelSaved;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use App\Personnel\AccountLink;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function al_user(string $role = 'Gast', array $attrs = []): User
{
    return User::factory()->withRole(Role::findByName($role))->create($attrs);
}

function al_person(array $attrs = []): Personnel
{
    return Personnel::factory()->create($attrs);
}

function al_race(User $rival, Personnel $person): void
{
    $done = false;
    DB::listen(function (QueryExecuted $q) use (&$done, $rival, $person) {
        if (! $done && str_contains($q->sql, 'exists') && str_contains($q->sql, 'personnel_id')) {
            $done = true;
            DB::table('users')->where('id', $rival->id)->update(['personnel_id' => $person->id]);
        }
    });
}

// ----- AccountLink::link / unlink -----

it('links in a transaction, sets the name from the record and audits once', function () {
    $user = al_user(attrs: ['name' => 'Discord Name']);
    $person = al_person(['full_name' => 'Erika Muster']);
    $admin = al_user('Admin');

    expect(AccountLink::link($user, $person, $admin))->toBeTrue()
        ->and($user->personnel_id)->toBe($person->id)
        ->and($user->fresh()->personnel_id)->toBe($person->id)
        ->and($user->fresh()->name)->toBe('Erika Muster')
        ->and(AccountLink::link($user, $person, $admin))->toBeFalse();

    $entry = Activity::where('description', 'Mitarbeiter verknüpft [Benutzer-ID: '.$user->id.']')->sole();
    expect($entry->is_global)->toBeTruthy()
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->details)->toBe('Erika Muster (Akten-ID '.$person->id.'), von Hand')
        ->and($entry->properties['context'])->toBe(['user_id' => $user->id, 'personnel_id' => $person->id]);
})->group('mariadb');

it('refuses an account that already has another record', function () {
    $user = al_user();
    $first = al_person();
    AccountLink::link($user, $first, null);

    expect(fn () => AccountLink::link($user, al_person(), null))->toThrow(DomainException::class, __('account_link.user_taken'));
    expect($user->fresh()->personnel_id)->toBe($first->id);
});

it('refuses a record that already has another account', function () {
    $person = al_person();
    $owner = al_user();
    AccountLink::link($owner, $person, null);

    $other = al_user();
    expect(fn () => AccountLink::link($other, $person, null))->toThrow(DomainException::class, __('account_link.personnel_taken'));
    expect($other->fresh()->personnel_id)->toBeNull();
});

it('turns a lost race on the unique index into a DomainException', function () {
    $person = al_person();
    $rival = al_user();
    $user = al_user();
    al_race($rival, $person);

    expect(fn () => AccountLink::link($user, $person, null))->toThrow(DomainException::class, __('account_link.personnel_taken'));
    expect($user->fresh()->personnel_id)->toBeNull()
        ->and(Activity::where('description', 'like', 'Mitarbeiter verknüpft%')->count())->toBe(0);
})->group('mariadb');

it('refuses a record deleted meanwhile', function () {
    $person = al_person();
    $person->delete();

    expect(fn () => AccountLink::link(al_user(), $person, null))->toThrow(DomainException::class, __('account_link.personnel_missing'));
});

it('unlinks, keeps the name and audits; unlinking a free account does nothing', function () {
    $user = al_user();
    $person = al_person(['full_name' => 'Erika Muster']);
    AccountLink::link($user, $person, null);

    AccountLink::unlink($user, null);
    AccountLink::unlink($user, null);

    expect($user->fresh()->personnel_id)->toBeNull()->and($user->fresh()->name)->toBe('Erika Muster')
        ->and(Activity::where('description', 'Mitarbeiterverknüpfung gelöst [Benutzer-ID: '.$user->id.']')->count())->toBe(1);
});

// ----- POST /users/{user}/personnel -----

it('links and unlinks through the route', function () {
    $admin = al_user('Admin');
    $user = al_user();
    $person = al_person(['full_name' => 'Erika Muster']);

    $this->actingAs($admin)->from('/users/'.$user->id.'/edit')->post('/users/'.$user->id.'/personnel', ['personnel_id' => $person->id])
        ->assertRedirect('/users/'.$user->id.'/edit')->assertSessionHasNoErrors();
    expect($user->fresh()->personnel_id)->toBe($person->id)->and($user->fresh()->name)->toBe('Erika Muster');

    $this->actingAs($admin)->post('/users/'.$user->id.'/personnel', ['unlink' => 1])->assertSessionHasNoErrors();
    expect($user->fresh()->personnel_id)->toBeNull();
});

it('answers conflicts with a validation error, never a 500', function () {
    $admin = al_user('Admin');
    $person = al_person();
    AccountLink::link(al_user(), $person, null);

    $this->actingAs($admin)->post('/users/'.al_user()->id.'/personnel', ['personnel_id' => $person->id])
        ->assertRedirect()->assertSessionHasErrors(['personnel_id' => __('account_link.personnel_taken')]);
    $this->actingAs($admin)->post('/users/'.al_user()->id.'/personnel', ['personnel_id' => 999999])
        ->assertSessionHasErrors(['personnel_id' => __('account_link.personnel_missing')]);
});

it('lets two parallel links to one record end in one link and one error', function () {
    $admin = al_user('Admin');
    $person = al_person();
    $first = al_user();
    $second = al_user();
    al_race(al_user(), $person);

    $this->actingAs($admin)->post('/users/'.$second->id.'/personnel', ['personnel_id' => $person->id])
        ->assertRedirect()->assertSessionHasErrors(['personnel_id' => __('account_link.personnel_taken')]);
    $this->actingAs($admin)->post('/users/'.$first->id.'/personnel', ['personnel_id' => $person->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/users/'.$second->id.'/personnel', ['personnel_id' => $person->id])
        ->assertSessionHasErrors(['personnel_id' => __('account_link.personnel_taken')]);
    expect(User::where('personnel_id', $person->id)->pluck('id')->all())->toBe([$first->id]);
})->group('mariadb');

it('validates the body', function (array $body) {
    $this->actingAs(al_user('Admin'))->post('/users/'.al_user()->id.'/personnel', $body)->assertSessionHasErrors('personnel_id');
})->with([
    'empty' => [[]],
    'not a number' => [['personnel_id' => 'x']],
    'array' => [['personnel_id' => [1]]],
]);

it('enforces users.edit, the rank rules and never the own account', function () {
    $person = al_person();
    $sgl = al_user('SGL');

    $this->post('/users/'.al_user()->id.'/personnel', ['personnel_id' => $person->id])->assertRedirect('/login');
    $this->actingAs(al_user('Personaler'))->post('/users/'.al_user()->id.'/personnel', ['personnel_id' => $person->id])->assertForbidden();
    $this->actingAs($sgl)->post('/users/'.al_user('Admin')->id.'/personnel', ['personnel_id' => $person->id])->assertForbidden();
    $this->actingAs($sgl)->post('/users/'.al_user('SGL')->id.'/personnel', ['unlink' => 1])->assertForbidden();
    $this->actingAs($sgl)->post('/users/'.$sgl->id.'/personnel', ['personnel_id' => $person->id])->assertForbidden();
    $super = User::factory()->superAdmin()->create();
    $this->actingAs($super)->post('/users/'.al_user('Admin')->id.'/personnel', ['personnel_id' => $person->id])->assertSessionHasNoErrors();
    $this->actingAs($super)->post('/users/'.User::factory()->superAdmin()->create()->id.'/personnel', ['personnel_id' => $person->id])->assertForbidden();
    AccountLink::unlink(User::where('personnel_id', $person->id)->sole(), null);

    expect(User::whereNotNull('personnel_id')->count())->toBe(0);
    $this->actingAs($sgl)->post('/users/'.al_user()->id.'/personnel', ['personnel_id' => $person->id])->assertSessionHasNoErrors();
    expect(User::whereNotNull('personnel_id')->count())->toBe(1);
});

it('shows the link and at most 200 free records, sorted, on the edit page', function () {
    $admin = al_user('Admin');
    $rank = Rank::factory()->create();
    $fire = FireQualification::create(['priority' => 0, 'short_name' => '-', 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    $ems = EmsQualification::create(['priority' => 0, 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    $shared = ['rank_id' => $rank->id, 'fire_qualification_id' => $fire->id, 'ems_qualification_id' => $ems->id];
    foreach (range(200, 0) as $n) {
        al_person(['full_name' => sprintf('Person %03d', $n), ...$shared]);
    }
    $taken = al_person(['full_name' => 'Aaron Taken', ...$shared]);
    AccountLink::link(al_user(), $taken, null);
    $user = al_user();

    DB::enableQueryLog();
    $this->actingAs($admin)->get('/users/'.$user->id.'/edit')->assertInertia(fn (Assert $p) => $p
        ->where('personnel', null)
        ->has('freePersonnel', 200)
        ->has('freePersonnel.0', fn (Assert $r) => $r->hasAll(['id', 'full_name', 'service_number']))
        ->where('freePersonnel', fn ($rows) => collect($rows)->pluck('full_name')->all() === array_map(fn ($n) => sprintf('Person %03d', $n), range(0, 199))));
    expect(count(DB::getQueryLog()))->toBeLessThan(40);

    $this->actingAs($admin)->get('/users/'.$taken->user->id.'/edit')->assertInertia(fn (Assert $p) => $p
        ->where('personnel', ['id' => $taken->id, 'full_name' => 'Aaron Taken', 'service_number' => $taken->service_number])
        ->where('freePersonnel', []));
});

it('shows the account on the profile with link, unlink and the free accounts the actor may link', function () {
    $sgl = al_user('SGL');
    $person = al_person();
    $free = al_user('Gast', ['username' => 'free_one']);
    al_user('Admin', ['username' => 'too_high']);
    AccountLink::link(al_user(), al_person(), null);

    $this->actingAs($sgl)->get('/personnel/'.$person->id)->assertInertia(fn (Assert $p) => $p
        ->where('account', null)
        ->where('freeAccounts', [['id' => $free->id, 'username' => 'free_one']])
        ->where('can.link', true)->where('can.invite', true));

    AccountLink::link($free, $person, null);
    $this->actingAs($sgl)->get('/personnel/'.$person->id)->assertInertia(fn (Assert $p) => $p
        ->where('account', ['id' => $free->id, 'username' => 'free_one', 'is_active' => true, 'can_update' => true, 'can_unlink' => true])
        ->where('freeAccounts', [])->where('can.invite', false));

    $this->actingAs(al_user('Personaler'))->get('/personnel/'.$person->id)->assertInertia(fn (Assert $p) => $p
        ->where('account.can_update', false)->where('can.link', false)->where('freeAccounts', []));
});

// ----- automatic link over the Discord ID -----

it('auto-links exactly one free active account to exactly one record', function () {
    $user = al_user(attrs: ['discord_id' => '111111111111111111']);
    $person = al_person(['discord_id' => '111111111111111111', 'full_name' => 'Auto Mann']);

    expect(AccountLink::autoLinkByDiscord('111111111111111111')?->id)->toBe($user->id)
        ->and($user->fresh()->personnel_id)->toBe($person->id)
        ->and($user->fresh()->name)->toBe('Auto Mann')
        ->and(Activity::where('description', 'Mitarbeiter verknüpft [Benutzer-ID: '.$user->id.']')->sole()->details)->toEndWith('automatisch über die Discord-ID');
});

it('does not auto-link with two records, an inactive or already linked account, or a taken record', function () {
    al_user(attrs: ['discord_id' => '111111111111111111']);
    al_person(['discord_id' => '111111111111111111']);
    al_person(['discord_id' => '111111111111111111']);
    expect(AccountLink::autoLinkByDiscord('111111111111111111'))->toBeNull();

    User::factory()->inactive()->create(['discord_id' => '222222222222222222']);
    al_person(['discord_id' => '222222222222222222']);
    expect(AccountLink::autoLinkByDiscord('222222222222222222'))->toBeNull();

    $linked = al_user(attrs: ['discord_id' => '333333333333333333']);
    AccountLink::link($linked, al_person(), null);
    al_person(['discord_id' => '333333333333333333']);
    expect(AccountLink::autoLinkByDiscord('333333333333333333'))->toBeNull();

    $taken = al_person(['discord_id' => '444444444444444444']);
    AccountLink::link(al_user(), $taken, null);
    al_user(attrs: ['discord_id' => '444444444444444444']);
    expect(AccountLink::autoLinkByDiscord('444444444444444444'))->toBeNull()
        ->and(User::where('personnel_id', $taken->id)->count())->toBe(1);
});

it('auto-links and syncs the name when a record is saved, idempotently', function () {
    $user = al_user(attrs: ['discord_id' => '111111111111111111', 'name' => 'Discord']);
    $person = al_person(['discord_id' => '111111111111111111', 'full_name' => 'Erst Name']);

    event(new PersonnelSaved($person->id));
    event(new PersonnelSaved($person->id));
    expect($user->fresh()->personnel_id)->toBe($person->id)->and($user->fresh()->name)->toBe('Erst Name')
        ->and(Activity::where('description', 'like', 'Mitarbeiter verknüpft%')->count())->toBe(1);

    $person->update(['full_name' => 'Neu Name']);
    event(new PersonnelSaved($person->id));
    expect($user->fresh()->name)->toBe('Neu Name');

    event(new PersonnelSaved(999999));
});

it('syncs the name through the profile form', function () {
    $admin = al_user('Admin');
    $person = al_person(['full_name' => 'Alt Name']);
    $user = al_user();
    AccountLink::link($user, $person, null);
    $data = [
        'full_name' => 'Neu Name', 'birth_date' => $person->birth_date->toDateString(), 'gender' => $person->gender,
        'service_number' => $person->service_number, 'hired_at' => $person->hired_at->toDateString(), 'rank_id' => $person->rank_id,
        'fire_qualification_id' => $person->fire_qualification_id, 'ems_qualification_id' => $person->ems_qualification_id,
    ];

    $this->actingAs($admin)->put('/personnel/'.$person->id, $data)->assertSessionHasNoErrors();
    expect($user->fresh()->name)->toBe('Neu Name');
});

it('auto-links on Discord login', function () {
    $s = app(Settings::class);
    $s->set('discord.client_id', '123456789012345678');
    $s->set('discord.client_secret', 'client-secret-value', encrypted: true);
    $user = al_user(attrs: ['discord_id' => '111111111111111111']);
    $person = al_person(['discord_id' => '111111111111111111']);
    $account = (new SocialiteUser)->setRaw(['id' => '111111111111111111'])
        ->map(['id' => '111111111111111111', 'nickname' => 'x', 'name' => 'x', 'email' => null, 'avatar' => null]);
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($account);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    $this->get('/auth/discord/callback?code=x&state=s')->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->personnel_id)->toBe($person->id);
});

it('never auto-links a super-admin account', function () {
    $super = User::factory()->superAdmin()->create(['discord_id' => '555555555555555555', 'name' => 'Chef']);
    $person = al_person(['discord_id' => '555555555555555555', 'full_name' => 'Anders']);

    expect(AccountLink::autoLinkByDiscord('555555555555555555'))->toBeNull();
    event(new PersonnelSaved($person->id));

    expect($super->fresh()->personnel_id)->toBeNull()->and($super->fresh()->name)->toBe('Chef')
        ->and(Activity::where('description', 'like', 'Mitarbeiter verknüpft%')->count())->toBe(0);
});

it('lets a super admin link and unlink their own account, and nobody else', function () {
    $super = User::factory()->superAdmin()->create();
    $person = al_person();

    $this->actingAs($super)->get('/personnel/'.$person->id)->assertInertia(fn (Assert $p) => $p
        ->where('freeAccounts', fn ($rows) => collect($rows)->pluck('id')->contains($super->id)));
    $this->actingAs($super)->post('/users/'.$super->id.'/personnel', ['personnel_id' => $person->id])->assertSessionHasNoErrors();
    expect($super->fresh()->personnel_id)->toBe($person->id);

    $this->actingAs($super)->get('/personnel/'.$person->id)->assertInertia(fn (Assert $p) => $p
        ->where('account.can_unlink', true)->where('account.can_update', false));
    $this->actingAs($super)->post('/users/'.$super->id.'/personnel', ['unlink' => 1])->assertSessionHasNoErrors();
    expect($super->fresh()->personnel_id)->toBeNull();

    foreach (['Admin', 'SGL'] as $role) {
        $actor = al_user($role);
        $this->actingAs($actor)->post('/users/'.$actor->id.'/personnel', ['personnel_id' => $person->id])->assertForbidden();
        $this->actingAs($actor)->post('/users/'.$super->id.'/personnel', ['personnel_id' => $person->id])->assertForbidden();
    }
    expect(User::whereNotNull('personnel_id')->count())->toBe(0);
});

<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function cfgUser(string ...$perms): User
{
    $role = Role::create(['name' => 'C'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function cfgSettings(): Settings
{
    return app(Settings::class);
}

it('requires login and the admin right', function () {
    $this->get('/settings/system/config')->assertRedirect('/login');
    $this->put('/settings/system/config/general', [])->assertRedirect('/login');

    foreach ([cfgUser('personnel.documents.manage'), cfgUser('personnel.view')] as $u) {
        $this->actingAs($u)->get('/settings/system/config')->assertForbidden();
        $this->actingAs($u)->put('/settings/system/config/general', ['system.name' => str_repeat('x', 500)])->assertForbidden();
    }
    $this->actingAs(cfgUser('admin'))->get('/settings/system/config')->assertOk();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/settings/system/config')->assertOk();
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/general', [])->assertRedirect();
});

it('grants system.admin to admins only', function () {
    expect(cfgUser('admin')->can('system.admin'))->toBeTrue()->and(cfgUser('personnel.view')->can('system.admin'))->toBeFalse();
});

it('redirects the old organisation page with 301', function () {
    $this->get('/settings/organisation')->assertStatus(301)->assertRedirect('/settings/system/config?tab=organisation');
});

it('lists the tabs of the groups with editable settings, interfaces included, and falls back on an unknown tab', function (string $query, string $tab) {
    $this->actingAs(cfgUser('admin'))->get('/settings/system/config'.$query)->assertInertia(fn (Assert $p) => $p
        ->component('Settings/System/Config')
        ->where('tabs', ['general', 'organisation', 'auth', 'interfaces', 'features', 'legal', 'privacy', 'enotf', 'firetab'])
        ->where('tab', $tab));
})->with([['', 'general'], ['?tab=auth', 'auth'], ['?tab=nope', 'general'], ['?tab[]=auth', 'general'], ['?tab=interfaces', 'interfaces']]);

it('renders every tab with its defaults and never sends a secret value', function () {
    $admin = cfgUser('admin');
    cfgSettings()->set('discord.client_secret', 'S3CRET-VALUE-XYZ', encrypted: true);
    cfgSettings()->set('api.key', 'APIKEY-VALUE-XYZ', encrypted: true);

    $res = $this->actingAs($admin)->get('/settings/system/config?tab=auth');
    $res->assertInertia(fn (Assert $p) => $p
        ->where('groups.general.0', fn ($f) => $f['key'] === 'system.name' && $f['type'] === 'string' && $f['value'] === '')
        ->where('groups.general.1.value', '#f0500a')
        ->where('groups.auth.0', fn ($f) => $f['key'] === 'auth.registration_mode' && $f['value'] === 'code' && in_array('open', $f['options'], true))
        ->where('groups.auth.3', ['key' => 'discord.client_secret', 'type' => 'string', 'secret' => true, 'is_set' => true])
        ->where('groups.interfaces', []));
    expect($res->getContent())->not->toContain('S3CRET-VALUE-XYZ')->not->toContain('APIKEY-VALUE-XYZ');

    $keys = collect($res->viewData('page')['props']['groups'])->flatten(1)->pluck('key');
    expect($keys)->not->toContain('system.logo')->not->toContain('api.key');
});

it('saves a group, audits each changed key exactly and writes nothing for unchanged values', function () {
    $admin = cfgUser('admin');
    $this->actingAs($admin)->put('/settings/system/config/general', ['system.name' => 'Leitstelle', 'system.accent_color' => '#F0500A', 'app.locale' => 'en'])
        ->assertRedirect('/settings/system/config?tab=general')->assertSessionHas('status', 'settings.system.saved');

    expect(cfgSettings()->get('system.name'))->toBe('Leitstelle')->and(cfgSettings()->get('app.locale'))->toBe('en')
        ->and(cfgSettings()->has('system.accent_color'))->toBeFalse();
    $a = Activity::where('description', 'Einstellung system.name geändert')->sole();
    expect($a->log_name)->toBe('System')->and($a->details)->toBe(' → Leitstelle')->and((bool) $a->is_global)->toBeTrue()
        ->and($a->properties['context'])->toBe(['key' => 'system.name'])->and($a->causer_id)->toBe($admin->id)
        ->and(Activity::where('description', 'Einstellung app.locale geändert')->sole()->details)->toBe('de → en')
        ->and(Activity::count())->toBe(2);

    $this->actingAs($admin)->put('/settings/system/config/general', ['system.name' => 'Leitstelle', 'app.locale' => 'en'])->assertRedirect();
    expect(Activity::count())->toBe(2);
});

it('saves a group partially', function () {
    cfgSettings()->set('organisation.city', 'Sandy Shores');
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/organisation', ['organisation.name' => '  Wache  '])->assertSessionDoesntHaveErrors();

    expect(cfgSettings()->get('organisation.name'))->toBe('Wache')->and(cfgSettings()->get('organisation.city'))->toBe('Sandy Shores');
});

it('truncates audit details to 200 characters', function () {
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/organisation', ['organisation.name' => str_repeat('a', 255)]);
    expect(mb_strlen(Activity::where('description', 'Einstellung organisation.name geändert')->sole()->details))->toBe(200);
});

it('formats bool details', function () {
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/features', ['personnel.require_character_id' => true]);
    expect(Activity::where('description', 'Einstellung personnel.require_character_id geändert')->sole()->details)->toBe('nein → ja');
});

it('rejects invalid values per type and stores nothing', function () {
    foreach ([
        'string too long' => ['general', ['system.name' => str_repeat('a', 101)], ['system.name']],
        'colour' => ['general', ['system.accent_color' => 'red'], ['system.accent_color']],
        'url scheme' => ['general', ['system.meta_image_url' => 'http://example.org/a.png'], ['system.meta_image_url']],
        'enum' => ['general', ['app.locale' => 'xx'], ['app.locale']],
        'enum auth' => ['auth', ['auth.registration_mode' => 'everyone'], ['auth.registration_mode']],
        'bool' => ['auth', ['auth.tablet_login' => 'maybe'], ['auth.tablet_login']],
        'discord id rule' => ['auth', ['discord.client_id' => 'abc'], ['discord.client_id']],
        'redirect path rule' => ['auth', ['discord.redirect_path' => 'callback'], ['discord.redirect_path']],
        'delete only with value' => ['auth', ['install.admin_discord_id' => '123456789012345678'], ['install.admin_discord_id']],
        'array for string' => ['organisation', ['organisation.name' => ['x']], ['organisation.name']],
        'two errors at once' => ['legal', ['legal.imprint_url' => 'ftp://x', 'legal.privacy_url' => 'javascript:alert(1)'], ['legal.imprint_url', 'legal.privacy_url']],
    ] as [$group, $body, $errors]) {
        $this->actingAs(cfgUser('admin'))->put("/settings/system/config/{$group}", $body)->assertSessionHasErrors($errors);

        expect(Setting::count())->toBe(0)->and(Activity::count())->toBe(0);
    }
});

it('refuses unknown keys, keys of another group and not editable keys (no mass assignment)', function (string $key) {
    cfgSettings()->set('api.key', 'ORIGINAL', encrypted: true);
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/general', ['system.name' => 'x', $key => 'pwned'])->assertSessionHasErrors([$key]);

    expect(cfgSettings()->get('api.key'))->toBe('ORIGINAL')->and(cfgSettings()->has('system.name'))->toBeFalse()->and(cfgSettings()->has('evil.key'))->toBeFalse()
        ->and(cfgSettings()->get('system.logo'))->toBe('');
})->with(['api.key', 'evil.key', 'auth.tablet_login', 'system.logo']);

it('answers 404 for an unknown group', function () {
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/nope', [])->assertNotFound();
});

it('keeps a secret when it is sent blank and replaces it encrypted otherwise, never auditing the value', function () {
    $admin = cfgUser('admin');
    cfgSettings()->set('discord.client_secret', 'OLD-SECRET', encrypted: true);

    $this->actingAs($admin)->put('/settings/system/config/auth', ['discord.client_secret' => '', 'auth.tablet_login' => true])->assertSessionDoesntHaveErrors();
    expect(cfgSettings()->get('discord.client_secret'))->toBe('OLD-SECRET')
        ->and(Activity::where('description', 'like', '%discord.client_secret%')->count())->toBe(0);

    $this->actingAs($admin)->put('/settings/system/config/auth', ['discord.client_secret' => 'NEW-SECRET'])->assertSessionDoesntHaveErrors();
    $row = Setting::where('key', 'discord.client_secret')->sole();
    expect(cfgSettings()->get('discord.client_secret'))->toBe('NEW-SECRET')->and($row->is_encrypted)->toBeTrue()
        ->and($row->getRawOriginal('value'))->not->toContain('NEW-SECRET')->and(Crypt::decryptString($row->getRawOriginal('value')))->toContain('NEW-SECRET');
    $a = Activity::where('description', 'Einstellung discord.client_secret geändert (geheim)')->sole();
    expect($a->details)->toBeNull()->and($a->properties['context'])->toBe(['key' => 'discord.client_secret'])
        ->and(json_encode(Activity::all()->toArray()))->not->toContain('NEW-SECRET')->not->toContain('OLD-SECRET');

    $this->actingAs($admin)->put('/settings/system/config/auth', ['discord.client_secret' => 'NEW-SECRET']);
    expect(Activity::where('description', 'like', '%client_secret%')->count())->toBe(1);
});

it('does not echo a mistyped secret back in the errors', function () {
    $res = $this->actingAs(cfgUser('admin'))->put('/settings/system/config/auth', ['discord.client_secret' => ['LEAK-ME']]);
    $res->assertSessionHasErrors(['discord.client_secret']);
    expect(json_encode(session()->all()))->not->toContain('LEAK-ME');
});

it('only deletes the installation admin id', function () {
    $admin = cfgUser('admin');
    cfgSettings()->set('install.admin_discord_id', '123456789012345678');

    $this->actingAs($admin)->get('/settings/system/config?tab=auth')->assertInertia(fn (Assert $p) => $p
        ->where('groups.auth.5', fn ($f) => $f['key'] === 'install.admin_discord_id' && $f['value'] === '123456789012345678' && $f['deletable_only'] === true));

    $this->actingAs($admin)->put('/settings/system/config/auth', ['install.admin_discord_id' => ''])->assertSessionDoesntHaveErrors();
    expect(cfgSettings()->has('install.admin_discord_id'))->toBeFalse()
        ->and(Activity::where('description', 'Einstellung install.admin_discord_id geändert')->sole()->details)->toBe('123456789012345678 → ');

    $this->actingAs($admin)->put('/settings/system/config/auth', ['install.admin_discord_id' => null]);
    expect(Activity::where('description', 'like', '%admin_discord_id%')->count())->toBe(1);
});

it('accepts a list setting registered by a module', function () {
    app(SettingDefinitions::class)->register('mod.tags', 'features', 'list', []);
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/features', ['mod.tags' => ['a', ' ', 'b']])->assertSessionDoesntHaveErrors();

    expect(cfgSettings()->get('mod.tags'))->toBe(['a', 'b'])
        ->and(Activity::where('description', 'Einstellung mod.tags geändert')->sole()->details)->toBe(' → a, b');
});

it('keeps a later registered secret out of the session when a sibling field fails', function () {
    app(SettingDefinitions::class)->register('mod.bot_token', 'features', 'string', '', secret: true);
    $res = $this->actingAs(cfgUser('admin'))->put('/settings/system/config/features', ['mod.bot_token' => 'BOT-TOKEN-XYZ', 'personnel.require_character_id' => 'maybe', 'discord.client_secret' => 'OTHER-SECRET-XYZ']);

    $res->assertSessionHasErrors(['personnel.require_character_id']);
    expect(json_encode(session()->all()))->not->toContain('BOT-TOKEN-XYZ')->not->toContain('OTHER-SECRET-XYZ');
});

it('refuses a body with more than 100 keys with one generic error and flashes nothing', function () {
    $body = [];
    foreach (range(1, 3000) as $i) {
        $body["x.k{$i}"] = str_repeat('v', 20);
    }
    $res = $this->actingAs(cfgUser('admin'))->put('/settings/system/config/general', $body);

    $res->assertSessionHasErrors(['settings']);
    expect(session('errors')->getBag('default')->keys())->toBe(['settings'])->and(strlen(json_encode(session()->all())))->toBeLessThan(3000);
});

it('answers 403 before 404 for an unknown group and accepts a group registered after the routes', function () {
    $this->actingAs(cfgUser('personnel.view'))->put('/settings/system/config/nope', [])->assertForbidden();

    app(SettingDefinitions::class)->register('late.flag', 'latemod', 'bool', false);
    $this->actingAs(cfgUser('admin'))->put('/settings/system/config/latemod', ['late.flag' => true])->assertRedirect('/settings/system/config?tab=latemod');
    expect(cfgSettings()->get('late.flag'))->toBeTrue();
});

it('always stores the privacy opt-ins when sent, even at their default', function () {
    $admin = cfgUser('admin');
    $this->actingAs($admin)->put('/settings/system/config/privacy', ['announcements.hub_enabled' => false, 'updates.check_enabled' => false])->assertSessionDoesntHaveErrors();

    expect(cfgSettings()->has('announcements.hub_enabled'))->toBeTrue()->and(cfgSettings()->has('updates.check_enabled'))->toBeTrue()
        ->and(Activity::count())->toBe(0);
    $this->actingAs($admin)->put('/settings/system/config/features', ['personnel.require_character_id' => false]);
    expect(cfgSettings()->has('personnel.require_character_id'))->toBeFalse();
});

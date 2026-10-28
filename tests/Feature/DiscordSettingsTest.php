<?php

use App\Discord\DiscordBot;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const DS_TOKEN = 'NEW.token-value-QWERTY';
const DS_OLD_TOKEN = 'OLD.token-value-ASDFGH';
const DS_BOT_ID = '987654321098765432';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Http::preventStrayRequests();
    $this->logs = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $m) => $this->logs[] = $m->message.json_encode($m->context));
});

function dsUser(string ...$perms): User
{
    $role = Role::create(['name' => 'D'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function dsMe(int $status = 200): void
{
    Http::fake(['https://discord.com/api/v10/users/@me' => $status === 200
        ? Http::response(['id' => DS_BOT_ID, 'username' => 'ignis', 'global_name' => 'Ignis Bot', 'avatar' => str_repeat('c', 32)])
        : Http::response(['message' => '401: Unauthorized', 'code' => 0], $status)]);
}

function dsConnected(bool $enabled = true): void
{
    $s = app(Settings::class);
    $s->set('discord.bot.enabled', $enabled);
    $s->set('discord.bot.token', DS_OLD_TOKEN, encrypted: true);
    $s->set('discord.bot.id', DS_BOT_ID);
    $s->set('discord.bot.name', 'Alter Name');
    $s->set('discord.bot.avatar', str_repeat('d', 32));
}

function dsLeaks($test, string $secret): void
{
    expect(Activity::all()->toJson())->not->toContain($secret)
        ->and(implode("\n", $test->logs))->not->toContain($secret)
        ->and(json_encode(session()->all()))->not->toContain($secret);
}

it('lets only admins in', function () {
    $this->get('/settings/system/discord')->assertRedirect('/login');
    $this->put('/settings/system/discord', [])->assertRedirect('/login');
    $this->delete('/settings/system/discord')->assertRedirect('/login');
    $this->post('/settings/system/discord/test')->assertRedirect('/login');

    $user = dsUser('personnel.view');
    $this->actingAs($user)->get('/settings/system/discord')->assertForbidden();
    $this->actingAs($user)->put('/settings/system/discord', ['enabled' => 'nope'])->assertForbidden();
    $this->actingAs($user)->delete('/settings/system/discord')->assertForbidden();
    $this->actingAs($user)->post('/settings/system/discord/test')->assertForbidden();

    $this->actingAs(dsUser('admin'))->get('/settings/system/discord')->assertOk();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/settings/system/discord')->assertOk();
});

it('shows the bot state, the kinds with labels and the reach, never the token', function () {
    dsConnected();
    $admin = dsUser('admin');
    dsUser()->forceFill(['discord_dm' => false])->save();
    dsUser()->forceFill(['is_active' => false])->save();
    dsUser()->forceFill(['discord_id' => 'kein-snowflake'])->save();
    $p = Personnel::factory()->create(['discord_id' => '111111111111111111']);
    dsUser()->forceFill(['discord_id' => 'x2', 'personnel_id' => $p->id])->save();

    $res = $this->actingAs($admin)->get('/settings/system/discord');
    $res->assertInertia(fn (Assert $page) => $page
        ->component('Settings/System/Discord')
        ->where('bot', [
            'enabled' => true, 'connected' => true, 'tokenLost' => false, 'id' => DS_BOT_ID, 'name' => 'Alter Name',
            'avatarUrl' => 'https://cdn.discordapp.com/avatars/'.DS_BOT_ID.'/'.str_repeat('d', 32).'.png?size=128',
            'inviteUrl' => 'https://discord.com/oauth2/authorize?client_id='.DS_BOT_ID.'&scope=bot&permissions=0',
            'dmKinds' => ['mail', 'system'],
        ])
        ->where('kinds', fn ($kinds) => collect($kinds)->contains(['key' => 'system', 'label' => 'System']) && collect($kinds)->contains(['key' => 'dokument', 'label' => 'Dokumente']))
        ->where('reach', ['users' => 4, 'reachable' => 2, 'optedOut' => 1])
        ->where('ownDiscordId', true));
    expect($res->getContent())->not->toContain(DS_OLD_TOKEN);
});

it('reports a token that cannot be decrypted any more', function () {
    dsConnected();
    Setting::where('key', 'discord.bot.token')->update(['value' => 'broken-ciphertext']);
    app(Settings::class)->refresh();

    $this->actingAs(dsUser('admin'))->get('/settings/system/discord')
        ->assertInertia(fn (Assert $p) => $p->where('bot.connected', false)->where('bot.tokenLost', true));
});

it('checks a new token with discord, stores it encrypted with the bot profile and audits without the token', function () {
    dsMe();
    $admin = dsUser('admin');

    $this->actingAs($admin)->put('/settings/system/discord', ['token' => DS_TOKEN, 'enabled' => true, 'dm_kinds' => ['system', 'dokument']])
        ->assertRedirect('/settings/system/discord')->assertSessionHas('status', 'settings.discord.saved');

    $s = app(Settings::class);
    expect($s->get('discord.bot.token'))->toBe(DS_TOKEN)
        ->and(Setting::where('key', 'discord.bot.token')->sole()->is_encrypted)->toBeTrue()
        ->and(Setting::where('key', 'discord.bot.token')->sole()->value)->not->toContain(DS_TOKEN)
        ->and($s->get('discord.bot.enabled'))->toBeTrue()
        ->and($s->get('discord.bot.id'))->toBe(DS_BOT_ID)
        ->and($s->get('discord.bot.name'))->toBe('Ignis Bot')
        ->and($s->get('discord.dm_kinds'))->toBe(['system', 'dokument']);
    $a = Activity::where('description', 'Discord-Bot geändert')->sole();
    expect($a->log_name)->toBe('System')->and((bool) $a->is_global)->toBeTrue()->and($a->details)->toBe('Token, eingeschaltet, Direktnachrichten');

    $page = $this->actingAs($admin)->get('/settings/system/discord');
    expect($page->getContent())->not->toContain(DS_TOKEN);
    dsLeaks($this, DS_TOKEN);
});

it('never changes the bot name or avatar at discord', function () {
    dsConnected();
    dsMe();

    $this->actingAs(dsUser('admin'))->put('/settings/system/discord', ['token' => DS_TOKEN, 'enabled' => true, 'dm_kinds' => [], 'name' => 'Neuer Name', 'avatar' => 'data:image/png;base64,AAAA']);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    expect(app(Settings::class)->get('discord.bot.name'))->toBe('Ignis Bot');
});

it('stores nothing when discord rejects the token', function () {
    dsMe(401);

    $this->actingAs(dsUser('admin'))->from('/settings/system/discord')
        ->put('/settings/system/discord', ['token' => DS_TOKEN, 'enabled' => true, 'dm_kinds' => ['system']])
        ->assertRedirect('/settings/system/discord')
        ->assertSessionHasErrors(['token' => 'Discord lehnt das Token ab. Bitte im Developer Portal ein neues Token erzeugen und hier eintragen.']);

    expect(Setting::count())->toBe(0)->and(Activity::count())->toBe(0);
    dsLeaks($this, DS_TOKEN);
});

it('keeps the stored token when the field is blank and refuses to switch on without a token', function () {
    $admin = dsUser('admin');
    Http::fake();

    $this->actingAs($admin)->put('/settings/system/discord', ['token' => '', 'enabled' => true, 'dm_kinds' => []])->assertSessionHasErrors('enabled');
    expect(Setting::count())->toBe(0);

    dsConnected(enabled: false);
    $this->actingAs($admin)->put('/settings/system/discord', ['token' => '', 'enabled' => true, 'dm_kinds' => ['system', 'mail']])->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('discord.bot.token'))->toBe(DS_OLD_TOKEN)->and(app(Settings::class)->get('discord.bot.enabled'))->toBeTrue()
        ->and(Activity::where('description', 'Discord-Bot geändert')->sole()->details)->toBe('eingeschaltet');
    Http::assertNothingSent();
});

it('accepts only registered notification kinds', function (mixed $kinds) {
    dsConnected();

    $this->actingAs(dsUser('admin'))->put('/settings/system/discord', ['enabled' => true, 'dm_kinds' => $kinds])->assertSessionHasErrors();
    expect(app(Settings::class)->get('discord.dm_kinds'))->toBe(['mail', 'system'])->and(Activity::count())->toBe(0);
})->with([[['system', 'nope']], [['unregistered_kind']], ['system'], [[['system']]]]);

it('disconnects: deletes the token and the bot profile and switches off', function () {
    dsConnected();
    $admin = dsUser('admin');

    $this->actingAs($admin)->delete('/settings/system/discord')->assertRedirect('/settings/system/discord')->assertSessionHas('status', 'settings.discord.disconnected');

    $s = app(Settings::class);
    foreach (['discord.bot.token', 'discord.bot.id', 'discord.bot.name', 'discord.bot.avatar'] as $key) {
        expect($s->has($key))->toBeFalse($key);
    }
    expect($s->get('discord.bot.enabled'))->toBeFalse()
        ->and(Activity::where('description', 'Discord-Bot getrennt')->sole()->details)->toBeNull();
    dsLeaks($this, DS_OLD_TOKEN);
});

it('sends a test message to the own account', function () {
    config(['app.url' => 'https://ignis.example']);
    dsConnected();
    $admin = dsUser('admin');
    Http::fake([
        'https://discord.com/api/v10/users/@me/channels' => Http::response(['id' => '222222222222222222']),
        'https://discord.com/api/v10/channels/*/messages' => Http::response(['id' => '3']),
    ]);

    $this->actingAs($admin)->post('/settings/system/discord/test')->assertRedirect('/settings/system/discord')->assertSessionHas('status', 'settings.discord.test_sent');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/users/@me/channels') && $r->data() === ['recipient_id' => $admin->discord_id]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages')
        && $r->data()['embeds'][0]['title'] === 'Testnachricht'
        && $r->data()['embeds'][0]['url'] === 'https://ignis.example/settings/system/discord'
        && $r->data()['allowed_mentions'] === ['parse' => []]);
});

it('sends the test message to the discord id of the linked personnel file', function () {
    dsConnected();
    $p = Personnel::factory()->create(['discord_id' => '111111111111111111']);
    $admin = dsUser('admin');
    $admin->forceFill(['discord_id' => 'kein', 'personnel_id' => $p->id])->save();
    Http::fake(['*/users/@me/channels' => Http::response(['id' => '222222222222222222']), '*/messages' => Http::response(['id' => '3'])]);

    $this->actingAs($admin)->post('/settings/system/discord/test')->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $r) => $r->data() === ['recipient_id' => '111111111111111111']);
});

it('answers 422 for a test message without own discord id or without token', function () {
    $admin = dsUser('admin');
    Http::fake();
    $this->actingAs($admin)->postJson('/settings/system/discord/test')->assertUnprocessable()->assertJsonValidationErrors('test');

    dsConnected();
    $admin->forceFill(['discord_id' => 'kein'])->save();
    $this->actingAs($admin)->postJson('/settings/system/discord/test')->assertUnprocessable()->assertJsonValidationErrors('test');
    Http::assertNothingSent();
});

it('reports discord errors of the test message in german', function (int $status, array $body, string $message) {
    dsConnected();
    Http::fake(['*/users/@me/channels' => Http::response(['id' => '222222222222222222']), '*/messages' => Http::response($body, $status)]);

    $this->actingAs(dsUser('admin'))->post('/settings/system/discord/test')->assertSessionHasErrors(['test' => $message]);
    dsLeaks($this, DS_OLD_TOKEN);
})->with([
    '50007' => [403, ['message' => 'Cannot send messages to this user', 'code' => 50007], 'Discord stellt keine Direktnachricht zu. Die Person muss einen Server mit dem Bot teilen und Direktnachrichten von Servermitgliedern erlauben.'],
    '429' => [429, ['retry_after' => 1], 'Discord bremst gerade (Rate-Limit). Bitte gleich noch einmal versuchen.'],
    '503' => [503, [], 'Discord hat gerade eine Störung (503).'],
]);

it('marks every parameter that carries the token as sensitive, so it never shows up in a logged stack trace', function (string $class, string $method, string $param) {
    $p = collect((new ReflectionMethod($class, $method))->getParameters())->firstWhere('name', $param);
    expect($p->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    [DiscordBot::class, 'me', 'token'],
    [DiscordBot::class, 'call', 'token'],
    [Settings::class, 'set', 'value'],
]);

it('audits no kinds change for another order or for stored kinds that are not registered', function () {
    dsConnected();
    $admin = dsUser('admin');
    app(Settings::class)->set('discord.dm_kinds', ['unregistered_kind', 'system', 'dokument']);

    $this->actingAs($admin)->put('/settings/system/discord', ['enabled' => true, 'dm_kinds' => ['dokument', 'system']])->assertSessionHasNoErrors();
    expect(Activity::count())->toBe(0);
});

it('does not audit a disconnect when nothing was connected', function () {
    $this->actingAs(dsUser('admin'))->delete('/settings/system/discord')->assertRedirect('/settings/system/discord');
    expect(Activity::count())->toBe(0);
});

it('keeps stored kinds that are not offered on the page when saving', function () {
    dsConnected();
    $admin = dsUser('admin');
    app(Settings::class)->set('discord.dm_kinds', ['unregistered_kind', 'system']);

    $this->actingAs($admin)->put('/settings/system/discord', ['enabled' => true, 'dm_kinds' => ['dokument']])->assertSessionHasNoErrors();
    $kinds = app(Settings::class)->get('discord.dm_kinds');
    sort($kinds);
    expect($kinds)->toBe(['dokument', 'unregistered_kind']);
});

<?php

use App\Models\Personnel;
use App\Models\RegistrationCode;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const PD_TOKEN = 'INVITE.token-value-LKJHGF';
const PD_RECORD_ID = '777777777777777777';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Http::preventStrayRequests();
    $s = app(Settings::class);
    $s->set('discord.bot.enabled', true);
    $s->set('discord.bot.token', PD_TOKEN, encrypted: true);
    $s->set('system.name', 'Wache 1');
    config(['app.url' => 'https://ignis.example']);
    $this->sgl = User::factory()->withRole(Role::findByName('SGL'))->create();
    $this->person = Personnel::factory()->create(['full_name' => 'Erika Muster', 'discord_id' => PD_RECORD_ID]);
});

function pdFake(int $status = 200, array $body = []): void
{
    Http::fake([
        'https://discord.com/api/v10/users/@me/channels' => Http::response(['id' => '888888888888888888']),
        'https://discord.com/api/v10/channels/*/messages' => Http::response($body, $status),
    ]);
}

function pdInvite($test, Personnel $person, array $body = ['via' => 'discord'])
{
    return $test->actingAs($test->sgl)->postJson('/personnel/'.$person->id.'/invite', $body);
}

it('sends the invitation as a dm with a link button and audits it', function () {
    pdFake();

    $res = pdInvite($this, $this->person)->assertOk();

    $code = RegistrationCode::sole();
    $url = 'https://ignis.example/invite?code='.$code->code;
    expect($res->json())->toBe(['url' => $url, 'existing' => false, 'discord' => ['sent' => true]]);
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/users/@me/channels'))->sole()[0]['recipient_id'])->toBe(PD_RECORD_ID);
    $message = Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages'))->sole()[0]->data();
    expect($message['embeds'][0])->toMatchArray([
        'title' => 'Einladung zu Wache 1',
        'url' => $url,
        'footer' => ['text' => 'Wache 1 · Einladung'],
    ])->and($message['embeds'][0]['description'])->toStartWith('Hallo Erika Muster, du bist eingeladen')
        ->and($message['components'])->toBe([['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => 'Konto anlegen', 'url' => $url]]]])
        ->and($message['allowed_mentions'])->toBe(['parse' => []]);

    $audit = Activity::where('description', 'Einladung per Discord gesendet')->sole();
    expect($audit->details)->toBe('Erika Muster')
        ->and(Activity::all()->toJson())->not->toContain($code->code)->not->toContain(PD_TOKEN);
});

it('reuses the open invitation for the dm', function () {
    pdFake();
    $first = pdInvite($this, $this->person, [])->assertOk();

    pdInvite($this, $this->person)->assertOk()
        ->assertJson(['url' => $first->json('url'), 'existing' => true, 'discord' => ['sent' => true]]);
    expect(RegistrationCode::count())->toBe(1);
});

it('keeps the code and reports sent false when the dm fails', function () {
    pdFake(403, ['message' => 'Cannot send messages to this user', 'code' => 50007]);

    $res = pdInvite($this, $this->person)->assertOk();

    $code = RegistrationCode::sole();
    expect($res->json('discord'))->toBe(['sent' => false, 'message' => __('discord.error.cannot_dm')])
        ->and($res->json('url'))->toBe($code->url())
        ->and($code->isRedeemable())->toBeTrue()
        ->and(Activity::where('description', 'Einladung per Discord gesendet')->exists())->toBeFalse()
        ->and(Activity::where('description', 'Einladung erstellt')->exists())->toBeTrue();
});

it('answers 422 and makes no code without an active bot or a discord id', function (Closure $arrange, string $message) {
    $arrange($this->person);

    pdInvite($this, $this->person->fresh())->assertStatus(422)->assertJson(['message' => __($message)]);

    expect(RegistrationCode::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'bot off' => [fn () => app(Settings::class)->set('discord.bot.enabled', false), 'account_link.discord_inactive'],
    'no token' => [fn () => app(Settings::class)->forget('discord.bot.token'), 'account_link.discord_inactive'],
    'no id' => [fn (Personnel $p) => $p->update(['discord_id' => null]), 'account_link.discord_no_id'],
    'invalid id' => [fn (Personnel $p) => $p->update(['discord_id' => '12ab']), 'account_link.discord_no_id'],
]);

it('answers 422 for a record that has an account, also via discord', function () {
    User::factory()->create(['personnel_id' => $this->person->id]);

    pdInvite($this, $this->person)->assertStatus(422);
    Http::assertNothingSent();
});

it('refuses an unknown via and still checks rights first', function () {
    pdInvite($this, $this->person, ['via' => 'mail'])->assertStatus(422)->assertJsonValidationErrors('via');

    $viewer = User::factory()->withRole(Role::create(['name' => 'Viewer', 'guard_name' => 'web', 'priority' => 900, 'color' => 'info']))->create();
    $this->actingAs($viewer)->postJson('/personnel/'.$this->person->id.'/invite', ['via' => 'mail'])->assertForbidden();
});

it('shows the discord invite button only with an active bot and a valid id', function () {
    $can = fn () => $this->actingAs($this->sgl)->get('/personnel/'.$this->person->id)->assertOk();

    $can()->assertInertia(fn (Assert $p) => $p->where('can.invite_discord', true));

    $this->person->update(['discord_id' => 'nope']);
    $can()->assertInertia(fn (Assert $p) => $p->where('can.invite_discord', false));

    $this->person->update(['discord_id' => PD_RECORD_ID]);
    app(Settings::class)->set('discord.bot.enabled', false);
    $can()->assertInertia(fn (Assert $p) => $p->where('can.invite_discord', false));
});

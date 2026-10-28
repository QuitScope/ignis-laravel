<?php

use App\Discord\DiscordChannel;
use App\Discord\DmRecipients;
use App\Jobs\SendDiscordDm;
use App\Models\Personnel;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

const DD_TOKEN = 'DELIVERY.token-value-ZXCVBN';
const DD_ACCOUNT_ID = '111111111111111111';
const DD_RECORD_ID = '222222222222222222';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Http::preventStrayRequests();
    $s = app(Settings::class);
    $s->set('discord.bot.enabled', true);
    $s->set('discord.bot.token', DD_TOKEN, encrypted: true);
    $s->set('discord.dm_kinds', ['system']);
});

function ddFakeDiscord(int $status = 200): void
{
    Http::fake([
        'https://discord.com/api/v10/users/@me/channels' => $status === 200 ? Http::response(['id' => '333333333333333333']) : Http::response(['message' => 'boom'], $status),
        'https://discord.com/api/v10/channels/*/messages' => Http::response(['id' => '444444444444444444']),
    ]);
}

function ddNotify(User $user, string $kind = 'system'): void
{
    $user->notify(new InboxNotification($kind, 'Wartung heute', 'Ab 20 Uhr', '/settings/system'));
}

function ddRecipient(): ?string
{
    $opened = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/users/@me/channels'));

    return $opened->isEmpty() ? null : $opened->first()[0]['recipient_id'];
}

it('delivers by dm only when every part of the rule holds', function (Closure $arrange, ?string $expected) {
    ddFakeDiscord();
    $user = User::factory()->create(['discord_id' => DD_ACCOUNT_ID, 'discord_dm' => true]);
    $arrange($user);

    ddNotify($user->fresh());

    expect(ddRecipient())->toBe($expected)
        ->and($user->notifications()->count())->toBe(1);
})->with([
    'all set: account id' => [fn () => null, DD_ACCOUNT_ID],
    'bot switched off' => [fn () => app(Settings::class)->set('discord.bot.enabled', false), null],
    'no token' => [fn () => app(Settings::class)->forget('discord.bot.token'), null],
    'kind not chosen' => [fn () => app(Settings::class)->set('discord.dm_kinds', ['dokument']), null],
    'account inactive' => [fn (User $u) => $u->forceFill(['is_active' => false])->save(), null],
    'discord_dm off' => [fn (User $u) => $u->update(['discord_dm' => false]), null],
    'no id' => [fn (User $u) => $u->forceFill(['discord_id' => ''])->save(), null],
    'invalid id' => [fn (User $u) => $u->forceFill(['discord_id' => '1234'])->save(), null],
    'id only on the record' => [function (User $u) {
        $u->forceFill(['discord_id' => '', 'personnel_id' => Personnel::factory()->create(['discord_id' => DD_RECORD_ID])->id])->save();
    }, DD_RECORD_ID],
    'invalid on the account, valid on the record' => [function (User $u) {
        $u->forceFill(['discord_id' => 'abc', 'personnel_id' => Personnel::factory()->create(['discord_id' => DD_RECORD_ID])->id])->save();
    }, DD_RECORD_ID],
    'account id wins over the record' => [function (User $u) {
        $u->forceFill(['personnel_id' => Personnel::factory()->create(['discord_id' => DD_RECORD_ID])->id])->save();
    }, DD_ACCOUNT_ID],
]);

it('picks the discord channel in via() only when the rule holds', function () {
    $user = User::factory()->create(['discord_id' => DD_ACCOUNT_ID, 'discord_dm' => true]);
    $note = new InboxNotification('system', 'T', null, null);

    expect($note->via($user))->toBe(['database', DiscordChannel::class])
        ->and((new InboxNotification('dokument', 'T', null, null))->via($user))->toBe(['database']);
    expect(InboxNotification::class)->not->toImplement(ShouldQueue::class);
});

it('sends the notification embed with an absolute link and no mentions', function () {
    ddFakeDiscord();
    config(['app.url' => 'https://ignis.example']);
    app(Settings::class)->set('system.name', 'Wache 1');

    ddNotify(User::factory()->create(['discord_id' => DD_ACCOUNT_ID]));

    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages'))->sole()[0]->data();
    expect($sent['embeds'][0])->toMatchArray([
        'title' => 'Wartung heute',
        'description' => 'Ab 20 Uhr',
        'url' => 'https://ignis.example/settings/system',
        'color' => 0xD9480F,
        'footer' => ['text' => 'Wache 1 · '.__('notifications.kind.system')],
    ])->and($sent['allowed_mentions'])->toBe(['parse' => []]);
});

it('keeps the inbox row when discord fails', function (int $status) {
    ddFakeDiscord($status);
    $user = User::factory()->create(['discord_id' => DD_ACCOUNT_ID]);

    ddNotify($user);

    expect($user->notifications()->sole()->data['title'])->toBe('Wartung heute');
})->with([500, 401, 429]);

it('keeps the inbox row when queueing the dm fails', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    $user = User::factory()->create(['discord_id' => DD_ACCOUNT_ID]);

    ddNotify($user);

    expect($user->notifications()->count())->toBe(1);
});

it('queues only ids: no token, no discord id, no text in the job payload', function () {
    Queue::fake();
    $user = User::factory()->create(['discord_id' => DD_ACCOUNT_ID]);

    ddNotify($user);

    $row = $user->notifications()->sole();
    Queue::assertPushedOn('default', SendDiscordDm::class, function (SendDiscordDm $job) use ($user, $row) {
        $payload = serialize($job);

        return $job->userId === $user->id && $job->notificationId === $row->id
            && ! str_contains($payload, DD_TOKEN) && ! str_contains($payload, DD_ACCOUNT_ID) && ! str_contains($payload, 'Wartung');
    });
    Queue::assertPushed(SendDiscordDm::class, 1);
});

it('shows the account menu switch only to reachable accounts', function () {
    $reachable = User::factory()->create(['discord_id' => DD_ACCOUNT_ID, 'discord_dm' => false]);
    $viaRecord = User::factory()->create(['discord_id' => '', 'personnel_id' => Personnel::factory()->create(['discord_id' => DD_RECORD_ID])->id]);
    $noId = User::factory()->create(['discord_id' => 'not-a-snowflake']);

    $this->actingAs($reachable)->get('/')->assertInertia(fn (Assert $p) => $p->where('discordDm', false));
    $this->actingAs($viaRecord)->get('/')->assertInertia(fn (Assert $p) => $p->where('discordDm', true));
    $this->actingAs($noId)->get('/')->assertInertia(fn (Assert $p) => $p->where('discordDm', null));

    app(Settings::class)->set('discord.bot.enabled', false);
    $this->actingAs($reachable)->get('/')->assertInertia(fn (Assert $p) => $p->where('discordDm', null));
});

it('lets the account switch its dms off and on', function () {
    $user = User::factory()->create(['discord_id' => DD_ACCOUNT_ID]);

    $this->actingAs($user)->postJson('/profile/discord-dm', ['discord_dm' => false])->assertNoContent();
    expect($user->fresh()->discord_dm)->toBeFalse();
    $this->actingAs($user)->postJson('/profile/discord-dm', ['discord_dm' => true])->assertNoContent();
    expect($user->fresh()->discord_dm)->toBeTrue();
});

it('sends no dm for a kind the inbox hides from the account', function () {
    app(Settings::class)->set('discord.dm_kinds', ['system', 'vehicle_defect']);
    $user = User::factory()->create(['discord_id' => DD_ACCOUNT_ID, 'discord_dm' => true]);
    $recipients = app(DmRecipients::class);

    expect($recipients->for($user, 'vehicle_defect'))->toBeNull()
        ->and($recipients->for($user, 'system'))->toBe(DD_ACCOUNT_ID);
});

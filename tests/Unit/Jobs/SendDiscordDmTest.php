<?php

use App\Discord\DiscordException;
use App\Jobs\SendDiscordDm;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SJ_TOKEN = 'JOB.token-value-POIUZT';
const SJ_DISCORD_ID = '555555555555555555';

beforeEach(function () {
    Http::preventStrayRequests();
    $s = app(Settings::class);
    $s->set('discord.bot.enabled', true);
    $s->set('discord.bot.token', SJ_TOKEN, encrypted: true);
    $s->set('discord.dm_kinds', ['system']);
    $this->logs = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $m) => $this->logs[] = $m->message.json_encode($m->context));

    Queue::fake();
    $this->user = User::factory()->create(['discord_id' => SJ_DISCORD_ID]);
    $this->user->notify(new InboxNotification('system', 'Titel', 'Text', '/x'));
    $this->job = (new SendDiscordDm($this->user->id, $this->user->notifications()->sole()->id))->withFakeQueueInteractions();
});

function sjFake(int $status, array $body = [], array $headers = []): void
{
    Http::fake([
        'https://discord.com/api/v10/users/@me/channels' => Http::response(['id' => '666666666666666666']),
        'https://discord.com/api/v10/channels/*/messages' => Http::response($body, $status, $headers),
    ]);
}

function sjRun(SendDiscordDm $job): void
{
    app()->call([$job, 'handle']);
}

it('is a queued job on default with three tries and the legacy backoff', function () {
    expect($this->job)->toBeInstanceOf(ShouldQueue::class)
        ->and($this->job->queue)->toBe('default')
        ->and($this->job->tries)->toBe(3)
        ->and($this->job->backoff)->toBe([30, 60, 120]);
});

it('sends the dm on 200', function () {
    sjFake(200, ['id' => '1']);

    sjRun($this->job);

    $this->job->assertNotReleased()->assertNotFailed();
    expect(Http::recorded())->toHaveCount(2);
});

it('waits as long as discord asks on 429', function () {
    sjFake(429, ['message' => 'You are being rate limited.', 'retry_after' => 7.2, 'global' => false]);

    sjRun($this->job);

    $this->job->assertReleased(8);
});

it('falls back to the backoff on 429 without retry_after', function () {
    sjFake(429);

    sjRun($this->job);

    $this->job->assertReleased(30);
});

it('does not retry what cannot work and logs without secrets', function (int $status, array $body) {
    sjFake($status, $body);

    sjRun($this->job);

    $this->job->assertNotReleased()->assertNotFailed();
    $log = implode("\n", $this->logs);
    expect($log)->toContain('Discord DM not delivered')->toContain('"status":'.$status)
        ->not->toContain(SJ_TOKEN)->not->toContain(SJ_DISCORD_ID)->not->toContain('Titel');
})->with([
    '50007' => [403, ['message' => 'Cannot send messages to this user', 'code' => 50007]],
    '401' => [401, ['message' => '401: Unauthorized', 'code' => 0]],
    '403' => [403, ['message' => 'Missing Access', 'code' => 50001]],
]);

it('throws on 5xx and network trouble so the queue retries', function () {
    sjFake(502);

    expect(fn () => sjRun($this->job))->toThrow(DiscordException::class);
    $this->job->assertNotReleased();
});

it('sends nothing when the bot, the kind or the opt-in went away after queueing', function (Closure $change) {
    sjFake(200);
    $change($this->user);

    sjRun($this->job);

    Http::assertNothingSent();
})->with([
    'bot off' => [fn () => app(Settings::class)->set('discord.bot.enabled', false)],
    'token gone' => [fn () => app(Settings::class)->forget('discord.bot.token')],
    'kind dropped' => [fn () => app(Settings::class)->set('discord.dm_kinds', [])],
    'opted out' => [fn (User $u) => $u->update(['discord_dm' => false])],
    'deactivated' => [fn (User $u) => $u->forceFill(['is_active' => false])->save()],
    'inbox row deleted' => [fn (User $u) => $u->notifications()->delete()],
    'account deleted' => [fn (User $u) => $u->delete()],
]);

it('carries no secret in its payload', function () {
    expect(serialize($this->job))->not->toContain(SJ_TOKEN)->not->toContain(SJ_DISCORD_ID)->not->toContain('Titel');
});

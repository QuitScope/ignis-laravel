<?php

use App\Discord\DiscordBot;
use App\Discord\DiscordException;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const BOT_TOKEN = 'MTIz.bot-token-XYZ';
const BOT_USER = '123456789012345678';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->bot = app(DiscordBot::class);
});

function botSetUp(bool $enabled = true): void
{
    app(Settings::class)->set('discord.bot.enabled', $enabled);
    app(Settings::class)->set('discord.bot.token', BOT_TOKEN, encrypted: true);
}

function botError(callable $call): DiscordException
{
    try {
        $call();
    } catch (DiscordException $e) {
        return $e;
    }
    throw new RuntimeException('no DiscordException');
}

it('reads the bot behind a token from the fixed api host, with bot auth, user agent and no redirects', function () {
    Http::fake(['https://discord.com/api/v10/users/@me' => Http::response(['id' => '987654321098765432', 'username' => 'ignis', 'global_name' => 'Ignis Bot', 'avatar' => str_repeat('a', 32)])]);

    expect($this->bot->me(BOT_TOKEN))->toBe(['id' => '987654321098765432', 'name' => 'Ignis Bot', 'avatar' => str_repeat('a', 32)]);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->url() === 'https://discord.com/api/v10/users/@me'
        && $r->header('Authorization') === ['Bot '.BOT_TOKEN]
        && $r->header('User-Agent') === ['DiscordBot (https://github.com/EmergencyForge/ignis, 1)']);
});

it('does not follow a redirect', function () {
    Http::fake([
        'https://discord.com/*' => Http::response('', 302, ['Location' => 'https://evil.test/steal']),
        'https://evil.test/*' => Http::response(['id' => '1']),
    ]);

    $e = botError(fn () => $this->bot->me(BOT_TOKEN));
    expect($e->status)->toBe(302)->and($e->getMessage())->not->toContain(BOT_TOKEN);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.test'));
});

it('sends a dm through a dm channel, without mentions', function () {
    botSetUp();
    Http::fake([
        'https://discord.com/api/v10/users/@me/channels' => Http::response(['id' => '222222222222222222']),
        'https://discord.com/api/v10/channels/222222222222222222/messages' => Http::response(['id' => '3']),
    ]);
    $components = [['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => 'Los', 'url' => 'https://x.test']]]];

    $this->bot->sendDm(BOT_USER, ['title' => 'Hallo'], $components);

    Http::assertSentInOrder([
        fn (Request $r) => $r->method() === 'POST' && $r->data() === ['recipient_id' => BOT_USER],
        fn (Request $r) => $r->method() === 'POST' && $r->data() === ['embeds' => [['title' => 'Hallo']], 'components' => $components, 'allowed_mentions' => ['parse' => []]],
    ]);
});

it('refuses a dm without a valid snowflake or without a token, before calling discord', function () {
    Http::fake();
    expect(botError(fn () => $this->bot->sendDm(BOT_USER, ['title' => 'x']))->getMessage())->toBe('Der Discord-Bot ist nicht eingerichtet.');
    botSetUp();
    expect(botError(fn () => $this->bot->sendDm('12345', ['title' => 'x']))->getMessage())->toBe('Keine gültige Discord-ID.');
    Http::assertNothingSent();
});

it('turns discord errors into german messages with status, code and retry hint', function (int $status, array $body, string $message, int $code, bool $retryable, ?int $retryAfter) {
    Http::fake(['*' => Http::response($body, $status)]);

    $e = botError(fn () => $this->bot->me(BOT_TOKEN));
    expect($e->getMessage())->toBe($message)
        ->and($e->status)->toBe($status)
        ->and($e->getCode())->toBe($code)
        ->and($e->retryable())->toBe($retryable)
        ->and($e->retryAfter)->toBe($retryAfter);
})->with([
    '401' => [401, ['message' => '401: Unauthorized', 'code' => 0], 'Discord lehnt das Token ab. Bitte im Developer Portal ein neues Token erzeugen und hier eintragen.', 0, false, null],
    '50007' => [403, ['message' => 'Cannot send messages to this user', 'code' => 50007], 'Discord stellt keine Direktnachricht zu. Die Person muss einen Server mit dem Bot teilen und Direktnachrichten von Servermitgliedern erlauben.', 50007, false, null],
    '429' => [429, ['message' => 'You are being rate limited.', 'retry_after' => 2.4, 'global' => false], 'Discord bremst gerade (Rate-Limit). Bitte gleich noch einmal versuchen.', 0, true, 3],
    '502' => [502, [], 'Discord hat gerade eine Störung (502).', 0, true, null],
    'other' => [400, ['message' => 'Invalid Form Body', 'code' => 50035, 'errors' => ['embeds' => ['0' => ['_errors' => [['message' => 'Too long']]]]]], 'Discord meldet: Too long (400)', 50035, false, null],
]);

it('treats a network failure as retryable', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $e = botError(fn () => $this->bot->me(BOT_TOKEN));
    expect($e->getMessage())->toBe('Discord ist nicht erreichbar.')->and($e->status)->toBe(0)->and($e->retryable())->toBeTrue();
});

it('is active only when switched on and the token can be decrypted', function () {
    expect($this->bot->active())->toBeFalse()->and($this->bot->tokenLost())->toBeFalse();
    botSetUp(enabled: false);
    expect($this->bot->active())->toBeFalse();
    botSetUp();
    expect($this->bot->active())->toBeTrue();

    Setting::where('key', 'discord.bot.token')->update(['value' => 'not-a-ciphertext']);
    app(Settings::class)->refresh();
    expect($this->bot->active())->toBeFalse()->and($this->bot->tokenLost())->toBeTrue()->and($this->bot->token())->toBeNull();
});

it('builds the invite link and avatar from the cached bot id', function () {
    expect($this->bot->inviteUrl())->toBeNull()->and($this->bot->avatarUrl())->toBeNull();
    app(Settings::class)->set('discord.bot.id', '987654321098765432');
    app(Settings::class)->set('discord.bot.avatar', str_repeat('b', 32));

    expect($this->bot->inviteUrl())->toBe('https://discord.com/oauth2/authorize?client_id=987654321098765432&scope=bot&permissions=0')
        ->and($this->bot->avatarUrl())->toBe('https://cdn.discordapp.com/avatars/987654321098765432/'.str_repeat('b', 32).'.png?size=128');
});

it('builds a notification embed with absolute links from app.url only', function () {
    config(['app.url' => 'https://ignis.example']);
    app(Settings::class)->set('system.name', 'Leitstelle');

    $embed = $this->bot->embed(str_repeat('t', 300), 'Text', '/inbox', 'System');
    expect(mb_strlen($embed['title']))->toBe(256)
        ->and($embed['color'])->toBe(0xD9480F)
        ->and($embed['description'])->toBe('Text')
        ->and($embed['url'])->toBe('https://ignis.example/inbox')
        ->and($embed['footer'])->toBe(['text' => 'Leitstelle · System']);
    expect($this->bot->embed('t', null, 'https://evil.test/x', 'System'))->not->toHaveKey('url')->not->toHaveKey('description');
});

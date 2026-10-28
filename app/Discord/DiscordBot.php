<?php

namespace App\Discord;

use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;

/**
 * The installation's own Discord bot: a bot token from the Developer Portal, REST only (no gateway, no
 * guild). It sends direct messages. Fixed host, token only in the Authorization header, no redirects, short timeouts.
 * The settings live in the registry group `discord` (Settings::set(..., encrypted: true) for the token); id, name and
 * avatar mirror what Discord reported for the token. Name and avatar are never changed from here.
 */
final class DiscordBot
{
    public const API = 'https://discord.com/api/v10';

    public const DEFAULT_DM_KINDS = ['mail', 'system'];

    public function __construct(private Settings $settings) {}

    public static function snowflake(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{17,20}$/D', $value) === 1;
    }

    public function token(): ?string
    {
        $token = $this->settings->get('discord.bot.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function tokenLost(): bool
    {
        return $this->settings->has('discord.bot.token') && $this->token() === null;
    }

    public function active(): bool
    {
        return $this->settings->get('discord.bot.enabled') === true && $this->token() !== null;
    }

    public function inviteUrl(): ?string
    {
        $id = $this->settings->get('discord.bot.id');

        return self::snowflake($id) ? 'https://discord.com/oauth2/authorize?client_id='.$id.'&scope=bot&permissions=0' : null;
    }

    public function avatarUrl(): ?string
    {
        $id = $this->settings->get('discord.bot.id');
        if (! self::snowflake($id)) {
            return null;
        }
        $avatar = $this->settings->get('discord.bot.avatar');

        return is_string($avatar) && preg_match('/^(a_)?[0-9a-f]{32}$/D', $avatar) === 1
            ? "https://cdn.discordapp.com/avatars/{$id}/{$avatar}.png?size=128"
            : 'https://cdn.discordapp.com/embed/avatars/'.(((int) $id >> 22) % 6).'.png';
    }

    /**
     * The bot behind a token (checks the token).
     *
     * @return array{id: string, name: string, avatar: string}
     */
    public function me(#[\SensitiveParameter] string $token): array
    {
        $data = $this->call($token, 'GET', '/users/@me');

        return [
            'id' => (string) ($data['id'] ?? ''),
            'name' => (string) ($data['global_name'] ?? $data['username'] ?? ''),
            'avatar' => (string) ($data['avatar'] ?? ''),
        ];
    }

    /**
     * A direct message with the stored token. Discord only delivers it when the person shares a server with the bot and
     * accepts DMs from server members (else 50007).
     *
     * @param  array<string, mixed>  $embed
     * @param  list<array<string, mixed>>|null  $components
     */
    public function sendDm(string $snowflake, array $embed, ?array $components = null): void
    {
        $token = $this->token() ?? throw new DiscordException(__('discord.error.not_set_up'));
        if (! self::snowflake($snowflake)) {
            throw new DiscordException(__('discord.error.snowflake'));
        }

        $channel = (string) ($this->call($token, 'POST', '/users/@me/channels', ['recipient_id' => $snowflake])['id'] ?? '');
        if (! self::snowflake($channel)) {
            throw new DiscordException(__('discord.error.channel'));
        }
        $message = ['embeds' => [$embed]] + ($components === null ? [] : ['components' => $components]);
        $this->call($token, 'POST', "/channels/{$channel}/messages", $message + ['allowed_mentions' => ['parse' => []]]);
    }

    /**
     * An embed in the style of a notification (legacy DiscordNotifier::embed): title linked back into the installation,
     * text, system name and kind below. Links are relative paths made absolute with app.url only.
     *
     * @return array<string, mixed>
     */
    public function embed(string $title, ?string $message, ?string $link, string $label): array
    {
        $embed = ['title' => mb_substr($title, 0, 256), 'color' => 0xD9480F, 'timestamp' => now('UTC')->toIso8601String()];
        if ($message !== null && trim($message) !== '') {
            $embed['description'] = mb_substr($message, 0, 4096);
        }
        $base = rtrim((string) config('app.url'), '/');
        if ($link !== null && str_starts_with($link, '/') && ! str_starts_with($link, '//') && preg_match('#^https?://[^/]+$#i', $base) === 1) {
            $embed['url'] = $base.$link;
        }
        $footer = array_filter([(string) ($this->settings->get('system.name') ?: config('app.name')), $label], fn (string $p) => $p !== '');
        if ($footer !== []) {
            $embed['footer'] = ['text' => mb_substr(implode(' · ', $footer), 0, 2048)];
        }

        return $embed;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function call(#[\SensitiveParameter] string $token, string $method, string $path, ?array $body = null): array
    {
        try {
            $response = DiscordHttp::pending()->baseUrl(self::API)->withToken($token, 'Bot')
                ->send($method, ltrim($path, '/'), $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            throw new DiscordException(__('discord.error.unreachable'));
        }

        $data = DiscordHttp::json($response);
        if ($response->successful()) {
            return $data;
        }

        throw DiscordHttp::failure($response, $data);
    }
}

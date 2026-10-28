<?php

namespace App\Discord;

use App\Jobs\SendDiscordWebhook;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;

/**
 * Discord webhooks: a message into a channel, no bot needed. The URL is read from the encrypted
 * setting when a message goes out, parsed against the host allowlist (WebhookUrl) every time, and never leaves this
 * class: not into a job payload, a log or an exception message. No redirects, short timeouts, no pings.
 *
 * Hook for modules (FireTab, eNOTF): `DiscordWebhook::dispatch(WebhookKind::FireProtocol, Embeds::fireProtocol($data))`.
 */
final class DiscordWebhook
{
    public function __construct(private Settings $settings) {}

    /**
     * Queues one message; the job reads the URL when it runs.
     *
     * @param  array<string, mixed>  $embed
     */
    public static function dispatch(WebhookKind $kind, array $embed): void
    {
        SendDiscordWebhook::dispatch($kind, $embed);
    }

    public function isSet(WebhookKind $kind): bool
    {
        return $this->settings->has($kind->settingKey());
    }

    /**
     * Sends now. False when no URL is stored for the kind, nothing to do.
     *
     * @param  array<string, mixed>  $embed  without timestamp, added here
     *
     * @throws DiscordException
     */
    public function send(WebhookKind $kind, array $embed): bool
    {
        $stored = $this->settings->get($kind->settingKey());
        if (! is_string($stored) || trim($stored) === '') {
            return false;
        }
        $url = WebhookUrl::parse(trim($stored)) ?? throw new DiscordException(__('discord.error.webhook_invalid'), 400);

        $this->post("https://{$url['host']}/api/webhooks/{$url['id']}/{$url['token']}", $embed);

        return true;
    }

    /** @param array<string, mixed> $embed */
    private function post(#[\SensitiveParameter] string $url, array $embed): void
    {
        $body = ['embeds' => [$embed + ['timestamp' => now('UTC')->toIso8601String()]], 'allowed_mentions' => ['parse' => []]];
        try {
            $response = DiscordHttp::pending()->asJson()->post($url, $body);
        } catch (ConnectionException) {
            throw new DiscordException(__('discord.error.unreachable'));
        }

        if ($response->successful()) {
            return;
        }
        throw in_array($response->status(), [401, 404], true)
            ? new DiscordException(__('discord.error.webhook_gone'), $response->status())
            : DiscordHttp::failure($response, DiscordHttp::json($response));
    }
}

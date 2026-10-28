<?php

namespace App\Discord;

/**
 * The only shape of webhook URL we ever call (legacy allowed any https host and followed redirects: SSRF).
 * One anchored pattern on the whole string, no URL parser involved, so there is nothing to confuse: https, one of
 * Discord's hosts in lower case, no userinfo, port, query or fragment, and exactly /api/webhooks/<id>/<token>.
 */
final class WebhookUrl
{
    private const PATTERN = '#^https://(discord\.com|discordapp\.com|canary\.discord\.com|ptb\.discord\.com)/api/webhooks/(\d{17,20})/([A-Za-z0-9._-]{1,100})$#D';

    /**
     * @return array{host: string, id: string, token: string}|null null when the string is not an allowed webhook URL
     */
    public static function parse(#[\SensitiveParameter] string $url): ?array
    {
        if (preg_match(self::PATTERN, $url, $m) !== 1 || $m[3] === '.' || $m[3] === '..') {
            return null;
        }

        return ['host' => $m[1], 'id' => $m[2], 'token' => $m[3]];
    }
}

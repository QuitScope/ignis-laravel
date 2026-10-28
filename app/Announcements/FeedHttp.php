<?php

namespace App\Announcements;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that talks to the outside for the announcement feeds (opt-in, scheduler only). No
 * installation id and no identifying header, the user agent is generic. Hardened as the response is foreign data:
 * https hosts only, no redirects (a 3xx is an error, so a redirect can never lead to another host), a size cap and
 * short timeouts. Compression is refused (identity, no decoding): the cap counts the bytes that are really held.
 */
final class FeedHttp
{
    public const MAX_BYTES = 256 * 1024;

    public static function baseUrl(string $configKey): ?string
    {
        $url = config($configKey);
        $parts = is_string($url) ? parse_url(trim($url)) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            Log::warning("Announcements: {$configKey} is not a plain https:// url, feed disabled");

            return null;
        }

        return rtrim(trim($url), '/');
    }

    public static function guard(int|float $total, int|float $received): void
    {
        if ($received > self::MAX_BYTES) {
            throw new FeedTooLarge('response too large');
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return Response|string the response, or a short error code (`unreachable`, `too_large`, `error`)
     */
    public static function get(string $url, int $timeout, array $headers = []): Response|string
    {
        try {
            $response = Http::withUserAgent('IgnisLaravel')->acceptJson()->withHeaders($headers + ['Accept-Encoding' => 'identity'])
                ->timeout($timeout)->connectTimeout($timeout)
                ->withOptions(['allow_redirects' => false, 'decode_content' => false, 'progress' => self::guard(...)])->get($url);
        } catch (Throwable $e) {
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof FeedTooLarge) {
                    return 'too_large';
                }
            }
            if ($e instanceof ConnectionException) {
                return 'unreachable';
            }
            Log::warning('Announcements: request failed: '.$e::class);

            return 'error';
        }

        return strlen($response->body()) > self::MAX_BYTES ? 'too_large' : $response;
    }

    /**
     * Plain text: entities decoded first, then tags removed (so escaped markup cannot come back as live markup),
     * control characters gone, whitespace collapsed (line breaks kept when `$multiline`).
     */
    public static function text(string $raw, bool $multiline = false): string
    {
        $text = strip_tags(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = $multiline
            ? preg_replace(['/[ \t]+/', '/\s*\n\s*/'], [' ', "\n"], str_replace("\r", '', $text))
            : preg_replace('/\s+/', ' ', $text);

        return trim($text ?? '');
    }
}

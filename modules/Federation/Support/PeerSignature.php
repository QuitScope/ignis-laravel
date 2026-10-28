<?php

namespace Modules\Federation\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Federation\Models\FederationLink;

/**
 * Protocol v2: HMAC-SHA256 over method, path (relative to the peer's base url), sorted query,
 * timestamp, nonce and body hash. The sender's secret_outgoing is the receiver's secret_incoming.
 */
final class PeerSignature
{
    public const WINDOW = 300;

    /**
     * @return array<string, string>
     */
    public static function headers(FederationLink $link, string $method, string $pathWithQuery, string $body = ''): array
    {
        $path = (string) parse_url($pathWithQuery, PHP_URL_PATH);
        parse_str((string) parse_url($pathWithQuery, PHP_URL_QUERY), $query);
        $ts = (string) now()->getTimestamp();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-Ignis-Federation' => '2',
            'X-Ignis-Instance' => FederationInstance::instanceId(),
            'X-Ignis-Timestamp' => $ts,
            'X-Ignis-Nonce' => $nonce,
            'X-Ignis-Signature' => self::sign($link->secret_outgoing, $method, $path, $query, $ts, $nonce, $body),
        ];
    }

    public static function verify(Request $r, FederationLink $link): ?string
    {
        $ts = (string) $r->header('X-Ignis-Timestamp');
        $nonce = (string) $r->header('X-Ignis-Nonce');
        $given = (string) $r->header('X-Ignis-Signature');
        if (! self::fresh($ts)) {
            return 'clock_skew';
        }
        if (preg_match('/\A[0-9a-f]{32}\z/', $nonce) !== 1) {
            return 'bad_signature';
        }
        $expected = self::sign($link->secret_incoming, $r->method(), '/'.ltrim($r->path(), '/'), $r->query(), $ts, $nonce, $r->getContent());
        if (! hash_equals($expected, $given)) {
            return 'bad_signature';
        }
        // After the signature check, so forged requests never fill the nonce store.
        if (! Cache::add("federation:nonce:{$link->id}:{$nonce}", 1, self::WINDOW * 2 + 60)) {
            return 'replay';
        }

        return null;
    }

    /** The timestamp lies within the window; checked before any link lookup (no instance-id oracle). */
    public static function fresh(string $ts): bool
    {
        return ctype_digit($ts) && abs(now()->getTimestamp() - (int) $ts) <= self::WINDOW;
    }

    /** @param  array<mixed>  $query */
    private static function sign(string $secret, string $method, string $path, array $query, string $ts, string $nonce, string $body): string
    {
        ksort($query);
        $canonical = implode("\n", ['v2', strtoupper($method), $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $ts, $nonce, hash('sha256', $body)]);

        return hash_hmac('sha256', $canonical, $secret);
    }
}

<?php

namespace Modules\Federation\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Federation\Models\FederationLink;
use Throwable;

/**
 * The only way out to a peer (SSRF): https only, no userinfo/query/fragment, port 443 unless the host
 * is allow-listed, every resolved address public (unless FEDERATION_ALLOW_PRIVATE), the connection pinned to the
 * checked address (no DNS rebinding), no redirects, TLS verified, 2 MB cap. Checked on pairing and on every call.
 * Failures are short codes; response content never leaves this class.
 */
final class PeerHttp
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var (Closure(string): list<string>)|null */
    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function check(string $baseUrl): ?string
    {
        $target = self::target($baseUrl);

        return is_string($target) ? $target : null;
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<mixed>|null  $json
     */
    public static function send(FederationLink|string $base, string $method, string $path, array $query = [], ?array $json = null, ?FederationLink $sign = null): Response|string
    {
        $baseUrl = $base instanceof FederationLink ? $base->url : $base;
        $target = self::target($baseUrl);
        if (is_string($target)) {
            return $target;
        }
        [$host, $port, $ip] = $target;
        // Without ext-curl Guzzle falls back to its stream handler, which ignores CURLOPT_RESOLVE and re-resolves DNS
        // unchecked (rebinding). Refuse instead of sending unpinned.
        if (! extension_loaded('curl')) {
            return 'unreachable';
        }

        ksort($query);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $pathWithQuery = '/'.ltrim($path, '/').($qs !== '' ? "?$qs" : '');
        $body = $json === null ? '' : (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Accept-Encoding' => 'identity'] + ($sign !== null ? PeerSignature::headers($sign, $method, $pathWithQuery, $body) : []);
        $pin = str_contains($ip, ':') ? "[$ip]" : $ip;

        try {
            $request = Http::withUserAgent('IgnisFederation/2')->acceptJson()->withHeaders($headers)
                ->connectTimeout(5)->timeout(20)
                ->withOptions([
                    'allow_redirects' => false, 'verify' => true, 'decode_content' => false,
                    'progress' => self::guard(...),
                    'curl' => [CURLOPT_RESOLVE => ["$host:$port:$pin"]],
                ]);
            if ($body !== '') {
                $request = $request->withBody($body, 'application/json');
            }
            $response = $request->send(strtoupper($method), rtrim($baseUrl, '/').$pathWithQuery);
        } catch (Throwable $e) {
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof PeerTooLarge) {
                    return 'too_large';
                }
            }
            if ($e instanceof ConnectionException) {
                return preg_match('/SSL|certificate|TLS/i', $e->getMessage()) === 1 ? 'tls' : 'unreachable';
            }
            Log::warning('Federation: request failed: '.$e::class);

            return 'unreachable';
        }

        return self::outcome($response);
    }

    public static function guard(int|float $total, int|float $received): void
    {
        if ($received > self::MAX_BYTES) {
            throw new PeerTooLarge('response too large');
        }
    }

    private static function outcome(Response $response): Response|string
    {
        $status = $response->status();
        if (strlen($response->body()) > self::MAX_BYTES) {
            return 'too_large';
        }
        if ($status >= 300 && $status < 400) {
            return 'redirect';
        }
        if ($status === 401) {
            // A legacy (v1) peer answers 401 without our error codes.
            return match ($response->json('error')) {
                'clock_skew' => 'clock_skew',
                'unauthorized', 'protocol_v2_required' => 'unauthorized',
                default => 'peer_v1',
            };
        }
        if (! $response->successful()) {
            return 'http_'.$status;
        }

        return is_array($response->json()) ? $response : 'bad_json';
    }

    /** @return array{0: string, 1: int, 2: string}|string host, port, pinned address; or an error code */
    private static function target(string $baseUrl): array|string
    {
        $p = parse_url(trim($baseUrl));
        $host = is_array($p) ? strtolower(trim((string) ($p['host'] ?? ''), '[]')) : '';
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || $host === '' || isset($p['user']) || isset($p['pass'])
            || isset($p['query']) || isset($p['fragment']) || str_contains((string) ($p['path'] ?? ''), '..')) {
            return 'invalid_url';
        }
        /** @var list<string> $allowed */
        $allowed = config('ignis.federation.allowed_hosts', []);
        $port = (int) ($p['port'] ?? 443);
        if (($allowed !== [] && ! in_array($host, $allowed, true)) || ($port !== 443 && ! in_array($host, $allowed, true))) {
            return 'blocked_address';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (self::$resolver ?? self::dns(...))($host);
        if ($ips === []) {
            return 'unreachable';
        }
        if (! config('ignis.federation.allow_private', false)) {
            foreach ($ips as $ip) {
                if (! self::isPublic($ip)) {
                    return 'blocked_address';
                }
            }
        }

        return [$host, $port, $ips[0]];
    }

    /** @return list<string> */
    private static function dns(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isPublic(string $ip): bool
    {
        // ::ffff:a.b.c.d is the IPv4 address in disguise.
        if (preg_match('/\A::ffff:(\d+\.\d+\.\d+\.\d+)\z/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (['100.64.0.0/10', '0.0.0.0/8', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96', '64:ff9b::/96'] as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = inet_pton($ip);
        $b = inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }

        return $rest === 0 || (ord($a[$bytes]) >> (8 - $rest)) === (ord($b[$bytes]) >> (8 - $rest));
    }
}

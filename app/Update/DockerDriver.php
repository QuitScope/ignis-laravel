<?php

namespace App\Update;

use App\System\Redact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use SensitiveParameter;

/**
 * App side of Weg B: asks the updater container (UPDATER_URL, internal network only) to start an update or a rollback.
 * Every request is signed: HMAC-SHA256 with UPDATER_SECRET over "<unix time>\n<nonce>\n<method>\n<path>\n<body>" in
 * X-Ignis-Signature, the time in X-Ignis-Timestamp, a random nonce in X-Ignis-Nonce (the updater accepts 60 s either
 * way and each signature once; the nonce keeps two equal requests within one second apart).
 * Bodies carry nothing but {version, skip_trial} or {action}; the updater fetches and verifies the release itself and
 * never takes an image name or a command from here. No error message carries the url, the secret or foreign text.
 */
class DockerDriver implements UpdateStarter
{
    public const TOLERANCE = 60;

    public const MIN_SECRET = 32;

    public function configured(): bool
    {
        return self::validUrl($this->url()) && strlen($this->secret()) >= self::MIN_SECRET;
    }

    public function start(string $version, bool $skipTrial = false): void
    {
        if (! InstalledVersion::isValid($version)) {
            throw new UpdateException('bad_version');
        }
        $this->post('/start', ['version' => $version, 'skip_trial' => $skipTrial], 40);
    }

    public function rollback(): void
    {
        $this->post('/rollback', ['action' => 'rollback'], 15);
    }

    /**
     * @return array{running: bool}
     *
     * @throws UpdateException
     */
    public function ping(): array
    {
        $data = $this->post('/ping', ['action' => 'ping'], 5);

        return ['running' => ($data['running'] ?? true) !== false];
    }

    public function ownerAlive(): bool
    {
        if (! $this->configured()) {
            return false;
        }
        try {
            return $this->ping()['running'];
        } catch (UpdateException) {
            return true;
        }
    }

    public static function signature(#[SensitiveParameter] string $secret, string $timestamp, string $nonce, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', $timestamp."\n".$nonce."\n".$method."\n".$path."\n".$body, $secret);
    }

    /** Checked on the updater side: timestamp within TOLERANCE of $now, signature compared in constant time. */
    public static function verify(#[SensitiveParameter] string $secret, ?string $timestamp, ?string $nonce, ?string $signature, string $method, string $path, string $body, ?int $now = null): bool
    {
        if (strlen($secret) < self::MIN_SECRET || $timestamp === null || $nonce === null || $signature === null
            || preg_match('/^\d{1,12}$/D', $timestamp) !== 1 || preg_match('/^[0-9a-f]{32}$/D', $nonce) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1
            || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(self::signature($secret, $timestamp, $nonce, $method, $path, $body), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, int $timeout): array
    {
        if (! $this->configured()) {
            throw new UpdateException('driver_docker');
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        try {
            $response = Http::withoutRedirecting()->timeout($timeout)->connectTimeout(3)
                ->withHeaders(['X-Ignis-Timestamp' => $ts, 'X-Ignis-Nonce' => $nonce, 'X-Ignis-Signature' => self::signature($this->secret(), $ts, $nonce, 'POST', $path, $body)])
                ->withBody($body, 'application/json')
                ->post(rtrim($this->url(), '/').$path);
        } catch (ConnectionException) {
            throw new UpdateException('updater_unreachable');
        }

        return $this->answer($response);
    }

    /** @return array<string, mixed> */
    private function answer(Response $response): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];
        if ($response->successful()) {
            return $data;
        }
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
        if ($response->status() === 401) {
            throw new UpdateException('updater_auth');
        }
        if ($response->status() === 409) {
            throw new UpdateException('busy');
        }
        if ($response->status() === 422 && preg_match('/^[a-z_]{1,40}$/D', $reason) === 1 && Lang::has("update.error.$reason")) {
            $message = is_string($data['message'] ?? null) && $data['message'] !== '' ? Redact::text(strip_tags($data['message']), 500) : null;

            throw new UpdateException($reason, message: $message);
        }

        throw new UpdateException('updater_failed');
    }

    private function url(): string
    {
        $url = config('ignis.update.updater_url');

        return is_string($url) ? $url : '';
    }

    private function secret(): string
    {
        $secret = config('ignis.update.updater_secret');

        return is_string($secret) ? $secret : '';
    }

    private static function validUrl(string $url): bool
    {
        $p = parse_url($url);

        return is_array($p) && in_array($p['scheme'] ?? '', ['http', 'https'], true) && ($p['host'] ?? '') !== ''
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['query']);
    }
}

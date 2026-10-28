<?php

namespace App\Update;

use App\System\Redact;
use Closure;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * The HTTP surface of the updater container (updater/updater.php under `php -S`, internal network only). Three signed
 * POST requests and nothing else:
 *   /ping      {"action":"ping"}                         -> 200 {ok, running}
 *   /start     {"version":"x.y.z","skip_trial":bool}     -> 202, after the release was verified HERE (not trusting the app)
 *   /rollback  {"action":"rollback"}                     -> 202
 * Refused: other methods/paths, bodies over 1 KB, a bad or reused signature, a timestamp off by more than 60 s, any
 * other or missing field, a non-SemVer or pre-release version. The job itself runs detached (`updater.php run|rollback`);
 * its outcome is in the shared state file.
 */
final class DockerUpdateEndpoint
{
    public const MAX_BODY = 1024;

    private const FIELDS = ['/ping' => ['action'], '/start' => ['skip_trial', 'version'], '/rollback' => ['action']];

    /** @var Closure(list<string>): void */
    private Closure $spawn;

    /** @param  (Closure(list<string>): void)|null  $spawn  starts `updater.php <args>` detached (tests record it) */
    public function __construct(
        #[SensitiveParameter] private string $secret,
        private DockerUpdateService $updater,
        private UpdateLock $lock,
        private string $seenFile,
        ?Closure $spawn = null,
    ) {
        $this->spawn = $spawn ?? fn (array $args) => (new ProcessUpdateStarter)->spawn([PHP_BINARY, base_path('updater/updater.php'), ...$args], base_path());
    }

    /** @return array{0: int, 1: array<string, mixed>} status and JSON body */
    public function handle(string $method, string $path, ?string $timestamp, ?string $nonce, ?string $signature, string $body, ?int $now = null): array
    {
        if ($method !== 'POST') {
            return [405, ['reason' => 'method']];
        }
        if (! isset(self::FIELDS[$path])) {
            return [404, ['reason' => 'not_found']];
        }
        if (strlen($body) > self::MAX_BODY) {
            return [413, ['reason' => 'too_large']];
        }
        if (! DockerDriver::verify($this->secret, $timestamp, $nonce, $signature, $method, $path, $body, $now)
            || ! $this->firstUse((string) $signature, $now ?? time())) {
            return [401, ['reason' => 'auth']];
        }
        $data = json_decode($body, true, 3);
        if (! is_array($data) || array_is_list($data)) {
            return [422, ['reason' => 'bad_request']];
        }
        $keys = array_map('strval', array_keys($data));
        sort($keys);
        if ($keys !== self::FIELDS[$path]) {
            return [422, ['reason' => 'bad_request']];
        }

        return match ($path) {
            '/ping' => ($data['action'] ?? null) === 'ping' ? [200, ['ok' => true, 'running' => $this->lock->ownerAlive()]] : [422, ['reason' => 'bad_request']],
            '/rollback' => ($data['action'] ?? null) === 'rollback' ? $this->launch(['rollback']) : [422, ['reason' => 'bad_request']],
            default => $this->start($data['version'] ?? null, $data['skip_trial'] ?? null),
        };
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function start(mixed $version, mixed $skipTrial): array
    {
        if (! is_string($version) || strlen($version) > 64 || ! InstalledVersion::isStable($version) || ! is_bool($skipTrial)) {
            return [422, ['reason' => 'bad_version']];
        }
        if ($this->lock->ownerAlive()) {
            return [409, ['reason' => 'busy']];
        }
        try {
            // the app's word counts for nothing: the release is fetched and verified here against the running version
            $installed = $this->updater->installedVersion() ?? throw new UpdateException('app_unreachable');
            $this->updater->verifiedManifest($version, $installed);
        } catch (UpdateException $e) {
            return [422, ['reason' => $e->reason, 'message' => Redact::text($e->getMessage(), 500)]];
        }

        return $this->launch(['run', $version, $skipTrial ? '1' : '0']);
    }

    /**
     * @param  list<string>  $args
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function launch(array $args): array
    {
        if ($this->lock->ownerAlive()) {
            return [409, ['reason' => 'busy']];
        }
        try {
            ($this->spawn)($args);
        } catch (Throwable) {
            return [500, ['reason' => 'start_failed']];
        }

        return [202, ['ok' => true]];
    }

    /** Each signature is accepted once (replay window = twice the tolerance); recorded only after it verified. */
    private function firstUse(string $signature, int $now): bool
    {
        $h = @fopen($this->seenFile, 'c+');
        if ($h === false || ! flock($h, LOCK_EX)) {
            throw new RuntimeException('replay store');
        }
        try {
            $seen = json_decode((string) stream_get_contents($h), true);
            $seen = array_filter(is_array($seen) ? $seen : [], fn ($until) => is_int($until) && $until >= $now);
            if (isset($seen[$signature])) {
                return false;
            }
            $seen[$signature] = $now + 2 * DockerDriver::TOLERANCE;
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($seen));

            return true;
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}

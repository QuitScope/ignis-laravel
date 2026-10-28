<?php

namespace App\Update;

use App\System\SystemOverview;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Is the installation healthy after a switch (or a rollback)? Several attempts with a pause. The probe runs as a child
 * process in the checked code (`php artisan ignis:update:health`): it proves the version boots, and the updater
 * process never loads classes of the new code (a fatal there cannot take the orchestrator down). Checked: VERSION,
 * the /healthz checks (database, queue tables, storage, migrations table), /healthz through the HTTP kernel answers
 * 200 (key `http`; absent in the probe of an older version, then not checked), no pending migrations; the scheduler
 * heartbeat (in-process, a cache read) only when it was alive before the update (snapshot()).
 */
class HealthGate
{
    /** @var Closure(string): array{version?: mixed, down?: mixed, queue?: mixed, pending?: mixed, http?: mixed} */
    private Closure $probe;

    /** @param  (Closure(string): array{version?: mixed, down?: mixed, queue?: mixed, pending?: mixed, http?: mixed})|null  $probe  report of the code in the given app dir (tests inject it) */
    public function __construct(?Closure $probe = null, private int $attempts = 5, private int $pauseMs = 2000)
    {
        $this->probe = $probe ?? self::childProbe(...);
    }

    /**
     * What ran before the update. Worker: there is no worker heartbeat in this app, so it never counts as seen.
     *
     * @return array{scheduler: bool, worker: bool}
     */
    public function snapshot(): array
    {
        return ['scheduler' => $this->schedulerAlive(), 'worker' => false];
    }

    public function check(string $expectedVersion, bool $expectScheduler, bool $expectWorker, ?string $app = null): void
    {
        $app ??= Installation::appPath();
        $problem = null;
        for ($i = 1; $i <= $this->attempts; $i++) {
            $problem = $this->problem($app, $expectedVersion, $expectScheduler, $expectWorker);
            if ($problem === null) {
                return;
            }
            if ($i < $this->attempts) {
                usleep($this->pauseMs * 1000);
            }
        }

        throw new UpdateException('health_failed', ['detail' => (string) $problem]);
    }

    private function problem(string $app, string $expected, bool $scheduler, bool $worker): ?string
    {
        try {
            $r = ($this->probe)($app);
            $version = is_string($r['version'] ?? null) ? $r['version'] : '?';
            $down = is_array($r['down'] ?? null) ? array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : '?', $r['down'])) : ['report'];
            $pending = is_int($r['pending'] ?? null) ? $r['pending'] : -1;
            $http = array_key_exists('http', $r) ? (is_int($r['http']) ? $r['http'] : 0) : null;
        } catch (Throwable $e) {
            return $e instanceof UpdateException ? $e->getMessage() : 'probe failed ('.$e::class.')';
        }
        if ($down !== []) {
            return 'down: '.implode(', ', $down);
        }
        if ($version !== $expected) {
            return "version $version instead of $expected";
        }
        if ($http !== null && $http !== 200) {
            return "/healthz answered HTTP $http";
        }
        if ($worker && ($r['queue'] ?? null) !== 'ok') {
            return 'queue unavailable';
        }
        if ($pending !== 0) {
            return $pending < 0 ? 'migrations table unreadable' : "$pending migrations pending";
        }
        if ($scheduler && ! $this->schedulerAlive()) {
            return 'scheduler heartbeat missing';
        }

        return null;
    }

    /** @return array{version?: mixed, down?: mixed, queue?: mixed, pending?: mixed, http?: mixed} */
    private static function childProbe(string $app): array
    {
        $php = (new PhpExecutableFinder)->find(false) ?: throw new UpdateException('php_missing');
        try {
            $result = Process::path($app)->timeout(120)->run([$php, 'artisan', 'ignis:update:health', '--no-interaction', '--no-ansi']);
        } catch (ProcessTimedOutException) {
            return ['down' => ['probe timed out']];
        }
        $lines = preg_split('/\R/', trim($result->output())) ?: [];
        $data = json_decode((string) end($lines), true);
        if ($result->failed() || ! is_array($data)) {
            return ['down' => ['the application does not boot (exit '.$result->exitCode().')']];
        }

        return $data;
    }

    private function schedulerAlive(): bool
    {
        return app(SystemOverview::class)->scheduler()['status'] === 'ok';
    }
}

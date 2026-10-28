<?php

namespace App\Update;

use Closure;
use RuntimeException;

/**
 * One update at a time. The lock is a pid file (update.lock.pid: pid, process start time where /proc tells it, and a
 * random per-instance token) whose owner is checked for liveness. Nothing is flock'ed for the duration of the update,
 * because a flock descriptor leaks to child processes in PHP and would outlive a crashed updater. A flock is only used
 * as a short guard (update.lock.guard) around reading/writing the pid file, taken and closed inside one call, so no
 * child can inherit it and two acquirers can never both see "no owner".
 *
 * Pid reuse without readable /proc (no start time to compare) counts the owner as alive: that blocks a new update
 * (safe), it never lets two run.
 */
class UpdateLock
{
    public ?Closure $procStat = null;

    private bool $held = false;

    private ?string $failure = null;

    private readonly string $token;

    public function __construct(private ?string $path = null)
    {
        $this->token = bin2hex(random_bytes(8));
    }

    private function path(): string
    {
        return $this->path ?? dirname((string) (config('ignis.update.state_path') ?: storage_path('app/update/state.json'))).'/update.lock';
    }

    public function lastFailure(): ?string
    {
        return $this->failure;
    }

    /**
     * @param  bool  $force  take the lock even from an owner that looks alive (operator override for a reused pid on
     *                       Windows, ignis:update:recover --force); never used by the pipeline itself
     */
    public function acquire(bool $force = false): bool
    {
        $this->failure = null;
        if ($this->held) {
            return true;
        }
        try {
            return $this->guarded(LOCK_EX, function () use ($force): bool {
                $owner = $this->readOwner();
                if (! $force && $owner !== null && $owner['token'] !== $this->token && $this->alive($owner)) {
                    $this->failure = 'busy';

                    return false;
                }
                $pid = (int) getmypid();
                UpdateState::writeAtomic($this->path().'.pid', $pid.'|'.$this->startTime($pid).'|'.$this->token);

                return $this->held = true;
            });
        } catch (RuntimeException) {
            $this->failure = 'io';

            return false;
        }
    }

    public function release(): void
    {
        $this->held = false;
        try {
            $this->guarded(LOCK_EX, function (): void {
                if (($this->readOwner()['token'] ?? null) === $this->token) {
                    @unlink($this->path().'.pid');
                }
            });
        } catch (RuntimeException) {
        }
    }

    public function ownerAlive(): bool
    {
        try {
            return $this->guarded(LOCK_SH, function (): bool {
                $owner = $this->readOwner();

                return $owner !== null && ($owner['token'] === $this->token ? $this->held : $this->alive($owner));
            });
        } catch (RuntimeException) {
            return true; // cannot tell: the safe answer is "someone owns it"
        }
    }

    private function guarded(int $mode, Closure $fn): mixed
    {
        $dir = dirname($this->path());
        if (! is_dir($dir) && (file_exists($dir) || (! @mkdir($dir, 0775, true) && ! is_dir($dir)))) {
            throw new RuntimeException('lock dir');
        }
        $guard = $this->path().'.guard';
        $new = ! is_file($guard);
        $h = @fopen($guard, $mode === LOCK_SH && ! $new ? 'r' : 'c');
        if ($h !== false && $new) {
            @chmod($guard, 0666);
        }
        if ($h === false || ! flock($h, $mode)) {
            throw new RuntimeException('lock guard');
        }
        try {
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** @return array{pid: int, start: string, token: string}|null null = missing or unparseable (= no owner; writes are atomic and guarded) */
    private function readOwner(): ?array
    {
        $raw = is_file($this->path().'.pid') ? trim((string) @file_get_contents($this->path().'.pid')) : '';

        return preg_match('/^(\d+)\|(\d*)\|([0-9a-f]+)$/D', $raw, $m) ? ['pid' => (int) $m[1], 'start' => $m[2], 'token' => $m[3]] : null;
    }

    /** @param array{pid: int, start: string, token: string} $o */
    private function alive(array $o): bool
    {
        if ($o['start'] !== '' && ($stat = $this->stat($o['pid'])) !== false && preg_match('/^\d+ \(.*\) (\S) (.*)$/s', $stat, $m)) {
            return $m[1] !== 'Z' && (explode(' ', $m[2])[18] ?? '') === $o['start'];
        }

        return self::pidAlive($o['pid']);
    }

    private function startTime(int $pid): string
    {
        $stat = $this->stat($pid);

        return $stat !== false && preg_match('/^\d+ \(.*\) \S (.*)$/s', $stat, $m) ? (explode(' ', $m[1])[18] ?? '') : '';
    }

    private function stat(int $pid): string|false
    {
        if ($this->procStat !== null) {
            return ($this->procStat)($pid);
        }

        return is_readable("/proc/{$pid}/stat") ? @file_get_contents("/proc/{$pid}/stat") : false;
    }

    private static function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }
        if (PHP_OS_FAMILY === 'Windows' && function_exists('exec')) {
            exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL', $out);

            return str_contains(implode(' ', $out), ' '.$pid.' ');
        }

        return true; // cannot tell: assume alive (blocks, never double-runs)
    }
}

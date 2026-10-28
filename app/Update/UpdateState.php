<?php

namespace App\Update;

use App\System\Redact;
use Closure;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Progress of a self-update in one JSON file (config ignis.update.state_path). Every write is temp file + rename.
 *
 * Statuses: idle (no file), running, rolling_back, done, failed, rolled_back, failed_rollback, unknown (file exists
 * but cannot be read; the corrupt file is kept as <file>.corrupt on the next write). begin() always starts from a
 * clean slate (from any state). `reason` is the machine key (UpdateException::reason) of the error, for the UI.
 *
 * Allowed transitions, everything else throws LogicException:
 *   running      -> done                      done()
 *   running      -> failed                    fail()          error before any change, no rollback needed
 *   running      -> rolling_back              beginRollback() error after changes, rollback starts
 *   rolling_back -> rolled_back | failed_rollback   finishRollback()
 *   failed_rollback -> rolled_back            resolve()       restored by hand (ignis:update:recover --force), reason
 *                                                             resolved_by_hand
 *   step() is allowed while running or rolling_back.
 *
 * The pipeline must acquire the UpdateLock BEFORE begin(): a status poll that sees "running" with a free lock reports
 * a crashed run (isStale()), so beginning without the lock would look like a false stale run.
 *
 * Crash detection: UpdateLock records the owner's pid (plus start time on Linux). A running status whose lock owner
 * is no longer alive means the updater process died: isStale(). This does not depend on the flock being released, so a
 * child that inherited the lock descriptor and outlived the updater cannot hide the crash.
 */
class UpdateState
{
    public const STEPS = ['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration', 'switching', 'migrating', 'health_check', 'done', 'rolling_back', 'rolled_back', 'failed_rollback'];

    private const ACTIVE = ['running', 'rolling_back'];

    public ?Closure $rename = null;

    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? (string) config('ignis.update.state_path');
    }

    /** @return array{status: string, step: ?string, from: ?string, to: ?string, started_at: ?string, finished_at: ?string, error: ?string, reason: ?string, rolled_back: bool, backup: ?string, maintenance_was_on: bool, maintenance_left_on: bool, image_ref: ?string, steps: list<array{name: string, at: string, ok: bool}>} */
    public function read(): array
    {
        return $this->load()[0];
    }

    public function isRunning(): bool
    {
        return $this->isActive($this->read()['status']);
    }

    public function isStale(UpdateLock $lock): bool
    {
        return $this->isRunning() && ! $lock->ownerAlive();
    }

    private function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    /**
     * @param  list<string>  $from
     * @return array{status: string, step: ?string, from: ?string, to: ?string, started_at: ?string, finished_at: ?string, error: ?string, reason: ?string, rolled_back: bool, backup: ?string, maintenance_was_on: bool, maintenance_left_on: bool, image_ref: ?string, steps: list<array{name: string, at: string, ok: bool}>}
     */
    private function require(array $from, string $to): array
    {
        $s = $this->read();
        if (! in_array($s['status'], $from, true)) {
            throw new LogicException("update state: cannot go from {$s['status']} to {$to}");
        }

        return $s;
    }

    public function begin(string $from, string $to): void
    {
        $this->write(['status' => 'running', 'from' => $from, 'to' => $to, 'started_at' => $this->now(), 'image_ref' => $this->read()['image_ref']] + self::skeleton());
    }

    public function step(string $name): void
    {
        if (! in_array($name, self::STEPS, true)) {
            throw new InvalidArgumentException("unknown update step {$name}");
        }
        $s = $this->require(self::ACTIVE, $name);
        $s['step'] = $name;
        $s['steps'][] = ['name' => $name, 'at' => $this->now(), 'ok' => true];
        $this->write($s);
    }

    /**
     * Facts of the run that recovery needs: the backup path (written when the backup exists or a rollback starts),
     * whether maintenance mode was already on before the update (then it is left on) and whether the updater still
     * has to switch off the maintenance mode it turned on (maintenance_left_on; also writable after done).
     *
     * @param  array{backup?: string, maintenance_was_on?: bool, maintenance_left_on?: bool, image_ref?: string}  $fields
     */
    public function annotate(array $fields): void
    {
        $s = $this->require([...self::ACTIVE, 'done'], 'annotate');
        $this->write(array_intersect_key($fields, ['backup' => 1, 'maintenance_was_on' => 1, 'maintenance_left_on' => 1, 'image_ref' => 1]) + $s);
    }

    public function done(): void
    {
        $s = $this->require(['running'], 'done');
        $this->write(['status' => 'done', 'step' => null, 'finished_at' => $this->now()] + $s);
    }

    public function fail(string $step, string $message, bool $rolledBack = false, ?string $reason = null): void
    {
        $s = $this->require(['running'], 'failed');
        $s['steps'][] = ['name' => $step, 'at' => $this->now(), 'ok' => false];
        $this->write(['status' => 'failed', 'step' => $step, 'error' => Redact::text($message, 2000), 'reason' => $reason, 'rolled_back' => $rolledBack, 'finished_at' => $this->now()] + $s);
    }

    public function beginRollback(string $step, string $message, ?string $reason = null): void
    {
        $s = $this->require(['running'], 'rolling_back');
        $s['steps'][] = ['name' => $step, 'at' => $this->now(), 'ok' => false];
        $this->write(['status' => 'rolling_back', 'step' => $step, 'error' => Redact::text($message, 2000), 'reason' => $reason] + $s);
    }

    public function finishRollback(bool $ok, ?string $message = null): void
    {
        $s = $this->require(['rolling_back'], $ok ? 'rolled_back' : 'failed_rollback');
        $s['finished_at'] = $this->now();
        $s['status'] = $ok ? 'rolled_back' : 'failed_rollback';
        $s['rolled_back'] = $ok;
        if ($message !== null) {
            $s['error'] = Redact::text($message, 2000);
        }
        $this->write($s);
    }

    public function resolve(string $note): void
    {
        $s = $this->require(['failed_rollback'], 'rolled_back');
        $this->write(['status' => 'rolled_back', 'rolled_back' => true, 'reason' => 'resolved_by_hand', 'finished_at' => $this->now(),
            'error' => Redact::text(trim($note.' '.($s['error'] ?? '')), 2000)] + $s);
    }

    /** @return array{status: string, step: ?string, from: ?string, to: ?string, started_at: ?string, finished_at: ?string, error: ?string, reason: ?string, rolled_back: bool, backup: ?string, maintenance_was_on: bool, maintenance_left_on: bool, image_ref: ?string, steps: list<array{name: string, at: string, ok: bool}>} */
    private static function skeleton(): array
    {
        return ['status' => 'idle', 'step' => null, 'from' => null, 'to' => null, 'started_at' => null, 'finished_at' => null, 'error' => null, 'reason' => null, 'rolled_back' => false, 'backup' => null, 'maintenance_was_on' => false, 'maintenance_left_on' => false, 'image_ref' => null, 'steps' => []];
    }

    private function now(): string
    {
        return now()->utc()->toIso8601ZuluString();
    }

    /** @return array{0: array{status: string, step: ?string, from: ?string, to: ?string, started_at: ?string, finished_at: ?string, error: ?string, reason: ?string, rolled_back: bool, backup: ?string, maintenance_was_on: bool, maintenance_left_on: bool, image_ref: ?string, steps: list<array{name: string, at: string, ok: bool}>}, 1: bool} state and whether the file was corrupt */
    private function load(): array
    {
        $skeleton = self::skeleton();
        if (! is_file($this->path())) {
            return [$skeleton, false];
        }
        $raw = @file_get_contents($this->path());
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data) || ! is_string($data['status'] ?? null)) {
            return [['status' => 'unknown', 'error' => 'state file unreadable'] + $skeleton, true];
        }

        return [array_replace($skeleton, $data), false];
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        [, $corrupt] = $this->load();
        if ($corrupt) {
            @copy($this->path(), $this->path().'.corrupt');
        }
        self::writeAtomic($this->path(), (string) json_encode(array_replace(self::skeleton(), $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $this->rename);
    }

    public static function writeAtomic(string $path, string $content, ?Closure $rename = null): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('state dir');
        }
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('state write');
        }
        $rename ??= fn (string $a, string $b): bool => @rename($a, $b);
        for ($i = 0; $i < 10; $i++) {
            if ($rename($tmp, $path)) {
                return;
            }
            usleep(30_000);
        }
        $ok = @copy($tmp, $path);
        @unlink($tmp);
        if (! $ok) {
            throw new RuntimeException('state write');
        }
    }
}

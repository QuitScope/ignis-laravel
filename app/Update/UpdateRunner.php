<?php

namespace App\Update;

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Support\AuditLogger;
use App\System\PendingMigrations;
use App\System\Redact;
use App\System\SystemOverview;
use App\Update\Backup\BackupManager;
use App\Update\Backup\BackupSet;
use App\Update\Backup\DatabaseDumper;
use App\Update\Backup\MariaDbDumper;
use App\Update\Backup\SqliteDumper;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\UpCommand;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * The self-update of a files installation (Weg A, spec §5): checking -> downloading -> verifying -> (maintenance on)
 * backing_up -> trial_migration -> switching -> migrating -> health_check -> done.
 *
 * Errors before switching changed nothing: fail(), maintenance back to how it was. From switching until the swap is
 * committed: rollback() (swap revert, database and .env from the backup, caches, health check of the old version, only
 * then maintenance off); a failing rollback ends in failed_rollback with maintenance ON and the backup path in the
 * message. After the commit nothing is rolled back any more: a failing state write is retried and logged.
 *
 * Runs in its own CLI process (ignis:update:run), never in a web request. Order: UpdateLock first, then
 * UpdateState::begin(); the lock is released after the final state was written. The app path is resolved once per
 * run (in the release layout base_path() stays the old release while `current` moves on). Everything that runs in
 * the new code (migrate, optimize, the health probe) is a child process; this process never loads its classes.
 */
class UpdateRunner
{
    public ?Closure $fault = null;

    /** @var (Closure(list<string>): void)|null runs `php artisan ...` in the app directory, throws on failure; null = real child process */
    public ?Closure $artisan = null;

    /** @var (Closure(string): (float|int|false))|null free bytes of a directory (tests inject it) */
    public ?Closure $freeSpace = null;

    public ?BackupManager $backups = null;

    public ?TrialMigration $trial = null;

    public ?UpdateLog $log = null;

    private string $step = 'checking';

    private string $app = '';

    private ?string $failReason = null;

    private bool $maintenanceWasOn = false;

    public function __construct(
        private UpdateState $state,
        private UpdateLock $lock,
        private Maintenance $maintenance,
        private Swapper $swapper,
        private HealthGate $gate,
    ) {}

    public function run(string $version, bool $skipTrial = false): void
    {
        self::filesOnly();
        $this->lockOrFail();
        try {
            $status = $this->state->read()['status'];
            if ($status === 'failed_rollback') {
                throw new UpdateException('rollback_needed');
            }
            if (in_array($status, ['running', 'rolling_back'], true)) {
                throw new UpdateException('recover_needed');
            }
            $this->app = Installation::appPath();
            $from = InstalledVersion::current($this->app);
            $this->maintenanceWasOn = $this->maintenance->isActive();
            $this->state->begin($from, $version);
            $this->guard(fn () => $this->state->annotate(['maintenance_was_on' => $this->maintenanceWasOn]));
            $this->log()->write("update $from -> $version started (log ".$this->log()->path().')');
            $this->pipeline($from, $version, $skipTrial);
        } finally {
            $this->lock->release();
        }
    }

    public function recover(bool $force = false): string
    {
        self::filesOnly();
        $this->app = Installation::appPath();
        if (($refusal = $this->checkoutRefusal()) !== null) {
            throw $refusal;
        }
        $s = $this->state->read();
        if (! $this->state->isRunning()) {
            if ($this->swapper->pending() !== []) {
                throw new UpdateException('rollback_needed');
            }
            if ($s['status'] === 'done' && $s['maintenance_left_on'] && ! $s['maintenance_was_on'] && $this->maintenance->isActive()) {
                $this->maintenance->disable();
                $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => false]));

                return 'the update had finished; maintenance mode switched off';
            }

            return 'nothing to recover';
        }
        $this->lockOrFail($force);
        try {
            $s = $this->state->read();
            $this->maintenanceWasOn = $s['maintenance_was_on'];
            $this->log()->write('recovering an interrupted update '.$s['from'].' -> '.$s['to'].' (status '.$s['status'].', step '.$s['step'].')');
            $changed = $s['status'] === 'rolling_back' || in_array('switching', array_column($s['steps'], 'name'), true);
            $set = $this->backupOfRun($s);
            if (! $changed) {
                $this->guard(fn () => $this->state->fail((string) ($s['step'] ?? 'checking'), __('update.error.interrupted'), reason: 'interrupted'));
                $this->restoreMaintenance();
                if ($set !== null) {
                    (new Filesystem)->deleteDirectory($set->path);
                }
                $this->cleanWork();

                return 'interrupted before any change; marked as failed';
            }
            if ($s['status'] === 'running' && $s['step'] === 'health_check' && $this->swapper->pending() === []
                && InstalledVersion::current($this->app) === $s['to']) {
                $this->guard(fn () => $this->state->done());
                if ($this->restoreMaintenance()) {
                    $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => false]));
                }
                $this->cleanWork();

                return 'the update had completed; marked as done';
            }
            if ($set === null) {
                if ($s['status'] === 'running') {
                    $this->guard(fn () => $this->state->beginRollback((string) ($s['step'] ?? 'switching'), __('update.error.interrupted'), reason: 'interrupted'));
                }
                $this->guard(fn () => $this->maintenance->enable());
                $this->guard(fn () => $this->state->finishRollback(false, __('update.error.rollback_failed', ['reason' => __('update.error.backup_not_found'), 'path' => (string) config('ignis.update.backup_path')])));

                return 'no backup of this run found; failed_rollback';
            }
            $this->step = (string) ($s['step'] ?? 'switching');
            $ok = $this->rollback($set, __('update.error.interrupted'), full: true);
            $this->cleanWork();

            return $ok ? 'rolled back' : 'rollback failed, see the state and the update log';
        } finally {
            $this->lock->release();
        }
    }

    public function rollbackToLatest(): bool
    {
        self::filesOnly();
        if (($refusal = $this->checkoutRefusal()) !== null) {
            throw $refusal;
        }
        $this->lockOrFail();
        try {
            $status = $this->state->read()['status'];
            if ($this->state->isRunning()) {
                throw new UpdateException('recover_needed');
            }
            $this->app = Installation::appPath();
            $current = InstalledVersion::current($this->app);
            $sets = $this->backups()->list();
            $set = $status === 'failed_rollback' ? ($sets[0] ?? null)
                : (array_values(array_filter($sets, fn (BackupSet $s) => $s->toVersion === $current))[0] ?? null);
            if ($set === null) {
                throw new UpdateException('no_backup');
            }
            $this->maintenanceWasOn = $status === 'failed_rollback' ? $this->state->read()['maintenance_was_on'] : $this->maintenance->isActive();
            $this->state->begin($current, $set->fromVersion);
            $this->guard(fn () => $this->state->annotate(['backup' => $set->path, 'maintenance_was_on' => $this->maintenanceWasOn]));
            $this->step = 'rolling_back';

            return $this->rollback($set, __('update.error.manual_rollback', ['version' => $set->fromVersion]), full: true);
        } finally {
            $this->lock->release();
        }
    }

    public function checkoutRefusal(): ?UpdateException
    {
        if (Installation::selfUpdateBlocker() !== 'git_checkout' || $this->swapper->pending() !== []
            || $this->state->isRunning() || $this->state->read()['status'] === 'failed_rollback') {
            return null;
        }

        return new UpdateException('blocked', ['reason' => __('update.blocker.git_checkout')]);
    }

    public function resolveFailedRollback(): string
    {
        self::filesOnly();
        $this->app = Installation::appPath();
        if ($this->state->read()['status'] !== 'failed_rollback') {
            return 'nothing to resolve';
        }
        if ($this->swapper->pending() !== []) {
            throw new UpdateException('rollback_needed');
        }
        $this->lockOrFail();
        try {
            $this->state->resolve(__('update.error.resolved_by_hand'));
            $this->log()->write('failed rollback marked as resolved by hand');
        } finally {
            $this->lock->release();
        }

        return 'failed rollback marked as resolved; switch maintenance mode off with php artisan up when the site is ready';
    }

    public function rollback(BackupSet $set, string $reason, bool $full = false): bool
    {
        if ($this->app === '') {
            $this->app = Installation::appPath();
        }
        $s = $this->state->read();
        $this->log()->write("rollback to {$set->fromVersion} from {$set->path}: ".$reason);
        if ($s['status'] === 'running') {
            $this->guard(fn () => $this->state->beginRollback($this->step, $reason, reason: $this->failReason));
        }
        $this->guard(fn () => $this->state->annotate(['backup' => $set->path]));
        $this->guard(fn () => $this->maintenance->enable());

        $problem = null;
        $reverted = false;
        try {
            $this->purgeConnections();
            $this->swapper->revert();
            $reverted = true;
        } catch (Throwable $e) {
            $this->log()->write('swap revert failed, the code comes from the backup: '.self::describe($e));
        }
        try {
            $this->backups()->restore($set, code: $full || ! $reverted);
            if (! $reverted) {
                $this->swapper->discard();
            }
        } catch (Throwable $e) {
            $this->log()->write('restore failed: '.self::describe($e));
            $problem = $e instanceof UpdateException ? $e->getMessage() : __('update.error.unexpected_step', ['step' => 'rolling_back']);
        }
        if ($problem === null) {
            try {
                $this->artisan(['optimize:clear']);
                $this->gate->check($set->fromVersion, false, false, $this->app);
            } catch (Throwable $e) {
                $this->log()->write('after the restore: '.self::describe($e));
                $problem = $e instanceof UpdateException ? $e->getMessage() : __('update.error.unexpected_step', ['step' => 'rolling_back']);
            }
        }
        self::resetOpcache();

        if ($problem === null) {
            $this->guard(fn () => $this->state->finishRollback(true));
            $this->restoreMaintenance();
            $this->log()->write('rolled back to '.$set->fromVersion);
            AuditLogger::log(null, 'Update zurückgerollt', Redact::summary($reason), 'System', true, ['version' => $set->fromVersion]);

            return true;
        }
        $message = __('update.error.rollback_failed', ['reason' => $problem, 'path' => $set->path]);
        $this->guard(fn () => $this->state->finishRollback(false, $message));
        $this->log()->write('ROLLBACK FAILED, maintenance mode stays on: '.$message);
        AuditLogger::log(null, 'Update fehlgeschlagen', Redact::summary($message), 'System', true);

        return false;
    }

    private function pipeline(string $from, string $version, bool $skipTrial): void
    {
        $app = $this->app;
        $work = "$app/".FlatSwapper::OLD.'/.work-'.bin2hex(random_bytes(4));
        $set = null;
        $switching = false;
        $committed = false;
        $maintenance = false;
        if ($this->swapper instanceof FlatSwapper || $this->swapper instanceof SymlinkSwapper) {
            $this->swapper->log ??= $this->log();
        }
        try {
            $this->enter('checking');
            $this->preconditions($version);

            $this->enter('downloading');
            if (! @mkdir($work, 0700, true)) {
                throw new UpdateException('write');
            }
            $work = realpath($work) ?: $work;
            $source = new ReleaseSource;
            $release = $source->latest();
            if ($release === null || $release->version !== $version) {
                throw new UpdateException('release_changed', ['version' => $version]);
            }
            $manifest = $source->fetchVerifiedManifest($release);
            $source->downloadArchive($manifest, "$work/release.zip");

            $this->enter('verifying');
            (new ArchiveExtractor)->extract("$work/release.zip", "$work/stage", $manifest->version);
            @unlink("$work/release.zip");
            $this->requireSpace("$work/stage");

            $seen = $this->gate->snapshot();
            $maintenance = true;
            $this->maintenance->enable();

            $this->enter('backing_up');
            $this->quietly(['queue:restart']);
            $set = $this->backups()->create($from, $version);
            $this->guard(fn () => $this->state->annotate(['backup' => $set->path]));
            $this->log()->write("backup {$set->path}");

            $this->enter('trial_migration');
            if ($skipTrial) {
                $this->log()->write('trial migration skipped on request');
            } else {
                ($this->trial ?? new TrialMigration(null, $this->log()))->run("$work/stage");
            }

            $this->preload();
            if ($this->swapper instanceof FlatSwapper) {
                $this->swapper->recovery = $this->recoveryInfo($set);
            }
            $switching = true;
            $this->enter('switching');
            $this->purgeConnections();
            $this->swapper->swap("$work/stage");

            $this->enter('migrating');
            $this->artisan(['migrate', '--force']);
            $this->artisan(['optimize:clear']);
            $this->artisan(['optimize']);

            $this->enter('health_check');
            $this->gate->check($version, $seen['scheduler'], $seen['worker'], $app);

            $this->swapper->commit();
            $committed = true;
            $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => ! $this->maintenanceWasOn]));
            $this->finishDone();
        } catch (Throwable $e) {
            $this->failReason = $e instanceof UpdateException ? $e->reason : null;
            $this->log()->write("step {$this->step} failed: ".self::describe($e));
            if ($committed) {
                $this->log()->write('after the commit; the update stays');
            } else {
                $message = $e instanceof UpdateException ? $e->getMessage() : __('update.error.unexpected_step', ['step' => $this->step]);
                if ($switching && $set !== null) {
                    $this->rollback($set, $message);
                } else {
                    $this->guard(fn () => $this->state->fail($this->step, $message, reason: $this->failReason));
                    if ($set !== null) {
                        (new Filesystem)->deleteDirectory($set->path);
                    }
                    if ($maintenance) {
                        $this->restoreMaintenance();
                    }
                    AuditLogger::log(null, 'Update fehlgeschlagen', Redact::summary($message), 'System', true, ['from' => $from, 'to' => $version]);
                }

                return;
            }
        } finally {
            (new Filesystem)->deleteDirectory($work);
        }

        $this->log()->write("update to $version done");
        $this->guard(fn () => AuditLogger::log(null, 'Update erfolgreich', "$from -> $version", 'System', true, ['from' => $from, 'to' => $version]));
        $this->quietly(['queue:restart']);
        if (! $this->maintenanceWasOn && $this->restoreMaintenance()) {
            $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => false]));
        }
        $this->guard(fn () => Cache::forget(PendingMigrations::CACHE_KEY));
        $this->guard(fn () => $this->backups()->prune($set));
        self::resetOpcache();
    }

    private function finishDone(): void
    {
        for ($i = 1; ; $i++) {
            try {
                $this->state->done();

                return;
            } catch (Throwable $e) {
                $this->log()->write("state done could not be written (attempt $i): ".self::describe($e));
                if ($i >= 3) {
                    return;
                }
                usleep(200_000);
            }
        }
    }

    private static function filesOnly(): void
    {
        if (Installation::driver() !== 'files') {
            throw new UpdateException('docker_use_updater');
        }
    }

    private function preconditions(string $version): void
    {
        if (($blocker = Installation::selfUpdateBlocker()) !== null) {
            throw new UpdateException('blocked', ['reason' => __("update.blocker.$blocker")]);
        }
        $this->configuration();
        if (! InstalledVersion::isValid($version)) {
            throw new UpdateException('bad_version');
        }
    }

    public function preflight(): ?UpdateException
    {
        try {
            $this->configuration();

            return null;
        } catch (UpdateException $e) {
            return $e;
        }
    }

    /** @throws UpdateException */
    private function configuration(): void
    {
        if (Installation::driver() !== 'files') {
            $driver = app(DockerDriver::class);
            if (! $driver->configured()) {
                throw new UpdateException('driver_docker');
            }
            $driver->ping();

            return;
        }
        $dumper = DatabaseDumper::for();
        if (($reason = $dumper->available()) !== null) {
            throw new UpdateException('dump_unavailable', ['reason' => $reason]);
        }
        if ((new PhpExecutableFinder)->find(false) === false) {
            throw new UpdateException('php_missing');
        }
        if ($this->swapper->pending() !== []) {
            throw new UpdateException('recover_needed');
        }
        if ($this->swapper instanceof SymlinkSwapper) {
            $this->swapper->check($dumper->localFiles());
        }
    }

    private function requireSpace(string $stage): void
    {
        $code = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $code += $f->isFile() ? (int) $f->getSize() : 0;
        }
        $db = DatabaseDumper::for()->sizeBytes();
        $need = 2 * ($code + $db) + $db + BackupManager::RESERVE_BYTES;
        $free = ($this->freeSpace ?? fn (string $dir) => @disk_free_space($dir))($this->app);
        if ($free === false || $free < $need) {
            throw new UpdateException('disk_space', ['need' => (int) ceil($need / 1048576), 'free' => (int) floor(max(0, (float) $free) / 1048576)]);
        }
    }

    /** @return array<string, string> what revert.php needs to put the database and .env back by hand */
    private function recoveryInfo(BackupSet $set): array
    {
        $info = ['env' => "$this->app/.env", 'env_backup' => "$set->path/env"];
        try {
            $dumper = DatabaseDumper::for();
            if ($dumper instanceof SqliteDumper) {
                $info += ['db_type' => 'sqlite', 'db_file' => $dumper->file(), 'db_backup' => "$set->path/db.sqlite"];
            } elseif ($dumper instanceof MariaDbDumper) {
                $info += ['db_type' => 'mariadb', 'database' => $dumper->database(), 'db_backup' => "$set->path/db.sql"];
            }
        } catch (Throwable) {
        }

        return $info;
    }

    private function enter(string $step): void
    {
        $this->step = $step;
        $this->state->step($step);
        $this->log()->write("step $step");
        if ($this->fault !== null) {
            ($this->fault)($step);
        }
    }

    /** @param list<string> $args */
    private function artisan(array $args): void
    {
        if ($this->artisan !== null) {
            ($this->artisan)($args);

            return;
        }
        $php = (new PhpExecutableFinder)->find(false) ?: throw new UpdateException('php_missing');
        try {
            $result = Process::path($this->app)->timeout(600)
                ->run([$php, 'artisan', ...$args, '--no-interaction', '--no-ansi']);
        } catch (ProcessTimedOutException) {
            $this->log()->write("artisan {$args[0]} exceeded 600 s");
            throw new UpdateException('command_failed', ['command' => $args[0]]);
        }
        $this->log()->write("artisan {$args[0]} (exit {$result->exitCode()}):\n".substr($result->output()."\n".$result->errorOutput(), -1800));
        if ($result->failed()) {
            throw new UpdateException('command_failed', ['command' => $args[0]]);
        }
    }

    /** @param list<string> $args a command whose failure must not stop the update (logged) */
    private function quietly(array $args): void
    {
        try {
            $this->artisan($args);
        } catch (Throwable $e) {
            $this->log()->write("artisan {$args[0]} failed (ignored): ".self::describe($e));
        }
    }

    private function restoreMaintenance(): bool
    {
        if ($this->maintenanceWasOn) {
            return true;
        }
        try {
            $this->maintenance->disable();

            return true;
        } catch (Throwable $e) {
            $this->log()->write('maintenance mode could not be switched off (php artisan up): '.self::describe($e));

            return false;
        }
    }

    private function purgeConnections(): void
    {
        foreach (array_keys((array) config('database.connections')) as $name) {
            DB::purge((string) $name);
        }
    }

    /**
     * After the switch, classes load from the NEW code (the autoloader paths point into the app directory) until
     * revert() puts the old code back. This process only orchestrates: what it still needs in that window is loaded
     * from the old code now; everything of the new code runs in child processes.
     * ponytail: fixed list; a new class used between switching and revert/finish must be added here.
     */
    private function preload(): void
    {
        foreach ([HealthGate::class, SystemOverview::class, SchedulerHeartbeatCommand::class, PendingMigrations::class, BackupSet::class,
            UpdateException::class, ProcessTimedOutException::class, AuditLogger::class, Redact::class, UpCommand::class,
            MaintenanceModeDisabled::class, Process::class, PhpExecutableFinder::class,
            'Spatie\Activitylog\Support\ActivityLogger', 'Spatie\Activitylog\Support\PendingActivityLog',
            'Spatie\Activitylog\Support\ActivityLogStatus', 'Spatie\Activitylog\Support\CauserResolver',
            'Spatie\Activitylog\Support\ActivityBuffer', 'Spatie\Activitylog\Support\Config',
            'Spatie\Activitylog\Actions\LogActivityAction', 'Spatie\Activitylog\Contracts\Activity',
            'Spatie\Activitylog\Enums\ActivityEvent', 'Spatie\Activitylog\Exceptions\CouldNotLogActivity',
            (string) config('activitylog.activity_model', 'Spatie\Activitylog\Models\Activity')] as $class) {
            class_exists($class) || interface_exists($class) || enum_exists($class);
        }
        $this->guard(fn () => activity('System'));
        $this->guard(fn () => Cache::store());
        $this->guard(fn () => app(SystemOverview::class)->scheduler());
        __('update.error.rollback_failed');
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function backups(): BackupManager
    {
        return $this->backups ??= new BackupManager(null, null, $this->log());
    }

    private function log(): UpdateLog
    {
        return $this->log ??= new UpdateLog;
    }

    private function lockOrFail(bool $force = false): void
    {
        if (! $this->lock->acquire($force)) {
            throw new UpdateException($this->lock->lastFailure() === 'io' ? 'lock_io' : 'busy');
        }
    }

    /**
     * The backup of the run in $s: the one recorded in the state, else the newest one with its versions made after it
     * started.
     *
     * @param  array{from: ?string, to: ?string, started_at: ?string, backup: ?string}  $s
     */
    private function backupOfRun(array $s): ?BackupSet
    {
        // the recorded path only counts inside the backup directory (the state file is not a trust anchor)
        $base = realpath((string) config('ignis.update.backup_path'));
        $real = is_string($s['backup']) ? realpath($s['backup']) : false;
        if ($base !== false && $real !== false && dirname($real) === $base && ($set = BackupSet::fromDir($real)) !== null) {
            return $set;
        }
        $since = strtotime((string) $s['started_at']) ?: PHP_INT_MAX;
        foreach ($this->backups()->list() as $set) {
            if ($set->fromVersion === $s['from'] && $set->toVersion === $s['to'] && $set->createdAt->getTimestamp() >= $since - 5) {
                return $set;
            }
        }

        return null;
    }

    private function cleanWork(): void
    {
        foreach (glob("$this->app/".FlatSwapper::OLD.'/.work-*', GLOB_ONLYDIR) ?: [] as $dir) {
            (new Filesystem)->deleteDirectory($dir);
        }
    }

    private function guard(Closure $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->log()->write('ignored during error handling: '.self::describe($e));
        }
    }

    private static function describe(Throwable $e): string
    {
        return $e instanceof UpdateException ? $e->reason.': '.$e->getMessage() : $e::class.': '.$e->getMessage();
    }
}

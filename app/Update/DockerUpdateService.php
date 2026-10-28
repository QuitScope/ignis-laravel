<?php

namespace App\Update;

use App\Update\Backup\BackupManager;
use App\Update\Backup\BackupSet;
use App\Update\Backup\SqliteDumper;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Weg B (spec §7): the update of a Docker Compose installation, run inside the updater container, which alone holds
 * the Docker socket. Same state file, lock, log and backup layout as Weg A (shared volumes), other steps:
 *
 * checking (app answers, version, repo, key; the running image is tagged ignis-update-previous:<from>)
 * -> downloading (release fetched and verified HERE with the same SignatureVerifier and key, `docker pull
 * <manifest.image>@<manifest.image_digest>`; nothing changed yet) -> verifying (the pulled digest)
 * -> maintenance on -> backing_up (mariadb-dump in the db container into the backup volume) -> trial_migration
 * (new image migrates a copy in the database ignis_trial; without CREATE/DROP rights: trial_unavailable)
 * -> switching (image override file in the updater-only volume, checked before every compose call; `up -d` of app,
 * worker, scheduler and reverb if its container exists) -> migrating (the new app container migrates on start, AUTORUN) -> health_check (health probe
 * in the app container: version, /healthz checks, no pending migrations; worker/scheduler running if they ran before)
 * -> done, maintenance as before, old backups pruned.
 *
 * Any error from switching on: rollback = stop the same services, database from the dump, override back to the
 * previous image, `up -d`, health check of the old version, maintenance as before. A failing rollback ends in
 * failed_rollback with maintenance ON and the manual steps in the message.
 *
 * Nothing here comes from the request but the version string (checked SemVer) and the trial flag: images only from
 * the signed manifest, commands are fixed argv arrays run with proc_open (no shell on this side; the scripts run in the
 * db container are constants and take the credentials from that container's own environment, never from argv).
 */
class DockerUpdateService
{
    public const SERVICES = ['app', 'worker', 'scheduler'];

    public const TRIAL_DB = 'ignis_trial';

    public const PREVIOUS = 'ignis-update-previous';

    private const IMAGE_REF = '~^[a-z0-9][a-z0-9._/-]*(:[A-Za-z0-9_.-]{1,128})?(@sha256:[0-9a-f]{64})?$~D';

    private const LOGIN = 'MYSQL_PWD="$MARIADB_PASSWORD" exec ';

    private const DB_DUMP = self::LOGIN.'mariadb-dump -u"$MARIADB_USER" --single-transaction --routines --triggers --default-character-set=utf8mb4 "$MARIADB_DATABASE"';

    private const DB_CLIENT = self::LOGIN.'mariadb --sandbox -u"$MARIADB_USER" --default-character-set=utf8mb4 "$MARIADB_DATABASE"';

    private const DB_TABLES = self::LOGIN.'mariadb -u"$MARIADB_USER" -N -B -e "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()" "$MARIADB_DATABASE"';

    private const TRIAL_CREATE = self::LOGIN.'mariadb -u"$MARIADB_USER" -e "DROP DATABASE IF EXISTS '.self::TRIAL_DB.'; CREATE DATABASE '.self::TRIAL_DB.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"';

    private const TRIAL_LOAD = self::LOGIN.'mariadb --sandbox -u"$MARIADB_USER" --default-character-set=utf8mb4 '.self::TRIAL_DB;

    private const TRIAL_DROP = self::LOGIN.'mariadb -u"$MARIADB_USER" -e "DROP DATABASE IF EXISTS '.self::TRIAL_DB.'"';

    /** @var (Closure(list<string>, ?string, ?string): array{0: int, 1: string, 2: string})|null argv, stdin file, stdout file -> exit code, stdout, stderr; null = real process */
    public ?Closure $run = null;

    public ?Closure $fault = null;

    public int $healthAttempts = 60;

    public int $pauseMs = 3000;

    private string $step = 'checking';

    private ?string $failReason = null;

    private bool $maintenanceWasOn = false;

    private ?bool $maintenanceOnNow = null;

    private ?bool $sandbox = null;

    /** @var list<string> worker/scheduler that ran before the run: a rollback's health check expects them again */
    private array $seen = [];

    /** @var list<string>|null SERVICES plus reverb when its container exists; null = not asked yet in this run */
    private ?array $services = null;

    public function __construct(private UpdateState $state, private UpdateLock $lock, private ?UpdateLog $log = null) {}

    public function installedVersion(): ?string
    {
        $r = $this->probe();

        return is_string($r['version'] ?? null) ? $r['version'] : null;
    }

    /**
     * The signed manifest of $version, verified here (independent of the app): latest stable release, signature with
     * the built-in key, age, min_version, newer than $installed, and it must name a signed image.
     *
     * @throws UpdateException
     */
    public function verifiedManifest(string $version, string $installed): Manifest
    {
        if (! InstalledVersion::isStable($version)) {
            throw new UpdateException('bad_version');
        }
        if (! InstalledVersion::isValid($installed)) {
            throw new UpdateException('dev_version');
        }
        $source = new ReleaseSource;
        $release = $source->latest() ?? throw new UpdateException('no_release');
        if ($release->version !== $version) {
            throw new UpdateException('release_changed', ['version' => $version]);
        }
        $manifest = $source->fetchVerifiedManifest($release, $installed);
        if ($manifest->image === null || $manifest->imageDigest === null) {
            throw new UpdateException('image_missing');
        }

        return $manifest;
    }

    public function run(string $version, bool $skipTrial = false): void
    {
        $this->lockOrFail();
        try {
            $status = $this->state->read()['status'];
            if ($status === 'failed_rollback') {
                throw new UpdateException('rollback_needed');
            }
            if (in_array($status, ['running', 'rolling_back'], true)) {
                throw new UpdateException('recover_needed');
            }
            $from = $this->installedVersion() ?? 'unknown';
            $this->maintenanceWasOn = $this->maintenanceActive();
            $this->state->begin($from, $version);
            $this->guard(fn () => $this->state->annotate(['maintenance_was_on' => $this->maintenanceWasOn]));
            $this->log()->write("docker update $from -> $version started (log ".$this->log()->path().')');
            $this->pipeline($from, $version, $skipTrial);
        } finally {
            $this->lock->release();
        }
    }

    public function rollbackToLatest(): bool
    {
        $this->lockOrFail();
        try {
            $s = $this->state->read();
            if ($this->state->isRunning()) {
                throw new UpdateException('recover_needed');
            }
            $current = $this->installedVersion() ?? 'unknown';
            $sets = $this->backups()->list();
            $set = $s['status'] === 'failed_rollback' ? ($sets[0] ?? null)
                : (array_values(array_filter($sets, fn (BackupSet $b) => $b->toVersion === $current))[0] ?? null);
            if ($set === null) {
                throw new UpdateException('no_backup');
            }
            $broken = $s['status'] === 'failed_rollback';
            $this->maintenanceWasOn = $broken ? $s['maintenance_was_on'] : $this->maintenanceActive();
            $this->seen = $this->runningServices();
            $this->state->begin($current, $set->fromVersion);
            $this->guard(fn () => $this->state->annotate(['backup' => $set->path, 'maintenance_was_on' => $this->maintenanceWasOn]));
            $this->step = 'rolling_back';
            try {
                $image = $this->prepareRollback($set);
            } catch (UpdateException $e) {
                $this->log()->write('rollback not possible: '.self::describe($e));
                if ($broken) {
                    $this->guard(fn () => $this->state->beginRollback('rolling_back', $e->getMessage(), reason: $e->reason));
                    $this->guard(fn () => $this->state->finishRollback(false, $this->rollbackFailed($e->getMessage(), $set->path)));
                } else {
                    $this->guard(fn () => $this->state->fail('rolling_back', $e->getMessage(), reason: $e->reason));
                }

                return false;
            }

            return $this->rollback($set, __('update.error.manual_rollback', ['version' => $set->fromVersion]), $image);
        } finally {
            $this->lock->release();
        }
    }

    public function resolveFailedRollback(): string
    {
        if ($this->state->read()['status'] !== 'failed_rollback') {
            return 'nothing to resolve';
        }
        $this->lockOrFail();
        try {
            $this->state->resolve(__('update.error.resolved_by_hand'));
            $this->log()->write('failed rollback marked as resolved by hand');
        } finally {
            $this->lock->release();
        }

        return 'failed rollback marked as resolved; switch maintenance mode off with docker compose exec app php artisan up when the site is ready';
    }

    public function recover(): string
    {
        if (! $this->state->isRunning()) {
            $s = $this->state->read();
            if ($s['status'] === 'done' && $s['maintenance_left_on'] && ! $s['maintenance_was_on'] && $this->maintenanceActive()) {
                $this->maintenanceWasOn = false;
                if ($this->restoreMaintenance()) {
                    $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => false]));
                }

                return 'the update had finished; maintenance mode switched off';
            }

            return 'nothing to recover';
        }
        $this->lockOrFail();
        try {
            $s = $this->state->read();
            $this->maintenanceWasOn = $s['maintenance_was_on'];
            $this->log()->write('recovering an interrupted docker update '.$s['from'].' -> '.$s['to'].' (status '.$s['status'].', step '.$s['step'].')');
            $this->guard(fn () => $this->db(self::TRIAL_DROP, 'trial drop'));
            @unlink($this->trialPath());
            if ($s['status'] === 'running' && $s['step'] === 'health_check' && $this->healthyTarget($s)) {
                $this->finishDone();
                if (! $this->maintenanceWasOn && $this->restoreMaintenance()) {
                    $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => false]));
                }

                return 'the update had completed; marked as done';
            }
            $changed = $s['status'] === 'rolling_back' || in_array('switching', array_column($s['steps'], 'name'), true);
            $set = $this->backupOfRun($s);
            if (! $changed) {
                $this->guard(fn () => $this->state->fail((string) ($s['step'] ?? 'checking'), __('update.error.interrupted'), reason: 'interrupted'));
                $this->restoreMaintenance();
                if ($set !== null) {
                    (new Filesystem)->deleteDirectory($set->path);
                }

                return 'interrupted before any change; marked as failed';
            }
            if ($set === null) {
                if ($s['status'] === 'running') {
                    $this->guard(fn () => $this->state->beginRollback((string) ($s['step'] ?? 'switching'), __('update.error.interrupted'), reason: 'interrupted'));
                }
                $this->guard(fn () => $this->state->finishRollback(false, $this->rollbackFailed(__('update.error.backup_not_found'), (string) config('ignis.update.backup_path'))));

                return 'no backup of this run found; failed_rollback';
            }
            $this->step = (string) ($s['step'] ?? 'switching');

            return $this->rollback($set, __('update.error.interrupted')) ? 'rolled back' : 'rollback failed, see the state and the update log';
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Back to the backup: services stopped, database from the dump, override to the previous image, up, health check
     * of the old version (worker/scheduler too when they ran before), then maintenance as before. Before anything is
     * stopped, the previous image and the dump must be there (prepareRollback); otherwise nothing is touched.
     * False on failed_rollback (maintenance stays on).
     *
     * @param  string|null  $image  the previous image, already checked by prepareRollback()
     */
    public function rollback(BackupSet $set, string $reason, ?string $image = null): bool
    {
        $this->log()->write("rollback to {$set->fromVersion} from {$set->path}: ".$reason);
        if ($this->state->read()['status'] === 'running') {
            $this->guard(fn () => $this->state->beginRollback($this->step, $reason, reason: $this->failReason));
        }
        $this->guard(fn () => $this->state->annotate(['backup' => $set->path]));

        $problem = null;
        try {
            $image ??= $this->prepareRollback($set);
            $this->guard(fn () => $this->maintenanceOn());
            $this->compose(['stop', ...$this->services()], 'stop', 300);
            $this->restoreDatabase("$set->path/db.sql");
            $this->writeOverride($image);
            $this->compose(['up', '-d', '--no-deps', '--no-build', '--pull', 'never', ...$this->services()], 'up', 600);
            $this->health($set->fromVersion, $this->seen);
        } catch (Throwable $e) {
            $this->log()->write('rollback step failed: '.self::describe($e));
            $problem = $e instanceof UpdateException ? $e->getMessage() : __('update.error.unexpected_step', ['step' => 'rolling_back']);
        }

        if ($problem === null) {
            $this->guard(fn () => $this->state->finishRollback(true));
            $this->restoreMaintenance();
            $this->log()->write('rolled back to '.$set->fromVersion);

            return true;
        }
        $message = $this->rollbackFailed($problem, $set->path);
        $this->guard(fn () => $this->state->finishRollback(false, $message));
        $this->log()->write('ROLLBACK FAILED, maintenance mode stays on: '.$message);

        return false;
    }

    private function pipeline(string $from, string $version, bool $skipTrial): void
    {
        $set = null;
        $switching = false;
        $committed = false;
        $maintenance = false;
        try {
            $this->enter('checking');
            if ($from === 'unknown') {
                throw new UpdateException('app_unreachable');
            }
            if (! InstalledVersion::isValid($from)) {
                throw new UpdateException('dev_version');
            }
            if (! Installation::repoConfigured()) {
                throw new UpdateException('repo_missing');
            }
            if (! SignatureVerifier::isConfigured()) {
                throw new UpdateException('key_missing');
            }
            $seen = $this->seen = $this->runningServices();
            $this->services();
            $previous = $this->tagPrevious($from);

            $this->enter('downloading');
            $manifest = $this->verifiedManifest($version, $from);
            $image = $manifest->image.'@'.$manifest->imageDigest;
            $this->docker(['pull', $image], 'pull', 1800);

            $this->enter('verifying');
            [, $digests] = $this->docker(['image', 'inspect', '--format', '{{json .RepoDigests}}', $image], 'image inspect');
            $digests = json_decode(trim($digests), true);
            if (! is_array($digests) || ! in_array($image, $digests, true)) {
                throw new UpdateException('docker_failed', ['command' => 'image inspect']);
            }

            $maintenance = true;
            $this->maintenanceOn();

            $this->enter('backing_up');
            $set = $this->backup($from, $version, $previous);
            $this->guard(fn () => $this->state->annotate(['backup' => $set->path]));
            $this->log()->write("backup {$set->path}");

            $this->enter('trial_migration');
            if ($skipTrial) {
                $this->log()->write('trial migration skipped on request');
            } else {
                $this->trial($image, "$set->path/db.sql");
            }

            $switching = true;
            $this->enter('switching');
            $this->writeOverride($image);
            $this->compose(['up', '-d', '--no-deps', '--no-build', '--pull', 'never', ...$this->services()], 'up', 600);

            $this->enter('migrating');
            $this->enter('health_check');
            $this->health($version, $seen);

            $committed = true;
            $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => ! $this->maintenanceWasOn]));
            $this->finishDone();
        } catch (Throwable $e) {
            $this->failReason = $e instanceof UpdateException ? $e->reason : null;
            $this->log()->write("step {$this->step} failed: ".self::describe($e));
            if (! $committed) {
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
                }

                return;
            }
            $this->log()->write('after the health check; the update stays');
        }

        if (! $this->maintenanceWasOn && $this->restoreMaintenance()) {
            $this->guard(fn () => $this->state->annotate(['maintenance_left_on' => false]));
        }
        $this->guard(fn () => $this->backups()->prune($set));
        $this->log()->write("update to $version done (image ".$this->currentOverride().')');
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

    /**
     * The image the app container runs now, tagged so a rollback can start it again (also after a later pull).
     *
     * @return array{image: string, image_id: string, image_pull: ?string}
     */
    private function tagPrevious(string $from): array
    {
        [, $ids] = $this->compose(['ps', '-q', 'app'], 'ps');
        $id = trim((string) strtok($ids, "\n"));
        if (preg_match('/^[0-9a-f]{12,64}$/D', $id) !== 1) {
            throw new UpdateException('app_unreachable');
        }
        [, $imageId] = $this->docker(['inspect', '--format', '{{.Image}}', $id], 'inspect');
        $imageId = trim($imageId);
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $imageId) !== 1) {
            throw new UpdateException('docker_failed', ['command' => 'inspect']);
        }
        $tag = self::PREVIOUS.':'.substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $from), 0, 128);
        $this->docker(['tag', $imageId, $tag], 'tag');
        [$code, $out] = $this->exec(['docker', 'image', 'inspect', '--format', '{{json .RepoDigests}}', $imageId], 'image inspect', 60, null, null, false, true);
        $digests = $code === 0 ? json_decode(trim($out), true) : null;
        $pull = array_values(array_filter(is_array($digests) ? $digests : [], fn ($d) => is_string($d) && str_contains($d, '@sha256:') && preg_match(self::IMAGE_REF, $d) === 1))[0] ?? null;

        return ['image' => $tag, 'image_id' => $imageId, 'image_pull' => $pull];
    }

    /**
     * Before a rollback stops anything: the dump is there and intact, and the previous image exists (its tag, else its
     * id re-tagged, else pulled by the recorded digest). Otherwise `rollback_unavailable` and nothing is touched.
     *
     * @return string the image to run
     */
    private function prepareRollback(BackupSet $set): string
    {
        $m = BackupSet::readManifest($set->path) ?? [];
        BackupSet::requireFormat($m);
        $dump = "$set->path/db.sql";
        if (! is_file($dump) || ! is_readable($dump) || ! hash_equals((string) ($m['files']['db.sql'] ?? ''), (string) @hash_file('sha256', $dump))) {
            throw new UpdateException('rollback_unavailable', ['detail' => __('update.error.rollback_no_dump')]);
        }
        $tag = $m['image'] ?? null;
        if (! is_string($tag) || preg_match(self::IMAGE_REF, $tag) !== 1) {
            throw new UpdateException('rollback_unavailable', ['detail' => __('update.error.rollback_no_image')]);
        }
        if ($this->imageExists($tag)) {
            return $tag;
        }
        $id = $m['image_id'] ?? null;
        $pull = $m['image_pull'] ?? null;
        if (is_string($id) && preg_match('/^sha256:[0-9a-f]{64}$/D', $id) === 1 && $this->imageExists($id)) {
            $this->docker(['tag', $id, $tag], 'tag');

            return $tag;
        }
        if (is_string($pull) && str_contains($pull, '@sha256:') && preg_match(self::IMAGE_REF, $pull) === 1
            && $this->exec(['docker', 'pull', $pull], 'pull previous', 1800, null, null, false, false)[0] === 0) {
            $this->docker(['tag', $pull, $tag], 'tag');

            return $tag;
        }

        throw new UpdateException('rollback_unavailable', ['detail' => __('update.error.rollback_no_image')]);
    }

    private function imageExists(string $ref): bool
    {
        return $this->exec(['docker', 'image', 'inspect', '--format', '{{.Id}}', $ref], 'image check', 60, null, null, false, true)[0] === 0;
    }

    /**
     * The run in $s had passed its health check: the override names the image it switched to, the app reports the
     * target version and one health check passes.
     *
     * @param  array{to: ?string, image_ref: ?string}  $s
     */
    private function healthyTarget(array $s): bool
    {
        try {
            if (! is_string($s['to']) || $s['image_ref'] === null || $this->overrideImage() !== $s['image_ref']) {
                return false;
            }
            (new HealthGate(fn () => $this->probe() ?? ['down' => ['no answer']], 1, 0))->check($s['to'], false, false, '');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Database dump (via the db container) and a manifest in the BackupManager layout, readable by the app.
     *
     * @param  array{image: string, image_id: string, image_pull: ?string}  $previous
     */
    private function backup(string $from, string $to, array $previous): BackupSet
    {
        $base = rtrim((string) config('ignis.update.backup_path'), '/\\');
        if (! is_dir($base) && ! @mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new UpdateException('backup_failed');
        }
        $this->own($base);
        $created = now()->utc();
        $name = $base.'/'.$created->format('Ymd-His').'-'.$from.'-to-'.$to;
        for ($i = 1, $dir = $name; ! @mkdir($dir, 0700); $dir = $name.'-'.++$i) {
            if ($i >= 9) {
                throw new UpdateException('backup_failed');
            }
        }
        try {
            [$code] = $this->db(self::DB_DUMP, 'mariadb-dump', stdout: "$dir/db.sql", timeout: 3600, check: false);
            $tail = (string) @file_get_contents("$dir/db.sql", false, null, max(0, (int) @filesize("$dir/db.sql") - 200));
            if ($code !== 0 || ! str_contains($tail, '-- Dump completed')) {
                throw new UpdateException('dump_failed');
            }
            $manifest = [
                'format' => BackupSet::FORMAT,
                'driver' => 'docker',
                'created_at' => $created->toIso8601ZuluString(),
                'from' => $from,
                'to' => $to,
                'database' => 'db.sql',
                'env' => false,
                'size_bytes' => (int) filesize("$dir/db.sql"),
                'files' => ['db.sql' => (string) hash_file('sha256', "$dir/db.sql")],
                'links' => [],
                ...$previous,
            ];
            if (@file_put_contents("$dir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
                throw new UpdateException('backup_failed');
            }
            foreach (["$dir/db.sql", "$dir/manifest.json"] as $file) {
                @chmod($file, 0600);
                $this->own($file);
            }
            $this->own($dir);
        } catch (Throwable $e) {
            (new Filesystem)->deleteDirectory($dir);
            throw $e instanceof UpdateException ? $e : new UpdateException('backup_failed');
        }

        return BackupSet::fromDir($dir) ?? throw new UpdateException('backup_failed');
    }

    private function trial(string $image, string $dump): void
    {
        [$code] = $this->db(self::TRIAL_CREATE, 'trial database', check: false);
        if ($code !== 0) {
            $this->log()->write('trial migration unavailable: the database '.self::TRIAL_DB.' cannot be created (CREATE/DROP rights?)');
            throw new UpdateException('trial_unavailable');
        }
        $override = $this->trialPath();
        try {
            [$code] = $this->db($this->sandboxed(self::TRIAL_LOAD), 'trial load', stdin: $dump, timeout: 3600, check: false);
            if ($code !== 0) {
                throw new UpdateException('trial_unavailable');
            }
            // exclusive create in the updater-only directory: nobody else's file is ever passed to compose
            @unlink($override);
            $h = @fopen($override, 'x');
            if ($h === false || fwrite($h, $this->overrideYaml($image, ['app'])) === false) {
                throw new UpdateException('docker_failed', ['command' => 'trial override']);
            }
            fclose($h);
            [$code] = $this->compose(['run', '--rm', '--no-deps', '-T', '-e', 'DB_DATABASE='.self::TRIAL_DB, '-e', 'AUTORUN_ENABLED=false',
                'app', 'php', 'artisan', 'migrate', '--force', '--no-interaction', '--no-ansi'], 'trial migrate', 900, extra: [$override], check: false);
            if ($code !== 0) {
                throw new UpdateException('trial_failed');
            }
            $this->log()->write('trial migration ok');
        } finally {
            $this->guard(fn () => $this->db(self::TRIAL_DROP, 'trial drop'));
            @unlink($override);
        }
    }

    private function restoreDatabase(string $dump): void
    {
        [$code, $out] = $this->db(self::DB_TABLES, 'list tables', check: false);
        if ($code !== 0) {
            throw new UpdateException('restore_db_failed');
        }
        $sql = "SET FOREIGN_KEY_CHECKS=0;\n";
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            [$name, $type] = explode("\t", $line, 2) + [1 => ''];
            if ($name !== '') {
                $sql .= ($type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ').'`'.str_replace('`', '``', $name)."`;\n";
            }
        }
        $drop = (string) tempnam(sys_get_temp_dir(), 'ignis-drop-');
        try {
            file_put_contents($drop, $sql);
            [$code] = $this->db($this->sandboxed(self::DB_CLIENT), 'drop tables', stdin: $drop, check: false);
            if ($code !== 0) {
                throw new UpdateException('restore_db_failed');
            }
            [$code] = $this->db($this->sandboxed(self::DB_CLIENT), 'restore', stdin: $dump, timeout: 3600, check: false);
            if ($code !== 0) {
                throw new UpdateException('restore_db_partial', ['path' => $dump]);
            }
        } finally {
            @unlink($drop);
        }
    }

    /** @param  list<string>  $seen  worker/scheduler that ran before: they must run again */
    private function health(string $expected, array $seen): void
    {
        $probe = function () use ($seen): array {
            $r = $this->probe() ?? ['down' => ['the application does not answer']];
            $missing = array_diff($seen, $this->runningServices());

            return $missing === [] ? $r : ['down' => array_map(fn (string $s) => "$s not running", array_values($missing))];
        };
        (new HealthGate($probe, $this->healthAttempts, $this->pauseMs))->check($expected, false, false, '');
    }

    /** @return array<string, mixed>|null the JSON of `php artisan ignis:update:health` in the app container */
    private function probe(): ?array
    {
        [$code, $out] = $this->compose(['exec', '-T', 'app', 'php', 'artisan', 'ignis:update:health', '--no-interaction', '--no-ansi'], 'health probe', 120, check: false, quiet: true);
        $lines = preg_split('/\R/', trim($out)) ?: [];
        $data = $code === 0 ? json_decode((string) end($lines), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * The services an update swaps: SERVICES plus reverb when its container exists (it has a compose profile, so
     * it is never started here when the operator did not enable it). Asked once per run, before anything is stopped.
     *
     * @return list<string>
     */
    private function services(): array
    {
        if ($this->services === null) {
            [$code, $out] = $this->compose(['ps', '-a', '-q', 'reverb'], 'ps', check: false, quiet: true);
            $this->services = $code === 0 && trim($out) !== '' ? [...self::SERVICES, 'reverb'] : self::SERVICES;
        }

        return $this->services;
    }

    /** @return list<string> worker/scheduler services that are running */
    private function runningServices(): array
    {
        [$code, $out] = $this->compose(['ps', '--status', 'running', '--services'], 'ps', check: false, quiet: true);

        return $code === 0 ? array_values(array_intersect(['worker', 'scheduler'], array_map('trim', preg_split('/\R/', $out) ?: []))) : [];
    }

    private function sandboxed(string $script): string
    {
        if ($this->sandbox === null) {
            [$code, $help] = $this->compose(['exec', '-T', 'db', 'mariadb', '--help'], 'mariadb --help', 60, check: false, quiet: true);
            $this->sandbox = $code === 0 && str_contains($help, '--sandbox');
            if (! $this->sandbox) {
                $this->log()->write('warning: mariadb client without --sandbox; dump content is trusted via volume isolation');
            }
        }

        return $this->sandbox ? $script : str_replace('mariadb --sandbox ', 'mariadb ', $script);
    }

    private function maintenanceKnownOff(): bool
    {
        [$code, $out] = $this->compose(['exec', '-T', 'app', 'sh', '-c', 'test -f storage/framework/down && echo on || echo off'], 'maintenance check', check: false, quiet: true);

        return $code === 0 && trim($out) === 'off';
    }

    private function maintenanceActive(): bool
    {
        return $this->compose(['exec', '-T', 'app', 'test', '-f', 'storage/framework/down'], 'maintenance check', check: false, quiet: true)[0] === 0;
    }

    private function maintenanceOn(): void
    {
        if (! $this->maintenanceActive()) {
            $this->compose(['exec', '-T', 'app', 'php', 'artisan', 'down', '--retry=60', '--secret='.bin2hex(random_bytes(16))], 'artisan down');
        }
        $this->maintenanceOnNow = true;
    }

    private function restoreMaintenance(): bool
    {
        if ($this->maintenanceWasOn) {
            return true;
        }
        try {
            $this->compose(['exec', '-T', 'app', 'php', 'artisan', 'up'], 'artisan up');
            $this->maintenanceOnNow = false;

            return true;
        } catch (Throwable $e) {
            $this->log()->write('maintenance mode could not be switched off (php artisan up): '.self::describe($e));

            return false;
        }
    }

    private function writeOverride(string $image): void
    {
        if (preg_match(self::IMAGE_REF, $image) !== 1) {
            throw new UpdateException('docker_failed', ['command' => 'override']);
        }
        UpdateState::writeAtomic($this->overridePath(), $this->overrideYaml($image, $this->services()));
        $this->state->annotate(['image_ref' => $image]);
        $this->log()->write("image override: $image");
    }

    /** @param list<string> $services */
    private function overrideYaml(string $image, array $services): string
    {
        $yaml = "# Written by the ignis updater (docker compose -f docker-compose.yml -f this file).\nservices:\n";
        foreach ($services as $service) {
            $yaml .= "  $service:\n    image: ".json_encode($image, JSON_UNESCAPED_SLASHES)."\n";
        }

        return $yaml;
    }

    private function currentOverride(): string
    {
        return $this->overrideImage() ?? '?';
    }

    /**
     * The image the override file names, or null when there is none. The file is only trusted when it is byte for byte
     * what overrideYaml() writes for that image and, once the state records one, for the state's image_ref; anything
     * else (another key, a volume, a second service, another image) refuses.
     *
     * @throws UpdateException
     */
    private function overrideImage(): ?string
    {
        if (! file_exists($this->overridePath())) {
            return null;
        }
        $raw = (string) @file_get_contents($this->overridePath());
        $recorded = $this->state->read()['image_ref'];
        if (preg_match('/^    image: "([^"\n]+)"$/m', $raw, $m) !== 1 || preg_match(self::IMAGE_REF, $m[1]) !== 1
            || ! in_array($raw, [$this->overrideYaml($m[1], self::SERVICES), $this->overrideYaml($m[1], [...self::SERVICES, 'reverb'])], true)
            || (is_string($recorded) && $recorded !== $m[1])) {
            $this->log()->write('the image override file was changed by someone else; refusing to use it');
            throw new UpdateException('docker_failed', ['command' => 'override']);
        }

        return $m[1];
    }

    public function overridePath(): string
    {
        return $this->privateDir().'/compose.image.yml';
    }

    private function trialPath(): string
    {
        return $this->privateDir().'/compose.trial.yml';
    }

    private function privateDir(): string
    {
        $dir = config('ignis.update.private_dir');
        $dir = is_string($dir) && $dir !== '' ? rtrim($dir, '/\\') : dirname($this->state->path()).'/private';
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /**
     * Children (docker, docker compose) get a minimal environment, not the updater's: compose interpolates the
     * operator's .env only, never UPDATER_SECRET or other variables of this container.
     *
     * @return array<string, string>
     */
    private static function childEnv(): array
    {
        $env = ['PATH' => (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'), 'HOME' => (string) (getenv('HOME') ?: '/root')];
        foreach (['DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CERT_PATH', 'DOCKER_TLS_VERIFY', 'COMPOSE_PROJECT_NAME', 'SYSTEMROOT', 'TEMP', 'TMP'] as $name) {
            if (($value = getenv($name)) !== false && $value !== '') {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $extra  more compose files
     * @return array{0: int, 1: string, 2: string}
     */
    private function compose(array $args, string $what, int $timeout = 300, ?string $stdin = null, ?string $stdout = null, array $extra = [], bool $check = true, bool $quiet = false): array
    {
        $files = array_values(array_filter(array_map('trim', explode(',', (string) config('ignis.update.compose_files')))));
        $argv = ['docker', 'compose', '--project-directory', dirname($files[0] ?? '/project/x')];
        // checked before every use (throws when it is not exactly ours)
        $override = $this->overrideImage() === null ? [] : [$this->overridePath()];
        foreach ([...$files, ...$override, ...$extra] as $file) {
            $argv[] = '-f';
            $argv[] = $file;
        }

        return $this->exec([...$argv, ...$args], $what, $timeout, $stdin, $stdout, $check, $quiet);
    }

    /**
     * @param  list<string>  $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function docker(array $args, string $what, int $timeout = 300): array
    {
        return $this->exec(['docker', ...$args], $what, $timeout, null, null, true, false);
    }

    /** @return array{0: int, 1: string, 2: string} a constant script in the db container */
    private function db(string $script, string $what, ?string $stdin = null, ?string $stdout = null, int $timeout = 600, bool $check = true): array
    {
        return $this->compose(['exec', '-T', 'db', 'sh', '-c', $script], $what, $timeout, $stdin, $stdout, check: $check);
    }

    /**
     * @param  list<string>  $argv
     * @return array{0: int, 1: string, 2: string}
     */
    private function exec(array $argv, string $what, int $timeout, ?string $stdin, ?string $stdout, bool $check, bool $quiet): array
    {
        $result = $this->run !== null ? ($this->run)($argv, $stdin, $stdout) : self::process($argv, $stdin, $stdout, $timeout);
        if (! $quiet || $result[0] !== 0) {
            $this->log()->write("$what: exit {$result[0]}".($result[0] !== 0 ? "\n".substr($result[2], -1500) : ''));
        }
        if ($check && $result[0] !== 0) {
            throw new UpdateException('docker_failed', ['command' => $what]);
        }

        return $result;
    }

    /**
     * proc_open with an argv array (no shell), stdin/stdout from/to files, killed after $timeout seconds (exit 124).
     *
     * @param  list<string>  $argv
     * @return array{0: int, 1: string, 2: string}
     */
    public static function process(array $argv, ?string $stdin, ?string $stdout, int $timeout): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $out = $stdout ?? (string) tempnam(sys_get_temp_dir(), 'ignis-out-');
        $err = (string) tempnam(sys_get_temp_dir(), 'ignis-err-');
        try {
            $p = proc_open($argv, [0 => ['file', $stdin ?? $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, null, self::childEnv());
            if (! is_resource($p)) {
                return [127, '', 'could not start '.$argv[0]];
            }
            $deadline = microtime(true) + $timeout;
            while (true) {
                $status = proc_get_status($p);
                if (! $status['running']) {
                    $code = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($p, 9);
                    $code = 124;
                    break;
                }
                usleep(100_000);
            }
            proc_close($p);

            return [$code, $stdout === null ? (string) file_get_contents($out) : '', substr((string) file_get_contents($err), -4000)];
        } finally {
            if ($stdout === null) {
                @unlink($out);
            }
            @unlink($err);
        }
    }

    private function own(string $path): void
    {
        $owner = (string) config('ignis.update.file_owner');
        if (function_exists('posix_getuid') && posix_getuid() === 0 && preg_match('/^(\d+):(\d+)$/D', $owner, $m) === 1) {
            @chown($path, (int) $m[1]);
            @chgrp($path, (int) $m[2]);
        }
    }

    private function rollbackFailed(string $problem, string $path): string
    {
        $on = $this->maintenanceOnNow ?? ! $this->maintenanceKnownOff();

        return implode(' ', array_filter([
            __('update.error.docker_rollback_failed', ['reason' => $problem]),
            __('update.error.backup_at', ['path' => $path]),
            $on ? __('update.error.maintenance_left') : null,
            __('update.error.docker_manual'),
        ]));
    }

    /** @param  array{backup: ?string, from: ?string, to: ?string, started_at: ?string}  $s */
    private function backupOfRun(array $s): ?BackupSet
    {
        // the recorded path only counts inside the backup directory (the state file is not a trust anchor)
        $base = realpath((string) config('ignis.update.backup_path'));
        $real = is_string($s['backup']) ? realpath($s['backup']) : false;
        if ($base !== false && $real !== false && dirname($real) === $base) {
            return BackupSet::fromDir($real);
        }

        return null;
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

    private function backups(): BackupManager
    {
        return new BackupManager(new SqliteDumper('updater'), null, $this->log());
    }

    private function log(): UpdateLog
    {
        return $this->log ??= new UpdateLog;
    }

    private function lockOrFail(): void
    {
        $this->maintenanceOnNow = null;
        $this->sandbox = null;
        if (! $this->lock->acquire()) {
            throw new UpdateException($this->lock->lastFailure() === 'io' ? 'lock_io' : 'busy');
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

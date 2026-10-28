<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use App\Support\Settings;
use App\System\Redact;
use App\Update\Backup\BackupManager;
use App\Update\Backup\BackupSet;
use App\Update\Installation;
use App\Update\InstalledVersion;
use App\Update\ReleaseSource;
use App\Update\UpdateException;
use App\Update\UpdateLog;
use App\Update\UpdateRunner;
use App\Update\UpdateStarter;
use App\Update\UpdateState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Update by click (spec §8). Every route is admin only. The start endpoint only validates and launches through the
 * UpdateStarter (files: the detached `ignis:update:run` process; Docker: the updater container), which takes the
 * UpdateLock and writes the state, so this controller never holds the lock. The
 * status route stays reachable in maintenance mode (bootstrap/app.php) and carries no secrets: state, a redacted log
 * tail and plain flags, never the maintenance secret.
 */
class UpdateController extends Controller
{
    private const LOG_LINES = 40;

    public function status(UpdateState $state, UpdateRunner $updater, BackupManager $backups, UpdateStarter $starter): JsonResponse
    {
        $this->authorize('system.admin');
        $s = $state->read();
        $failed = in_array($s['status'], ['failed', 'rolled_back', 'failed_rollback'], true);
        $running = $state->isRunning();

        return response()->json([
            'state' => [
                'status' => $s['status'],
                'step' => $s['step'],
                'from' => $s['from'],
                'to' => $s['to'],
                'started_at' => $s['started_at'],
                'finished_at' => $s['finished_at'],
                'reason' => $s['reason'],
                'error' => $s['error'] === null ? null : Redact::text($s['error'], 2000),
                'rolled_back' => $s['rolled_back'],
                'backup' => $s['backup'],
                'steps' => $s['steps'],
            ],
            'stale' => $running && ! $starter->ownerAlive(),
            'maintenance' => app()->isDownForMaintenance(),
            'blocker' => Installation::selfUpdateBlocker(),
            'trial_unavailable' => $failed && $s['reason'] === 'trial_unavailable',
            'preflight' => $running ? null : self::describe($updater->preflight()),
            'backup_available' => $this->backupFor($state, $backups) !== null,
            'installed' => InstalledVersion::current(),
            'driver' => Installation::driver(),
            'paths' => ['state' => $state->path(), 'log' => UpdateLog::dir()],
            'image_ref' => Installation::driver() === 'docker' && is_string($s['image_ref'])
                && preg_match('~^[a-z0-9][a-z0-9._/-]*(:[A-Za-z0-9_.-]{1,128})?(@sha256:[0-9a-f]{64})?$~D', $s['image_ref']) === 1 ? $s['image_ref'] : null,
            'log' => $this->logTail(),
        ])->header('Cache-Control', 'no-store');
    }

    public function backups(BackupManager $backups): JsonResponse
    {
        $this->authorize('system.admin');

        return response()->json(['backups' => $this->backupList($backups)])->header('Cache-Control', 'no-store');
    }

    public function check(Settings $settings): JsonResponse
    {
        $this->authorize('system.admin');
        if (! Installation::repoConfigured()) {
            return $this->refuse('repo_missing');
        }
        $code = Artisan::call('ignis:update:check');
        if ($code !== 0) {
            return response()->json(['reason' => 'check_failed', 'message' => (string) $settings->get('updates.last_error')], 422);
        }
        $latest = $settings->get('updates.latest_version');
        $url = $settings->get('updates.release_url');

        return response()->json([
            'latest_version' => is_string($latest) && $latest !== '' ? $latest : null,
            'release_url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
        ]);
    }

    public function start(Request $request, UpdateState $state, UpdateRunner $updater, ReleaseSource $source, UpdateStarter $starter): JsonResponse
    {
        $this->authorize('system.admin');
        $data = $request->validate([
            'version' => ['required', 'string', 'max:64', fn ($a, $v, $fail) => is_string($v) && InstalledVersion::isValid($v) ? null : $fail('invalid')],
            'without_trial' => ['sometimes', 'boolean'],
        ]);
        $version = $data['version'];

        if (($blocker = Installation::selfUpdateBlocker()) !== null) {
            return response()->json(['reason' => $blocker, 'message' => __("update.blocker.$blocker")], 422);
        }
        $status = $state->read()['status'];
        if (($busy = $this->busy($state, $starter)) !== null) {
            return $busy;
        }
        if ($status === 'failed_rollback') {
            return $this->refuse('rollback_needed', 409);
        }
        if ($status === 'unknown') {
            return $this->refuse('state_unreadable', 409);
        }
        if (($problem = $updater->preflight()) !== null) {
            return $this->refuse($problem->reason, 422, $problem->getMessage());
        }

        try {
            $release = $source->latest() ?? throw new UpdateException('no_release');
            $manifest = $source->fetchVerifiedManifest($release);
            if ($manifest->version !== $version) {
                throw new UpdateException('release_changed', ['version' => $manifest->version]);
            }
            $from = InstalledVersion::current();
            $starter->start($version, (bool) ($data['without_trial'] ?? false));
        } catch (UpdateException $e) {
            return $this->refuse($e->reason, $e->reason === 'busy' ? 409 : 422, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->refuse('start_failed', 500);
        }
        AuditLogger::log($request->user(), 'Update gestartet', "$from → $version", 'System', true, ['from' => $from, 'to' => $version]);

        return response()->json(['started' => true], 202);
    }

    public function rollback(Request $request, UpdateState $state, BackupManager $backups, UpdateStarter $starter, UpdateRunner $updater): JsonResponse
    {
        $this->authorize('system.admin');
        $request->validate(['confirm' => ['required', 'accepted']]);
        if (($refusal = $updater->checkoutRefusal()) !== null) {
            return $this->refuse($refusal->reason, 422, $refusal->getMessage());
        }

        if (($busy = $this->busy($state, $starter)) !== null) {
            return $busy;
        }
        $current = InstalledVersion::current();
        $set = $this->backupFor($state, $backups);
        if ($set === null) {
            return $this->refuse('no_backup', 422);
        }
        try {
            $starter->rollback();
        } catch (UpdateException $e) {
            return $this->refuse($e->reason, $e->reason === 'busy' ? 409 : 422, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->refuse('start_failed', 500);
        }
        AuditLogger::log($request->user(), 'Auf vorherigen Stand zurückgesetzt', "$current → {$set->fromVersion}", 'System', true, ['from' => $current, 'to' => $set->fromVersion]);

        return response()->json(['started' => true], 202);
    }

    private function busy(UpdateState $state, UpdateStarter $starter): ?JsonResponse
    {
        $running = $state->isRunning();
        $alive = $starter->ownerAlive();
        if (! $running && ! $alive) {
            return null;
        }

        return $this->refuse($running && ! $alive ? 'recover_needed' : 'busy', 409);
    }

    private function backupFor(UpdateState $state, BackupManager $backups): ?BackupSet
    {
        $current = InstalledVersion::current();
        $sets = $backups->list();

        return $state->read()['status'] === 'failed_rollback' ? ($sets[0] ?? null)
            : (array_values(array_filter($sets, fn (BackupSet $s) => $s->toVersion === $current))[0] ?? null);
    }

    /** @return array{reason: string, message: string}|null */
    private static function describe(?UpdateException $e): ?array
    {
        return $e === null ? null : ['reason' => $e->reason, 'message' => $e->getMessage()];
    }

    /** @return list<array{name: string, created_at: string, from: string, to: string, size_bytes: int}> */
    private function backupList(BackupManager $backups): array
    {
        return array_map(fn (BackupSet $s) => [
            'name' => basename($s->path),
            'created_at' => $s->createdAt->utc()->toIso8601ZuluString(),
            'from' => $s->fromVersion,
            'to' => $s->toVersion,
            'size_bytes' => $s->sizeBytes,
        ], $backups->list());
    }

    private function refuse(string $reason, int $status = 422, ?string $message = null): JsonResponse
    {
        return response()->json(['reason' => $reason, 'message' => $message ?? __("update.error.$reason")], $status);
    }

    /**
     * The last lines of the newest update log (names sort by time). Lines were redacted on write and are again here.
     *
     * @return list<string>
     */
    private function logTail(): array
    {
        $files = glob(UpdateLog::dir().'/update-*.log') ?: [];
        sort($files);
        $file = end($files);
        if ($file === false || ($h = @fopen($file, 'rb')) === false) {
            return [];
        }
        $size = (int) filesize($file);
        fseek($h, max(0, $size - 32768));
        $raw = (string) stream_get_contents($h);
        fclose($h);
        $lines = array_values(array_filter(explode("\n", $raw), fn (string $l) => $l !== ''));
        if ($size > 32768) {
            array_shift($lines);
        }

        return array_map(fn (string $l) => Redact::text($l, 2000), array_slice($lines, -self::LOG_LINES));
    }
}

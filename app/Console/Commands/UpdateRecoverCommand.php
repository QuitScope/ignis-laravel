<?php

namespace App\Console\Commands;

use App\Update\UpdateException;
use App\Update\UpdateRunner;
use App\Update\UpdateState;
use Illuminate\Console\Command;

/**
 * After a crash (power loss, killed process): an update that claims to run while its process is gone is rolled back
 * from the backup of that run. --force ignores a lock owner that still looks alive (Windows may reuse the pid); only
 * after confirmation or --yes, since two runs at once would break the installation.
 *
 * After a failed rollback that the admin repaired by hand, --force (confirmed, or --yes) marks it as resolved instead,
 * so updates can start again (docs/dev/update-recovery.md).
 */
class UpdateRecoverCommand extends Command
{
    protected $signature = 'ignis:update:recover {--force : take over the lock even if its owner seems alive; after a failed rollback: mark it resolved} {--yes : confirm --force without asking}';

    protected $description = 'Roll back an interrupted self-update';

    public function handle(UpdateRunner $updater, UpdateState $state): int
    {
        $force = (bool) $this->option('force');
        if ($force && $state->read()['status'] === 'failed_rollback') {
            return $this->resolve($updater);
        }
        if ($force && ! $this->option('yes')
            && ! $this->confirm('Only continue if no update process is running any more. Take over the update lock?')) {
            $this->line('Aborted.');

            return self::FAILURE;
        }
        try {
            $this->info($updater->recover($force));
        } catch (UpdateException $e) {
            $this->error($e->getMessage().($e->reason === 'busy' ? ' (if no update process runs any more: --force)' : ''));

            return self::FAILURE;
        }

        return $state->read()['status'] === 'failed_rollback' ? self::FAILURE : self::SUCCESS;
    }

    private function resolve(UpdateRunner $updater): int
    {
        if (! $this->option('yes')
            && ! $this->confirm('Only continue if code, database and .env were restored by hand (docs/dev/update-recovery.md). Mark the failed rollback as resolved?')) {
            $this->line('Aborted.');

            return self::FAILURE;
        }
        try {
            $this->info($updater->resolveFailedRollback());
        } catch (UpdateException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Update\UpdateException;
use App\Update\UpdateLog;
use App\Update\UpdateRunner;
use App\Update\UpdateState;
use Illuminate\Console\Command;

/** Back to the state before the last update (code, database, .env from its backup). Data written since then is lost. */
class UpdateRollbackCommand extends Command
{
    protected $signature = 'ignis:update:rollback {--force : confirm: the database goes back to the backup, newer data is lost}';

    protected $description = 'Reset ignis to the state before the last update';

    public function handle(UpdateRunner $updater, UpdateState $state): int
    {
        if (! $this->option('force')) {
            $this->error('This restores code, database and .env from the newest backup; everything written since the update is lost. Run again with --force.');

            return self::FAILURE;
        }
        try {
            $ok = $updater->rollbackToLatest();
        } catch (UpdateException $e) {
            (new UpdateLog)->write("rollback could not start: {$e->reason}: ".$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($ok) {
            $this->info('Rolled back.');

            return self::SUCCESS;
        }
        $this->error((string) $state->read()['error']);

        return self::FAILURE;
    }
}

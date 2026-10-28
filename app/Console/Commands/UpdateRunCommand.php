<?php

namespace App\Console\Commands;

use App\Update\UpdateException;
use App\Update\UpdateLog;
use App\Update\UpdateRunner;
use App\Update\UpdateState;
use Illuminate\Console\Command;

/** Runs a self-update (started detached by ProcessUpdateStarter, or by hand). Progress and result are in the state file. */
class UpdateRunCommand extends Command
{
    protected $signature = 'ignis:update:run {version : the release version to install} {--without-trial : skip the trial migration on a database copy}';

    protected $description = 'Install a signed ignis release (backup, switch, migrate, health check, rollback on error)';

    public function handle(UpdateRunner $updater, UpdateState $state): int
    {
        try {
            $updater->run((string) $this->argument('version'), (bool) $this->option('without-trial'));
        } catch (UpdateException $e) {
            (new UpdateLog)->write("update run could not start: {$e->reason}: ".$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $s = $state->read();
        if ($s['status'] === 'done') {
            $this->info("Updated to {$s['to']}.");

            return self::SUCCESS;
        }
        $this->error("{$s['status']} (step {$s['step']}): {$s['error']}");

        return self::FAILURE;
    }
}

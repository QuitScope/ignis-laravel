<?php

namespace Modules\Mail\Console;

use App\Models\Personnel;
use App\Modules\ModuleCommand;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Support\MailboxProvisioner;

/**
 * Brings the mailboxes in line with the records: every record runs through the same rule as on save
 * (MailboxProvisioner::sync), mailboxes of deleted records go inactive, free mailboxes are bound (never moved).
 * Idempotent. Nightly at 03:15. The dry run does the same work in a transaction and rolls it back.
 */
class BackfillMailboxesCommand extends ModuleCommand
{
    protected $signature = 'mail:backfill {--dry-run : Report what would change, write nothing}';

    protected $description = 'Create or update the mailboxes of all personnel records';

    public function module(): string
    {
        return 'mail';
    }

    public function handle(MailboxProvisioner $provisioner): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry) {
            $this->report($this->sync($provisioner), '');

            return self::SUCCESS;
        }
        // ponytail: the dry run holds one transaction over all records; fine for a few thousand records.
        DB::beginTransaction();
        try {
            $this->report($this->sync($provisioner), ' (dry run, nothing written)');
        } finally {
            DB::rollBack();
        }

        return self::SUCCESS;
    }

    /** @return array{int, int, int, int} synced, created, without mailbox, deactivated */
    private function sync(MailboxProvisioner $provisioner): array
    {
        $before = Mailbox::query()->count();
        $synced = $skipped = 0;
        foreach (Personnel::query()->orderBy('id')->pluck('id') as $id) {
            $provisioner->sync((int) $id) === null ? $skipped++ : $synced++;
        }
        $orphans = $provisioner->deactivateOrphans();

        return [$synced, Mailbox::query()->count() - $before, $skipped, $orphans];
    }

    /** @param array{int, int, int, int} $n */
    private function report(array $n, string $suffix): void
    {
        $this->info(sprintf('%d synced, %d created, %d without mailbox, %d deactivated (record deleted)%s.', ...[...$n, $suffix]));
    }
}

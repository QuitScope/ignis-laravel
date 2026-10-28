<?php

namespace Modules\Mail\Console;

use App\Modules\ModuleCommand;
use FilesystemIterator;
use Modules\Mail\Http\Controllers\InboxController;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentStorage;
use SplFileInfo;

/**
 * Nightly at 03:30 (legacy MailCleanupCommand):
 * - drafts nobody touched for 30 days, with their copy, attachments and files;
 * - attachments of sent mail whose copies are all deleted (the message row stays for the thread, as in legacy);
 * - files on the disk without a row (named like ours, older than a day) are reported, deleted only with
 *   --prune-orphans; rows whose file is missing are counted (they stay, the download answers 404).
 * Flat disk root only: subfolders and symlinks are never entered or deleted, nothing outside the root is touched.
 * Idempotent; --dry-run changes nothing.
 */
class MailCleanupCommand extends ModuleCommand
{
    private const GRACE_SECONDS = 86400;

    protected $signature = 'mail:cleanup {--dry-run : Report what would go, change nothing} {--prune-orphans : Also delete files without a row}';

    protected $description = 'Remove untouched drafts and attachments nobody holds any more; report orphaned and missing files';

    public function module(): string
    {
        return 'mail';
    }

    public function handle(AttachmentStorage $storage): int
    {
        $dry = (bool) $this->option('dry-run');
        $drafts = $removed = 0;

        $cutoff = now()->subDays(InboxController::DRAFT_DAYS);
        MailMessage::query()->where('status', 'draft')->where('updated_at', '<', $cutoff)
            ->withCount('attachments')->chunkById(200, function ($chunk) use ($dry, $storage, $cutoff, &$drafts, &$removed) {
                foreach ($chunk as $draft) {
                    if ($dry || $storage->purge($draft, $cutoff)) {
                        $drafts++;
                        $removed += (int) $draft->getAttribute('attachments_count');
                    }
                }
            });

        Attachment::query()->whereHas('message', fn ($m) => $m->where('status', 'sent')->whereDoesntHave('deliveries', fn ($d) => $d->whereNull('deleted_at')))
            ->chunkById(200, function ($chunk) use ($dry, $storage, &$removed) {
                foreach ($chunk as $attachment) {
                    $removed++;
                    $dry || $storage->delete($attachment);
                }
            });

        [$orphans, $pruned] = $this->orphans($storage, ! $dry && (bool) $this->option('prune-orphans'));

        $missing = Attachment::query()->where('missing', true)->count();
        Attachment::query()->where('missing', false)->chunkById(500, function ($chunk) use ($storage, &$missing) {
            $missing += $chunk->filter(fn (Attachment $a) => $storage->absolutePath($a) === null)->count();
        });

        $this->info(sprintf('%d draft(s) removed, %d attachment(s) removed, %d orphaned file(s) found, %d deleted, %d missing file(s)%s.',
            $drafts, $removed, $orphans, $pruned, $missing, $dry ? ' (dry run, nothing changed)' : ''));

        return self::SUCCESS;
    }

    /** @return array{int, int} orphaned files found, deleted */
    private function orphans(AttachmentStorage $storage, bool $prune): array
    {
        $root = $storage->root();
        if ($root === null) {
            return [0, 0];
        }
        $candidates = [];
        /** @var SplFileInfo $file */
        foreach (new FilesystemIterator($root) as $file) {
            if (! $file->isLink() && $file->isFile() && preg_match(AttachmentStorage::FILE_PATTERN, $file->getFilename()) === 1
                && $file->getMTime() <= time() - self::GRACE_SECONDS) {
                $candidates[$file->getFilename()] = $file->getPathname();
            }
        }
        // Read after listing: a row written meanwhile is seen. ponytail: all paths in memory, fine up to ~10^5 rows.
        $orphans = array_diff_key($candidates, array_flip(Attachment::query()->pluck('path')->all()));
        $deleted = 0;
        foreach ($orphans as $name => $path) {
            $this->line("orphan: $name");
            if ($prune && ! is_link($path) && @unlink($path)) {
                $deleted++;
            }
        }

        return [count($orphans), $deleted];
    }
}

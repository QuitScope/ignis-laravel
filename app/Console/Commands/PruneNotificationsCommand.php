<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Read notifications go after 180 days; unread ones stay. Rows of deleted accounts go regardless
 * (`notifications` has no foreign key and UserController::destroy deletes hard). Scheduled daily (routes/console.php).
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune {--days=180 : Delete notifications read more than this many days ago}';

    protected $description = 'Delete read notifications older than the given number of days';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false) {
            $this->error('--days must be a positive whole number.');

            return self::FAILURE;
        }

        $deleted = DatabaseNotification::query()->where('read_at', '<', now()->subDays($days))->delete();
        $orphans = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.id', 'notifications.notifiable_id'))
            ->delete();
        $this->info("{$deleted} read notifications and {$orphans} of deleted accounts deleted.");

        return self::SUCCESS;
    }
}

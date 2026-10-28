<?php

namespace App\Jobs;

use App\Discord\DiscordBot;
use App\Discord\DiscordException;
use App\Discord\DmRecipients;
use App\Models\User;
use App\Notifications\NotificationKinds;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One inbox entry as a Discord DM (legacy SendDiscordDmJob). The payload is two ids only: recipient, embed and token are
 * resolved when it runs, so `failed_jobs` never holds a secret, and the rule is checked again (bot switched off, kind
 * dropped or the person opted out in between: nothing goes out). Network trouble and outages are retried, a rate limit
 * waits as long as Discord asks; a refused token or a closed DM (401, 403, 50007) stays a log line.
 */
class SendDiscordDm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $userId, public string $notificationId)
    {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(DiscordBot $bot, DmRecipients $recipients, NotificationKinds $kinds): void
    {
        $user = User::find($this->userId);
        $row = $user?->notifications()->find($this->notificationId);
        $discordId = $row === null ? null : $recipients->for($user, $row->type);
        if ($discordId === null) {
            return;
        }

        $data = $row->data;
        try {
            $bot->sendDm($discordId, $bot->embed((string) ($data['title'] ?? ''), $kinds->dmMessage($row->type) ? ($data['message'] ?? null) : null, $data['link'] ?? null, $kinds->describe($row->type)['label']));
        } catch (DiscordException $e) {
            $context = ['kind' => $row->type, 'status' => $e->status, 'code' => $e->getCode()];
            if ($e->status === 429) {
                Log::info('Discord DM rate limited', $context);
                $this->release($e->retryAfter ?? $this->backoff[min($this->attempts() - 1, 2)]);

                return;
            }
            if ($e->retryable()) {
                throw $e;
            }
            Log::info('Discord DM not delivered', $context);
        }
    }
}

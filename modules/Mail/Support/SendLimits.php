<?php

namespace Modules\Mail\Support;

use Illuminate\Support\Carbon;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailMessage;

/**
 * The legacy limits of composing and sending (MailController constants) and the send pause per mailbox.
 */
final class SendLimits
{
    public const SUBJECT_MAX = 255;

    public const BODY_MAX_BYTES = 200_000;

    public const BODY_MAX_DEPTH = 32;

    public const RAW_RECIPIENTS_MAX = 100;

    public const RESOLVED_MAX = 500;

    public const ADDRESS_MAX = 254;

    /**
     * Seconds left of the send pause of $mailbox (0 = may send). Call only inside the send transaction, after its row
     * locks and before any plain read: the mailbox lock serializes parallel sends, and under REPEATABLE READ the first
     * plain read fixes the snapshot, so the latest sent_at is read only once the lock is held (legacy sendCooldown).
     * $seconds therefore comes from outside the transaction.
     */
    public static function cooldownWait(Mailbox $mailbox, int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }
        Mailbox::query()->whereKey($mailbox->id)->lockForUpdate()->first(['id']);
        $last = MailMessage::query()->where('sender_mailbox_id', $mailbox->id)->where('status', 'sent')->max('sent_at');

        return $last === null ? 0 : max(0, Carbon::parse($last)->getTimestamp() + $seconds - now()->getTimestamp());
    }
}

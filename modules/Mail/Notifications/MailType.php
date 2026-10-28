<?php

namespace Modules\Mail\Notifications;

use App\Models\User;
use App\Notifications\InboxNotification;
use App\Realtime\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Mail\Events\MessageSent;
use Modules\Mail\Support\MailboxAccess;

/**
 * The bell kind `mail` (legacy Notifications\MailType): one entry per recipient account (personal mailbox:
 * its owner, group: its active members), never for the writer. Title "Neue Mail von <mailbox>", text = subject; the
 * Discord DM leaves the subject out (`dm_message: false`). The stored link /mail/inbox/<id> (no base path) is also
 * the key: reading marks it read, trash and delete remove it, each only for the own account. Visible with mail.use and
 * an open mailbox.
 */
final class MailType
{
    public const KEY = 'mail';

    /** @return array{label: string, icon: string, module: string, ability: \Closure(User): bool, dm_message: bool} */
    public static function definition(): array
    {
        return [
            'label' => 'mail.notification.kind', 'icon' => 'mail', 'module' => 'mail', 'dm_message' => false,
            'ability' => fn (User $u) => $u->hasPermission(['admin', 'mail.use']) && MailboxAccess::accessibleIds($u) !== [],
        ];
    }

    public static function link(int $messageId): string
    {
        return "/mail/inbox/$messageId";
    }

    public static function notify(MessageSent $event): void
    {
        $mailboxes = $event->message->deliveries()->where('role', '!=', 'sender')->distinct()->pluck('mailbox_id');
        $owners = DB::table('mail_mailboxes')->whereIn('id', $mailboxes)->whereNotNull('user_id')->select('user_id');
        $members = DB::table('mail_mailbox_members')->join('mail_mailboxes', 'mail_mailboxes.id', '=', 'mail_mailbox_members.mailbox_id')
            ->whereIn('mail_mailboxes.id', $mailboxes)->where('mail_mailboxes.kind', 'group')->select('mail_mailbox_members.user_id');
        $users = User::query()->where('is_active', true)->whereKeyNot($event->by->id)
            ->where(fn ($q) => $q->whereIn('id', $owners)->orWhereIn('id', $members))->get();
        $users->each(fn (User $u) => Realtime::pulse('user.'.$u->id, 'mail'));
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new InboxNotification(self::KEY, __('mail.notification.title', ['name' => $event->from->display_name]),
            $event->message->subject, self::link($event->message->id)));
    }

    public static function markRead(User $user, int $messageId): void
    {
        $user->unreadNotifications()->where('type', self::KEY)->where('data->link', self::link($messageId))->update(['read_at' => now()]);
    }

    public static function forget(User $user, int $messageId): void
    {
        $user->notifications()->where('type', self::KEY)->where('data->link', self::link($messageId))->delete();
    }
}

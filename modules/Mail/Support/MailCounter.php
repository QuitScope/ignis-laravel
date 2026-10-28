<?php

namespace Modules\Mail\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The sidebar counter `mail`: unread inbox mails over all mailboxes the account opens (not the own sent copies). */
final class MailCounter
{
    public static function resolve(User $user): ?int
    {
        if (! $user->hasPermission(['admin', 'mail.use'])) {
            return null;
        }
        $ids = MailboxAccess::accessibleIds($user);
        if ($ids === []) {
            return null;
        }

        return DB::table('mail_deliveries')->whereIn('mailbox_id', $ids)->where('folder', 'inbox')->whereNull('read_at')
            ->whereNull('deleted_at')->where('role', '!=', 'sender')->count();
    }
}

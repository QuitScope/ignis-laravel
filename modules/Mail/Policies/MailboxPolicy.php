<?php

namespace Modules\Mail\Policies;

use App\Models\User;

/**
 * The mailbox administration (`mail.admin`): metadata only (address, name, lock, account, members). No content policy:
 * mail is reached through MailDelivery::visibleTo alone, so super admins passing here (Gate::before) open nothing.
 */
class MailboxPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission(['admin', 'mail.admin']);
    }

    public function chooseDomain(User $user): bool
    {
        return $user->hasPermission(['admin', 'mail.domain.choose']);
    }
}

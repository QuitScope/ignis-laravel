<?php

namespace Modules\Mail\Policies;

use App\Models\User;

/** The distribution lists (`mail.lists.manage`). Not a content policy: lists hold addresses and rules, no mail. */
class MailListPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission(['admin', 'mail.lists.manage']);
    }
}

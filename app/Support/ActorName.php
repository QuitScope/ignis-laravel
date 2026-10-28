<?php

namespace App\Support;

use App\Models\User;

/** Who a history or audit entry names as its author (legacy UserHelper::getCurrentUserFullnameForAction). */
class ActorName
{
    public static function for(?User $user): string
    {
        if ($user === null) {
            return 'Admin #0';
        }

        return $user->personnel->full_name ?? $user->name ?? 'Admin #'.$user->id;
    }
}

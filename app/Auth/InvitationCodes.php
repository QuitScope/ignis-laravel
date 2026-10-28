<?php

namespace App\Auth;

use App\Models\RegistrationCode;

final class InvitationCodes
{
    public function isRedeemable(string $code): bool
    {
        return RegistrationCode::query()->where('code', $code)->first()?->isRedeemable() ?? false;
    }
}

<?php

namespace Modules\Calendar\Support;

use App\Models\User;
use App\Modules\ModuleRegistry;

/**
 * Personal iCal subscription token. Only `sha256(token)` is stored in
 * users.calendar_token_hash; the plaintext leaves issue() once and is never stored, logged or audited.
 * Imported legacy tokens (48 hex) are stored as the hash of their plaintext, so old subscriptions keep working.
 */
final class IcalToken
{
    /** New tokens are 64 hex; 20..64 also admits the legacy ones (the legacy route accepted that range). */
    public const PATTERN = '[a-f0-9]{20,64}';

    public static function issue(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $user->forceFill(['calendar_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public static function revoke(User $user): void
    {
        $user->forceFill(['calendar_token_hash' => null])->save();
    }

    public static function resolve(string $token): ?User
    {
        if (preg_match('/^'.self::PATTERN.'$/D', $token) !== 1 || ! ModuleRegistry::active('calendar')) {
            return null;
        }
        // Looked up by the hash (unique index), so no plaintext comparison happens anywhere.
        $user = User::query()->where('calendar_token_hash', hash('sha256', $token))->first();

        return $user !== null && $user->is_active && $user->hasPermission(['admin', 'calendar.view', 'calendar.manage']) ? $user : null;
    }
}

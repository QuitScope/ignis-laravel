<?php

namespace App\Auth;

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;

/** What a Discord callback for a given Discord ID may do. No network, no writes. */
final class LoginDecision
{
    public const MODES = ['open', 'code', 'closed'];

    public function __construct(
        private readonly Settings $settings,
        private readonly InvitationCodes $codes,
    ) {}

    /**
     * @return array{action: 'login'|'register'|'deny', reason: ?string}
     */
    public function decide(string $discordId, ?string $code): array
    {
        $user = User::where('discord_id', $discordId)->first();

        if ($user !== null) {
            return $user->is_active ? $this->result('login') : $this->deny('deactivated');
        }

        if (! $this->isInstallAdmin($discordId)) {
            $denied = match ($this->registrationMode()) {
                'closed' => 'closed',
                'code' => match (true) {
                    $code === null || $code === '' => 'code_required',
                    ! $this->codes->isRedeemable($code) => 'code_invalid',
                    default => null,
                },
                default => null,
            };

            if ($denied !== null) {
                return $this->deny($denied);
            }
        }

        return $this->roleFor($discordId) === null ? $this->deny('no_default_role') : $this->result('register');
    }

    public function roleFor(string $discordId): ?Role
    {
        $admin = $this->isInstallAdmin($discordId) ? Role::where('name', 'Admin')->first() : null;

        return $admin ?? Role::default();
    }

    public function isInstallAdmin(string $discordId): bool
    {
        $adminId = $this->settings->get('install.admin_discord_id');

        return is_string($adminId) && $adminId !== '' && hash_equals($adminId, $discordId);
    }

    public function registrationMode(): string
    {
        $mode = $this->settings->get('auth.registration_mode', 'code');

        return in_array($mode, self::MODES, true) ? $mode : 'code';
    }

    /** @return array{action: 'deny', reason: string} */
    private function deny(string $reason): array
    {
        return ['action' => 'deny', 'reason' => $reason];
    }

    /**
     * @param  'login'|'register'  $action
     * @return array{action: 'login'|'register', reason: null}
     */
    private function result(string $action): array
    {
        return ['action' => $action, 'reason' => null];
    }
}

<?php

namespace App\Discord;

use App\Models\User;
use App\Notifications\NotificationKinds;
use App\Support\Settings;

/**
 * Who gets a notification as a Discord DM (legacy DiscordNotifier::recipients/preference), the one place of the rule:
 * bot active, kind chosen in `discord.dm_kinds` and visible to the account (module on, ability), account active, `discord_dm` on, a snowflake on the account, else on
 * the linked personnel file.
 */
final class DmRecipients
{
    public function __construct(private DiscordBot $bot, private Settings $settings, private NotificationKinds $kinds) {}

    public static function discordId(?User $user): ?string
    {
        foreach ([$user?->discord_id, $user?->personnel?->discord_id] as $id) {
            if (DiscordBot::snowflake($id)) {
                return $id;
            }
        }

        return null;
    }

    public function for(User $user, string $kind): ?string
    {
        if (! $user->is_active || ! $user->discord_dm || ! in_array($kind, (array) $this->settings->get('discord.dm_kinds'), true) || ! in_array($kind, $this->kinds->allowed($user), true) || ! $this->bot->active()) {
            return null;
        }

        return self::discordId($user);
    }

    public function preference(User $user): ?bool
    {
        return $this->bot->active() && self::discordId($user) !== null ? $user->discord_dm : null;
    }

    /**
     * Active accounts, how many of them a DM would reach (DMs on and an id), opted out.
     *
     * @return array{users: int, reachable: int, optedOut: int}
     */
    public function reach(): array
    {
        $users = User::active()->with('personnel:id,discord_id')->get(['id', 'discord_id', 'discord_dm', 'personnel_id']);

        return [
            'users' => $users->count(),
            'reachable' => $users->filter(fn (User $u) => $u->discord_dm && self::discordId($u) !== null)->count(),
            'optedOut' => $users->where('discord_dm', false)->count(),
        ];
    }
}

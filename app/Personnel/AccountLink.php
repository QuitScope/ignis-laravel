<?php

namespace App\Personnel;

use App\Models\Personnel;
use App\Models\User;
use App\Support\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Account <-> personnel record, strictly 1:1 (unique users.personnel_id; as in the legacy). The link
 * never moves: if either side is taken, link() refuses. The Discord ID is only a way to set it
 * automatically. users.name follows personnel.full_name.
 */
class AccountLink
{
    public const FREE_LIMIT = 200;

    /**
     * Links a free account to a free record. False if exactly this link exists already.
     *
     * @param  string  $how  goes into the audit entry, e.g. "von Hand"
     *
     * @throws DomainException translated reason
     */
    public static function link(User $user, Personnel $person, ?User $actor, string $how = 'von Hand'): bool
    {
        try {
            // Locks: account row first, then the record row, always in this order (no lock cycle).
            // The record lock serialises two links to the same record; the unique index is the backstop.
            $name = DB::transaction(function () use ($user, $person): ?string {
                $current = User::query()->whereKey($user->id)->lockForUpdate()->first(['id', 'personnel_id']);
                if ($current === null) {
                    throw new DomainException(__('account_link.user_missing'));
                }
                $name = Personnel::query()->whereKey($person->id)->lockForUpdate()->value('full_name');
                if (! is_string($name)) {
                    throw new DomainException(__('account_link.personnel_missing'));
                }
                if ($current->personnel_id !== null && (int) $current->personnel_id === $person->id) {
                    return null;
                }
                if ($current->personnel_id !== null) {
                    throw new DomainException(__('account_link.user_taken'));
                }
                if (User::query()->where('personnel_id', $person->id)->exists()) {
                    throw new DomainException(__('account_link.personnel_taken'));
                }
                User::query()->whereKey($user->id)->update(['personnel_id' => $person->id, 'name' => $name]);

                return $name;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(__('account_link.personnel_taken'));
        }

        if ($name === null) {
            return false;
        }

        $user->forceFill(['personnel_id' => $person->id, 'name' => $name])->syncOriginal()->unsetRelation('personnel');
        AuditLogger::log($actor, 'Mitarbeiter verknüpft [Benutzer-ID: '.$user->id.']', $name.' (Akten-ID '.$person->id.'), '.$how, 'Benutzer', true,
            ['user_id' => $user->id, 'personnel_id' => $person->id]);

        return true;
    }

    public static function unlink(User $user, ?User $actor): void
    {
        $personnelId = User::query()->whereKey($user->id)->value('personnel_id');
        if ($personnelId === null || User::query()->whereKey($user->id)->where('personnel_id', $personnelId)->update(['personnel_id' => null]) !== 1) {
            return;
        }

        $user->forceFill(['personnel_id' => null])->syncOriginal()->unsetRelation('personnel');
        AuditLogger::log($actor, 'Mitarbeiterverknüpfung gelöst [Benutzer-ID: '.$user->id.']', 'Akten-ID '.$personnelId, 'Benutzer', true,
            ['user_id' => $user->id, 'personnel_id' => (int) $personnelId]);
    }

    public static function linkInvited(User $user, ?int $personnelId): void
    {
        if ($personnelId === null) {
            return;
        }

        try {
            $person = Personnel::query()->find($personnelId) ?? throw new DomainException(__('account_link.personnel_missing'));
            self::link($user, $person, $user, 'über die Einladung');
        } catch (Throwable $e) {
            if (! $e instanceof DomainException) {
                Log::warning('Invitation link failed: '.$e::class);
            }
            AuditLogger::log($user, 'Einladung ohne Mitarbeiterverknüpfung eingelöst [Benutzer-ID: '.$user->id.']',
                ($e instanceof DomainException ? $e->getMessage() : __('account_link.failed')).' (Akten-ID '.$personnelId.')', 'Benutzer', true,
                ['user_id' => $user->id, 'personnel_id' => $personnelId]);
        }
    }

    public static function autoLinkByDiscord(string $discordId): ?User
    {
        if ($discordId === '') {
            return null;
        }

        try {
            $users = User::query()->where('discord_id', $discordId)->where('is_active', true)->limit(2)->get();
            $people = Personnel::query()->where('discord_id', $discordId)->limit(2)->get(['id']);
            // Super admins are linked by hand only: nobody may unlink them in the UI,
            // and a record's discord_id must not rename or bind them.
            if ($users->count() !== 1 || $people->count() !== 1 || $users[0]->personnel_id !== null || $users[0]->is_super_admin) {
                return null;
            }

            self::link($users[0], $people[0], $users[0], 'automatisch über die Discord-ID');

            return $users[0];
        } catch (DomainException) {
            return null;
        } catch (Throwable $e) {
            Log::warning('Automatic personnel link failed: '.$e::class);

            return null;
        }
    }

    /** @return Collection<int, Personnel> */
    public static function freePersonnel(): Collection
    {
        return Personnel::query()->whereDoesntHave('user')->orderBy('full_name')->orderBy('id')
            ->limit(self::FREE_LIMIT)->get(['id', 'full_name', 'service_number']);
    }
}

<?php

namespace Modules\Mail\Support;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Modules\Mail\Models\Mailbox;

/**
 * Which mailboxes an account opens (legacy Mailbox::current/accessible/selected/autoBind). Ownership is `user_id`
 * alone; the record link (`users.personnel_id`) only decides which account a still free mailbox gets, once. No
 * permission widens this: `admin`, `mail.admin` and super admins open nothing extra. Every content query goes
 * through MailDelivery::visibleTo, which builds on accessible().
 */
final class MailboxAccess
{
    /** Session key of the mailbox chosen in the mail UI (display only, never a right). */
    public const SESSION_KEY = 'mail.mailbox_id';

    public static function current(User $user): ?Mailbox
    {
        $mailbox = self::ownedBy($user);

        return $mailbox !== null && $mailbox->isOpen() ? $mailbox : null;
    }

    /**
     * The own mailbox (current()), then the open group mailboxes the account is a member of, by name.
     *
     * @return Collection<int, Mailbox>
     */
    public static function accessible(User $user): Collection
    {
        $memo = app(MailboxAccessMemo::class);

        return $memo->accessible[$user->id] ??= self::resolve($user);
    }

    public static function forget(): void
    {
        app(MailboxAccessMemo::class)->accessible = [];
    }

    /** @return Collection<int, Mailbox> */
    private static function resolve(User $user): Collection
    {
        $groups = Mailbox::query()->where('kind', Mailbox::GROUP)->where('is_active', true)->where('is_locked', false)
            ->whereIn('id', fn ($q) => $q->select('mailbox_id')->from('mail_mailbox_members')->where('user_id', $user->id))
            ->orderBy('display_name')->orderBy('id')->get();
        $own = self::current($user);

        return $own === null ? $groups->values() : $groups->prepend($own)->values();
    }

    /** @return list<int> */
    public static function accessibleIds(User $user): array
    {
        return self::accessible($user)->map(fn (Mailbox $m) => $m->id)->values()->all();
    }

    public static function selected(User $user, ?int $requested = null): ?Mailbox
    {
        $accessible = self::accessible($user);
        $remembered = session()->get(self::SESSION_KEY);
        $wanted = $requested ?? (is_int($remembered) ? $remembered : null);
        $hit = $accessible->first(fn (Mailbox $m) => $m->id === $wanted);

        return $hit ?? ($requested !== null ? null : $accessible->first());
    }

    public static function remember(Mailbox $mailbox): void
    {
        session()->put(self::SESSION_KEY, $mailbox->id);
    }

    public static function ownedBy(User $user): ?Mailbox
    {
        $bound = Mailbox::query()->where('user_id', $user->id)->first();
        if ($bound !== null || $user->personnel_id === null) {
            return $bound;
        }
        $free = Mailbox::query()->where('personnel_id', $user->personnel_id)->whereNull('user_id')->first();

        return $free !== null && self::autoBind($free) === $user->id ? $free : null;
    }

    public static function bindCandidate(Mailbox $mailbox): ?int
    {
        if ($mailbox->personnel_id === null) {
            return null;
        }
        $accounts = User::query()->where('is_active', true)->where('personnel_id', $mailbox->personnel_id)->pluck('id');
        if ($accounts->count() !== 1 || Mailbox::query()->where('user_id', $accounts[0])->whereKeyNot($mailbox->id)->exists()) {
            return null;
        }

        return (int) $accounts[0];
    }

    public static function autoBind(Mailbox $mailbox): ?int
    {
        if ($mailbox->user_id !== null || $mailbox->personnel_id === null) {
            return $mailbox->user_id;
        }
        $account = self::bindCandidate($mailbox);
        if ($account === null) {
            return null;
        }
        try {
            $bound = Mailbox::query()->whereKey($mailbox->id)->whereNull('user_id')->update(['user_id' => $account]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        if ($bound !== 1) {
            return null;
        }
        $mailbox->user_id = $account;
        $mailbox->syncOriginalAttribute('user_id');
        self::forget();

        return $mailbox->user_id;
    }
}

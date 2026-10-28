<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Support\AddressRules;
use Modules\Mail\Support\MailboxAccess;
use Throwable;

/**
 * The mailbox administration under /settings/mail/mailboxes (mail.admin, route middleware; legacy MailAdminController).
 * It manages mailboxes, never their content: no page or action reads a message, a subject, a delivery or even a count
 * of them. Rules:
 * - an address change keeps the old address reserved for the mailbox, as an alias that still delivers;
 *   the domain changes only with mail.domain.choose (a posted domain is ignored otherwise);
 * - the own mailbox is changed, locked, unlocked and reassigned by somebody else, never by oneself;
 * - an account is assigned only to a matching one (linked to the record, active, without a mailbox), never to oneself;
 *   the previous account gets a `system` notification;
 * - group mailboxes: created here with name and address; members are added and removed by somebody else (four eyes);
 *   they are locked, never deleted (they are the sender of mail in other mailboxes).
 * The lock is never undone automatically. The audit (module Mail) gets IDs and addresses, never content.
 */
class MailAdminController extends Controller
{
    private const MODULE = 'Mail';

    private const STATES = ['active', 'inactive', 'locked'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'kind' => ['nullable', 'string', Rule::in([Mailbox::PERSONAL, Mailbox::GROUP])],
            'state' => ['nullable', 'string', Rule::in(self::STATES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $this->user($request);
        $q = trim((string) ($filters['q'] ?? ''));

        $mailboxes = Mailbox::query()->with(['personnel:id,full_name', 'user:id,name,username'])->withCount('members')
            ->when($filters['kind'] ?? null, fn (Builder $b, string $kind) => $b->where('kind', $kind))
            ->when($filters['state'] ?? null, fn (Builder $b, string $state) => match ($state) {
                'locked' => $b->where('is_locked', true),
                'inactive' => $b->where('is_active', false)->where('is_locked', false),
                default => $b->where('is_active', true)->where('is_locked', false),
            })
            ->when($q !== '', fn (Builder $b) => SqlLike::anyOf($b, ['address', 'display_name'], $q))
            ->orderBy('display_name')->orderBy('id')->paginate(50)->withQueryString()
            ->through(fn (Mailbox $m) => [
                'id' => $m->id, 'kind' => $m->kind, 'address' => $m->address, 'display_name' => $m->display_name,
                'is_active' => $m->is_active, 'is_locked' => $m->is_locked, 'members_count' => $m->members_count,
                'owner' => $m->personnel?->full_name, 'account' => $m->user === null ? null : self::name($m->user),
                'is_own' => self::isOwn($m, $user),
            ]);

        return Inertia::render('Mail/Admin/Mailboxes', [
            'mailboxes' => $mailboxes,
            'filters' => ['kind' => $filters['kind'] ?? null, 'state' => $filters['state'] ?? null, 'q' => $q],
        ]);
    }

    public function createGroup(Request $request): Response
    {
        return Inertia::render('Mail/Admin/GroupCreate', [
            'defaultDomain' => AddressRules::defaultDomain(),
            'domains' => AddressRules::allowedDomains(),
            'canChooseDomain' => $this->user($request)->can('chooseDomain', Mailbox::class),
        ]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'local' => ['required', 'string', 'max:64'],
            'domain' => ['nullable', 'string', 'max:100'],
        ]);
        $domain = $this->user($request)->can('chooseDomain', Mailbox::class) && ($data['domain'] ?? '') !== ''
            ? AddressRules::normalize($data['domain']) : AddressRules::defaultDomain();
        $address = AddressRules::normalize($data['local']).'@'.$domain;

        $mailbox = $this->writeAddress($address, fn () => Mailbox::query()->forceCreate([
            'kind' => Mailbox::GROUP, 'address' => $address, 'display_name' => $data['name'], 'domain' => $domain,
        ]), AddressRules::validate(...));
        AuditLogger::log($request->user(), 'Gruppenpostfach angelegt', $address, self::MODULE, true, ['mailbox_id' => $mailbox->id, 'address' => $address]);

        return redirect("/settings/mail/mailboxes/{$mailbox->id}/edit")->with('status', 'mail.admin.group_created');
    }

    public function edit(Request $request, Mailbox $mailbox): Response
    {
        $user = $this->user($request);
        $domains = AddressRules::allowedDomains();
        if (! in_array($mailbox->domain, $domains, true)) {
            array_unshift($domains, $mailbox->domain);
        }
        $members = $mailbox->isGroup() ? $mailbox->members()->orderBy('name')->orderBy('username')->get() : collect();

        return Inertia::render('Mail/Admin/Edit', [
            'mailbox' => [
                'id' => $mailbox->id, 'kind' => $mailbox->kind, 'address' => $mailbox->address, 'local' => strstr($mailbox->address, '@', true) ?: '',
                'domain' => $mailbox->domain, 'display_name' => $mailbox->display_name, 'is_active' => $mailbox->is_active, 'is_locked' => $mailbox->is_locked,
                'owner' => $mailbox->personnel?->full_name, 'account' => $mailbox->user === null ? null : self::name($mailbox->user),
            ],
            'isOwn' => self::isOwn($mailbox, $user),
            'canChooseDomain' => $user->can('chooseDomain', Mailbox::class),
            'domains' => $domains,
            'members' => $members->map(fn (User $u) => ['id' => $u->id, 'name' => self::name($u), 'is_active' => $u->is_active])->values()->all(),
            'memberCandidates' => $mailbox->isGroup()
                ? User::query()->where('is_active', true)->whereKeyNot($user->id)->whereNotIn('id', $members->pluck('id'))
                    ->orderBy('name')->orderBy('username')->get(['id', 'name', 'username'])->map(fn (User $u) => ['id' => $u->id, 'name' => self::name($u)])->all()
                : [],
            'releaseBlocked' => ! $mailbox->isGroup() && $mailbox->user_id !== null && MailboxAccess::bindCandidate($mailbox) !== null,
            'accountCandidates' => $this->eligibleAccounts($mailbox, $user)->map(fn (User $u) => ['id' => $u->id, 'name' => self::name($u)])->values()->all(),
        ]);
    }

    /** PUT …/{mailbox}: the address (domain only with mail.domain.choose); the old one stays reserved as an alias. */
    public function updateAddress(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $user = $this->user($request);
        $this->refuseOwn($mailbox, $user);
        $data = $request->validate(['local' => ['required', 'string', 'max:64'], 'domain' => ['nullable', 'string', 'max:100']]);
        $domain = $user->can('chooseDomain', Mailbox::class) && ($data['domain'] ?? '') !== '' ? AddressRules::normalize($data['domain']) : $mailbox->domain;
        $address = AddressRules::normalize($data['local']).'@'.$domain;
        if ($address === $mailbox->address) {
            return redirect('/settings/mail/mailboxes')->with('status', 'mail.admin.unchanged');
        }

        $before = $mailbox->address;
        $this->writeAddress($address, function () use ($mailbox, $address, $domain, $before) {
            $mailbox->forceFill(['address' => $address, 'domain' => $domain])->save();
            AddressRules::reserve($before, $mailbox->id);
            DB::table('mail_address_history')->where('address', $address)->where('mailbox_id', $mailbox->id)->delete();

            return $mailbox;
        }, fn (string $a) => AddressRules::validate($a, $mailbox->id, null, $mailbox->domain));

        AuditLogger::log($user, $domain !== substr((string) strrchr($before, '@'), 1) ? 'Postfach-Domain geändert' : 'Postfach-Adresse geändert',
            "$before → $address", self::MODULE, true, ['mailbox_id' => $mailbox->id, 'from' => $before, 'to' => $address]);

        return redirect('/settings/mail/mailboxes')->with('status', 'mail.admin.address_saved')->with('status_params', ['address' => $address]);
    }

    /** PATCH …/{mailbox}/name: the name of a group mailbox (a personal one follows its record). */
    public function rename(Request $request, Mailbox $mailbox): RedirectResponse
    {
        abort_unless($mailbox->isGroup(), 404);
        $name = $request->validate(['name' => ['required', 'string', 'max:150']])['name'];
        $back = redirect("/settings/mail/mailboxes/{$mailbox->id}/edit");
        if ($name === $mailbox->display_name) {
            return $back->with('status', 'mail.admin.unchanged');
        }
        $before = $mailbox->display_name;
        $mailbox->forceFill(['display_name' => $name])->save();
        AuditLogger::log($request->user(), 'Gruppenpostfach umbenannt', "$before → $name", self::MODULE, true, ['mailbox_id' => $mailbox->id, 'from' => $before, 'to' => $name]);

        return $back->with('status', 'mail.admin.renamed');
    }

    /** PATCH …/{mailbox}/lock {locked}: never the own mailbox; the lock is never undone automatically. */
    public function lock(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $this->refuseOwn($mailbox, $this->user($request));
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];
        $locked = filter_var($locked, FILTER_VALIDATE_BOOLEAN);
        if ($mailbox->is_locked !== $locked) {
            $mailbox->forceFill(['is_locked' => $locked])->save();
            AuditLogger::log($request->user(), $locked ? 'Postfach gesperrt' : 'Postfach entsperrt', $mailbox->address, self::MODULE, true,
                ['mailbox_id' => $mailbox->id, 'address' => $mailbox->address]);
        }

        return redirect('/settings/mail/mailboxes')->with('status', match (true) {
            $locked => 'mail.admin.locked',
            $mailbox->is_active || $mailbox->isGroup() => 'mail.admin.unlocked',
            default => 'mail.admin.unlocked_inactive',
        })->with('status_params', ['address' => $mailbox->address]);
    }

    /** PUT …/{mailbox}/account {user_id}: assign the account of a personal mailbox. */
    public function assignAccount(Request $request, Mailbox $mailbox): RedirectResponse
    {
        // An account must be named: a stray submit never releases (that is DELETE …/account).
        $this->guardAccount($request, $mailbox);

        return $this->setAccount($request, $mailbox, (int) $request->validate(['user_id' => ['required', 'integer']])['user_id']);
    }

    /** DELETE …/{mailbox}/account: release the account explicitly (autoBind may bind a still linked one again). */
    public function releaseAccount(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $this->guardAccount($request, $mailbox);
        // A release autoBind would undo (or hand to another account of the record) at once is refused: the audit and the
        // notification would be false. The record link goes first (user administration), or the mailbox is locked.
        if ($mailbox->user_id !== null && MailboxAccess::bindCandidate($mailbox) !== null) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.release_blocked')]);
        }

        return $this->setAccount($request, $mailbox, null);
    }

    private function guardAccount(Request $request, Mailbox $mailbox): void
    {
        if ($mailbox->isGroup()) {
            throw ValidationException::withMessages(['mailbox' => __('mail.admin.group_has_no_account')]);
        }
        $this->refuseOwn($mailbox, $this->user($request));
    }

    private function setAccount(Request $request, Mailbox $mailbox, ?int $target): RedirectResponse
    {
        $user = $this->user($request);
        $back = redirect("/settings/mail/mailboxes/{$mailbox->id}/edit");
        if ($target === $mailbox->user_id) {
            return $back->with('status', 'mail.admin.unchanged');
        }
        if ($target === $user->id) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.not_to_oneself')]);
        }
        if ($target !== null && ! $this->eligibleAccounts($mailbox, $user)->contains('id', $target)) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.account_not_eligible')]);
        }

        $before = $mailbox->user_id;
        try {
            $mailbox->forceFill(['user_id' => $target])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.account_not_eligible')]);
        }
        AuditLogger::log($user, $target === null ? 'Postfach-Konto gelöst' : 'Postfach-Konto zugeordnet',
            "Postfach #{$mailbox->id}: Konto #".($before ?? '-').' → #'.($target ?? '-'), self::MODULE, true,
            ['mailbox_id' => $mailbox->id, 'from' => $before, 'to' => $target]);
        if ($before !== null) {
            try {
                User::query()->find($before)?->notify(new InboxNotification('system', __('mail.admin.moved_title'), __('mail.admin.moved_message', ['address' => $mailbox->address]), null));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $back->with('status', $target === null ? 'mail.admin.account_released' : 'mail.admin.account_assigned');
    }

    /** POST …/{mailbox}/members {user_id}: an active account, never oneself (four eyes). */
    public function addMember(Request $request, Mailbox $mailbox): RedirectResponse
    {
        abort_unless($mailbox->isGroup(), 404);
        $id = (int) $request->validate(['user_id' => ['required', 'integer']])['user_id'];
        $member = User::query()->where('is_active', true)->find($id);
        if ($member === null) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.member_invalid')]);
        }
        $error = match (true) {
            $member->id === $this->user($request)->id => __('mail.admin.member_not_oneself'),
            $mailbox->hasMember($member) => __('mail.admin.member_exists', ['name' => self::name($member)]),
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['user_id' => $error]);
        }
        try {
            $mailbox->members()->attach($member->id);
            MailboxAccess::forget();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.member_exists', ['name' => self::name($member)])]);
        }
        AuditLogger::log($request->user(), 'Gruppenpostfach: Mitglied aufgenommen', "{$mailbox->address}: Konto #{$member->id}", self::MODULE, true,
            ['mailbox_id' => $mailbox->id, 'user_id' => $member->id]);

        return redirect("/settings/mail/mailboxes/{$mailbox->id}/edit")->with('status', 'mail.admin.member_added')->with('status_params', ['name' => self::name($member)]);
    }

    /** DELETE …/{mailbox}/members/{user}: never oneself either (four eyes). */
    public function removeMember(Request $request, Mailbox $mailbox, User $user): RedirectResponse
    {
        abort_unless($mailbox->isGroup(), 404);
        if ($user->id === $this->user($request)->id) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.member_not_oneself')]);
        }
        $detached = $mailbox->members()->detach($user->id);
        MailboxAccess::forget();
        if ($detached === 0) {
            throw ValidationException::withMessages(['user_id' => __('mail.admin.member_missing')]);
        }
        AuditLogger::log($request->user(), 'Gruppenpostfach: Mitglied entfernt', "{$mailbox->address}: Konto #{$user->id}", self::MODULE, true,
            ['mailbox_id' => $mailbox->id, 'user_id' => $user->id]);

        return redirect("/settings/mail/mailboxes/{$mailbox->id}/edit")->with('status', 'mail.admin.member_removed')->with('status_params', ['name' => self::name($user)]);
    }

    /**
     * Writes an address: the check runs inside the transaction, a parallel insert of the same address still meets the
     * unique index, and both end as a validation error on `local`, never as a database error.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @param  callable(string): ?string  $check
     * @return T
     */
    private function writeAddress(string $address, callable $write, callable $check): mixed
    {
        try {
            return DB::transaction(function () use ($address, $write, $check) {
                $error = $check($address);
                if ($error !== null) {
                    throw ValidationException::withMessages(['local' => $error]);
                }

                return $write();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['local' => __('mail.address.taken', ['address' => $address])]);
        }
    }

    /**
     * Accounts the mailbox may belong to: active, linked to its record, without a mailbox, neither the own account
     * nor the current one.
     *
     * @return Collection<int, User>
     */
    private function eligibleAccounts(Mailbox $mailbox, User $me): Collection
    {
        if ($mailbox->isGroup() || $mailbox->personnel_id === null) {
            return collect();
        }

        return User::query()->where('is_active', true)->where('personnel_id', $mailbox->personnel_id)
            ->whereKeyNot($me->id)->when($mailbox->user_id !== null, fn ($q) => $q->whereKeyNot($mailbox->user_id))
            ->whereNotIn('id', Mailbox::query()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);
    }

    private static function isOwn(Mailbox $mailbox, User $user): bool
    {
        return $mailbox->user_id === $user->id
            || ($mailbox->user_id === null && $user->personnel_id !== null && $mailbox->personnel_id === $user->personnel_id);
    }

    private function refuseOwn(Mailbox $mailbox, User $user): void
    {
        if (self::isOwn($mailbox, $user)) {
            throw ValidationException::withMessages(['mailbox' => __('mail.admin.own_mailbox')]);
        }
    }

    private static function name(User $user): string
    {
        return (string) ($user->name ?: $user->username);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

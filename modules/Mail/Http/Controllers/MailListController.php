<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Specialty;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Mail\Content\MailContentValidator;
use Modules\Mail\Http\Requests\MailListRequest;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;
use Modules\Mail\Support\AddressRules;

/**
 * Distribution lists under /mail/lists (mail.lists.manage, route middleware; legacy MailListController):
 * - static: fixed member mailboxes; only deliverable ones join, members stay when their mailbox is locked later;
 * - dynamic: a rule of roles, ranks, EMS/fire qualifications and specialties (IDs of this instance, OR), resolved when
 *   sending (RecipientResolver); lists never contain lists.
 * The address is unique over mailboxes, lists and former addresses (checked inside the write, the unique index answers
 * a lost race). The audit gets the list's address, name, kind, senders and the IDs that came and went, no member names
 * and never mail content.
 */
class MailListController extends Controller
{
    public const RULE_KEYS = ['role', 'rank', 'ems_qualification', 'fire_qualification', 'specialty'];

    private const MODULE = 'Mail';

    public function index(): Response
    {
        $names = collect($this->criteria())->map(fn ($options) => collect($options)->pluck('name', 'id'));

        return Inertia::render('Mail/Lists/Index', [
            'lists' => MailList::query()->withCount('mailboxes')->orderBy('name')->orderBy('id')->get()->map(fn (MailList $l) => [
                'id' => $l->id, 'name' => $l->name, 'address' => $l->address, 'kind' => $l->kind, 'senders' => $l->senders,
                'members_count' => $l->mailboxes_count,
                'rule' => collect(self::RULE_KEYS)->mapWithKeys(fn (string $k) => [$k => collect($l->rule[$k] ?? [])
                    ->map(fn ($id) => $names[$k][(int) $id] ?? null)->filter()->values()->all()])->filter()->all(),
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(MailList $list): Response
    {
        return $this->form($list);
    }

    public function store(MailListRequest $request): RedirectResponse
    {
        return $this->save($request, new MailList);
    }

    public function update(MailListRequest $request, MailList $list): RedirectResponse
    {
        return $this->save($request, $list);
    }

    public function destroy(Request $request, MailList $list): RedirectResponse
    {
        $members = $this->memberIds($list);
        $list->delete();
        AuditLogger::log($request->user(), 'Verteiler gelöscht', $list->address, self::MODULE, true,
            ['list_id' => $list->id, 'address' => $list->address, 'name' => $list->name, 'kind' => $list->kind] + self::changes($members, [], $list->rule ?? [], []));

        return redirect('/mail/lists')->with('status', 'mail.lists.deleted');
    }

    private function save(MailListRequest $request, MailList $list): RedirectResponse
    {
        $kind = (string) $request->string('kind');
        $address = AddressRules::normalize((string) $request->string('local')).'@'.AddressRules::normalize((string) $request->string('domain'));
        $rule = [];
        foreach ($this->criteriaQueries() as $key => $query) {
            $rule[$key] = $query->whereKey($request->ids("rule.$key"))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        if ($kind === 'dynamic' && array_merge(...array_values($rule)) === []) {
            throw ValidationException::withMessages(['rule' => __('mail.lists.rule_required')]);
        }

        $isNew = ! $list->exists;
        $before = $isNew ? [] : $this->memberIds($list);
        $ruleBefore = $list->rule ?? [];
        $sendersBefore = $list->senders;
        $wanted = $kind === 'static' ? $request->ids('members') : [];
        $usable = Mailbox::query()->deliverable()->whereKey($wanted)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $members = array_values(array_filter($wanted, fn (int $id) => in_array($id, $before, true) || in_array($id, $usable, true)));
        $keepDomain = $isNew ? null : substr((string) strrchr($list->address, '@'), 1);

        try {
            DB::transaction(function () use ($request, $list, $address, $kind, $rule, $members, $keepDomain) {
                $error = AddressRules::validate($address, null, $list->exists ? $list->id : null, $keepDomain);
                if ($error !== null) {
                    throw ValidationException::withMessages(['local' => $error]);
                }
                $list->forceFill([
                    'name' => (string) $request->string('name'), 'address' => $address, 'kind' => $kind,
                    'senders' => (string) $request->string('senders'), 'rule' => $kind === 'dynamic' ? $rule : null,
                ])->save();
                $list->mailboxes()->sync($members);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['local' => __('mail.address.taken', ['address' => $address])]);
        }

        $context = ['list_id' => $list->id, 'address' => $list->address, 'name' => $list->name, 'kind' => $list->kind, 'senders' => $list->senders]
            + self::changes($before, $members, $ruleBefore, $list->rule ?? []);
        if (! $isNew && $sendersBefore !== $list->senders) {
            $context['senders_changed'] = [$sendersBefore, $list->senders];
        }
        AuditLogger::log($request->user(), $isNew ? 'Verteiler angelegt' : 'Verteiler bearbeitet', $list->address, self::MODULE, true, $context);

        return redirect('/mail/lists')->with('status', $isNew ? 'mail.lists.created' : 'mail.lists.saved');
    }

    private function form(?MailList $list): Response
    {
        $members = $list === null ? [] : $this->memberIds($list);
        $domains = AddressRules::allowedDomains();
        $domain = $list === null ? AddressRules::defaultDomain() : substr((string) strrchr($list->address, '@'), 1);
        if (! in_array($domain, $domains, true)) {
            array_unshift($domains, $domain);
        }

        return Inertia::render('Mail/Lists/Edit', [
            'list' => $list === null ? null : [
                'id' => $list->id, 'name' => $list->name, 'local' => strstr($list->address, '@', true) ?: '', 'domain' => $domain,
                'kind' => $list->kind, 'senders' => $list->senders, 'members' => $members,
                'rule' => collect(self::RULE_KEYS)->mapWithKeys(fn (string $k) => [$k => array_map('intval', (array) ($list->rule[$k] ?? []))])->all(),
            ],
            'defaultDomain' => $domain,
            'domains' => $domains,
            'mailboxes' => Mailbox::query()->where(fn (Builder $q) => $q->where('is_active', true)->where('is_locked', false))->orWhereIn('id', $members)
                ->orderBy('display_name')->orderBy('id')->get(['id', 'kind', 'address', 'display_name', 'is_active', 'is_locked'])
                ->map(fn (Mailbox $m) => ['id' => $m->id, 'kind' => $m->kind, 'address' => $m->address, 'name' => $m->display_name, 'deliverable' => $m->isOpen()])->all(),
            'criteria' => $this->criteria(),
        ]);
    }

    /** @return array<string, list<array{id: int, name: string, badge?: ?string}>> options of the rule editor */
    private function criteria(): array
    {
        $out = [];
        foreach ($this->criteriaQueries() as $key => $query) {
            $out[$key] = $query->get()->map(fn ($row) => ['id' => (int) $row->getKey(), 'name' => (string) $row->getAttribute('name')]
                + ($row instanceof Rank ? ['badge' => MailContentValidator::validImageSrc($row->badge) ? $row->badge : null] : []))->all();
        }

        return $out;
    }

    /** @return array<string, Builder<*>> */
    private function criteriaQueries(): array
    {
        return [
            'role' => Role::query()->orderBy('priority')->orderBy('name'),
            'rank' => Rank::query()->orderBy('priority')->orderBy('name'),
            'ems_qualification' => EmsQualification::query()->orderBy('priority')->orderBy('name'),
            'fire_qualification' => FireQualification::query()->orderBy('priority')->orderBy('name'),
            'specialty' => Specialty::query()->orderBy('number')->orderBy('name'),
        ];
    }

    /** @return list<int> */
    private function memberIds(MailList $list): array
    {
        return $list->mailboxes()->orderBy('mail_mailboxes.id')->pluck('mail_mailboxes.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The difference of two states as IDs only: `members_added`, `rank_removed`, …
     *
     * @param  list<int>  $membersBefore
     * @param  list<int>  $membersAfter
     * @param  array<string, mixed>  $ruleBefore
     * @param  array<string, mixed>  $ruleAfter
     * @return array<string, list<int>>
     */
    private static function changes(array $membersBefore, array $membersAfter, array $ruleBefore, array $ruleAfter): array
    {
        $pairs = ['members' => [$membersBefore, $membersAfter]];
        foreach (self::RULE_KEYS as $key) {
            $pairs[$key] = [array_map('intval', (array) ($ruleBefore[$key] ?? [])), array_map('intval', (array) ($ruleAfter[$key] ?? []))];
        }
        $changes = [];
        foreach ($pairs as $key => [$before, $after]) {
            if (($added = array_values(array_diff($after, $before))) !== []) {
                $changes[$key.'_added'] = $added;
            }
            if (($removed = array_values(array_diff($before, $after))) !== []) {
                $changes[$key.'_removed'] = $removed;
            }
        }

        return $changes;
    }
}

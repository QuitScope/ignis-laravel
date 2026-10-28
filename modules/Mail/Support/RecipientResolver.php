<?php

namespace Modules\Mail\Support;

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\AddressHistory;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;

/**
 * Rebuild of the package RecipientResolver (unverified there, fixed here): an address is a mailbox (its
 * current address or a former one), else a list (static members, or the dynamic rule: any role of the bound
 * account, rank, EMS or fire qualification of the record, specialty over the pivot), else unknown. Only
 * deliverable mailboxes count (active, not locked), also inside lists; lists never contain lists. Each mailbox gets one
 * copy with its highest role, to over cc over bcc. A fixed number of queries per header, none per address.
 */
final class RecipientResolver
{
    public const ROLES = ['to', 'cc', 'bcc'];

    /** @param array<string, list<string>> $header to/cc/bcc address lists */
    public function resolve(array $header): ResolvedRecipients
    {
        $byRole = [];
        foreach (self::ROLES as $role) {
            $byRole[$role] = array_values(array_unique(array_map(AddressRules::normalize(...), $header[$role] ?? [])));
        }
        $all = array_values(array_unique(array_merge(...array_values($byRole))));
        if ($all === []) {
            return new ResolvedRecipients([], [], collect());
        }

        /** @var array<string, int> $mailboxes address => mailbox id, former addresses included */
        $mailboxes = Mailbox::query()->deliverable()->whereIn('address', $all)->pluck('id', 'address')->all()
            + AddressHistory::query()->whereIn('address', $all)
                ->whereIn('mailbox_id', Mailbox::query()->deliverable()->select('id'))->pluck('mailbox_id', 'address')->all();
        $lists = MailList::query()->whereIn('address', array_diff($all, array_keys($mailboxes)))->get()->keyBy('address');
        $members = $lists->map(fn (MailList $list) => $this->members($list));

        $roles = [];
        $unknown = [];
        foreach ($byRole as $role => $addresses) {
            foreach ($addresses as $address) {
                $ids = isset($mailboxes[$address]) ? [$mailboxes[$address]] : $members->get($address);
                if ($ids === null) {
                    $unknown[] = $address;

                    continue;
                }
                foreach ($ids as $id) {
                    $roles[$id] ??= $role;
                }
            }
        }

        return new ResolvedRecipients($roles, array_values(array_unique($unknown)), $lists->values());
    }

    /** @return list<int> deliverable member mailboxes of a list */
    public function members(MailList $list): array
    {
        $query = Mailbox::query()->deliverable();
        if ($list->kind !== 'dynamic') {
            return $query->whereIn('id', DB::table('mail_list_members')->select('mailbox_id')->where('mail_list_id', $list->id))->pluck('id')->all();
        }

        $rule = [];
        foreach (['role', 'rank', 'ems_qualification', 'fire_qualification', 'specialty'] as $key) {
            $rule[$key] = array_values(array_filter(array_map('intval', (array) ($list->rule[$key] ?? [])), fn (int $id) => $id > 0));
        }
        if (array_merge(...array_values($rule)) === []) {
            return [];
        }

        return $query->where(function (Builder $q) use ($rule) {
            $personnel = [];
            foreach (['rank' => 'rank_id', 'ems_qualification' => 'ems_qualification_id', 'fire_qualification' => 'fire_qualification_id'] as $key => $column) {
                if ($rule[$key] !== []) {
                    $personnel[$column] = $rule[$key];
                }
            }
            if ($personnel !== []) {
                $q->orWhereIn('personnel_id', Personnel::query()->select('id')->where(function (Builder $p) use ($personnel) {
                    foreach ($personnel as $column => $ids) {
                        $p->orWhereIn($column, $ids);
                    }
                }));
            }
            if ($rule['specialty'] !== []) {
                $q->orWhereIn('personnel_id', DB::table('personnel_specialty')->select('personnel_id')->whereIn('specialty_id', $rule['specialty']));
            }
            if ($rule['role'] !== []) {
                // Any role of the account the mailbox belongs to, never one found over a Discord id.
                $q->orWhereIn('user_id', DB::table(config('permission.table_names.model_has_roles'))->select(config('permission.column_names.model_morph_key'))
                    ->where('model_type', (new User)->getMorphClass())->whereIn('role_id', $rule['role']));
            }
        })->pluck('id')->all();
    }
}

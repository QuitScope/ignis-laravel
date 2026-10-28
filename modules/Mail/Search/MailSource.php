<?php

namespace Modules\Mail\Search;

use App\Models\User;
use App\Modules\BelongsToModule;
use App\Search\SearchSource;
use App\Support\SqlLike;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Support\MailboxAccess;

/**
 * Group "Mails" of the global search (legacy Search\MailSource): only the viewer's own copies in mailboxes the account
 * opens (MailDelivery::visibleTo, no admin or super admin shortcut), not deleted. Matches subject, sender name and
 * address and the plain `search_text`; drafts by subject only (their search_text holds the body). The recipient header
 * (with BCC) is never searched or shown, so a hit reveals nothing about who else was in BCC.
 */
final class MailSource implements BelongsToModule, SearchSource
{
    public function module(): string
    {
        return 'mail';
    }

    public function key(): string
    {
        return 'mails';
    }

    public function label(): string
    {
        return __('mail.search.source');
    }

    public function allowed(User $user): bool
    {
        return $user->hasPermission(['admin', 'mail.use']) && MailboxAccess::accessibleIds($user) !== [];
    }

    public function search(User $user, string $q, int $limit): array
    {
        $rows = MailDelivery::query()->visibleTo($user)
            ->join('mail_messages as m', 'm.id', '=', 'mail_deliveries.message_id')
            ->join('mail_mailboxes as s', 's.id', '=', 'm.sender_mailbox_id')
            ->where(function (Builder $w) use ($q) {
                SqlLike::anyOf($w, ['m.subject'], $q);
                $w->orWhere(fn (Builder $sent) => $sent->where('m.status', 'sent')->where(fn ($x) => SqlLike::anyOf($x, ['s.display_name', 's.address', 'm.search_text'], $q)));
            })
            ->orderByRaw('COALESCE(m.sent_at, m.updated_at, m.created_at) DESC')->orderByDesc('mail_deliveries.id')
            ->toBase()->limit($limit * 4)
            ->get(['mail_deliveries.message_id', 'mail_deliveries.folder', 'mail_deliveries.mailbox_id', 'm.subject', 'm.status', 'm.sent_at', 's.display_name']);

        return $rows->unique('message_id')->take($limit)->map(fn ($row) => [
            'label' => $row->subject !== '' ? $row->subject : __('mail.search.no_subject'),
            'sub' => ($row->status === 'draft' ? __('mail.search.draft') : __('mail.search.from', ['name' => $row->display_name]))
                .($row->sent_at !== null ? ' · '.CarbonImmutable::parse($row->sent_at, 'UTC')->setTimezone((string) config('ignis.timezone'))->format('d.m.Y') : ''),
            'href' => "/mail/{$row->folder}/{$row->message_id}?mailbox={$row->mailbox_id}",
        ])->values()->all();
    }

    /** No typo correction for mail: a shared vocabulary would be built from private rows. */
    public function vocabulary(): ?array
    {
        return null;
    }

    public function fuzzyAllowed(User $user): bool
    {
        return false;
    }
}

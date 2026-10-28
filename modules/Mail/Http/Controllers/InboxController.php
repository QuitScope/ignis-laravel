<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Mail\Content\MailContentRenderer;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Notifications\MailType;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\HeaderVisibility;
use Modules\Mail\Support\MailboxAccess;
use Throwable;

/**
 * The workspace (legacy MailController folder/messagePage/move/markRead/flag/delete/addressbook). Lists show the chosen
 * mailbox only (MailboxAccess::selected), one row per mail, cursor pages of 50, never the body or the raw header.
 * `{message}` is bound through MailDelivery::visibleTo (MailServiceProvider): no Gate, no permission opens a foreign mail,
 * admins and super admins get the same 404. GET writes nothing to mail or bell rows (reading is PATCH …/read); the only
 * thing it remembers is the chosen mailbox in the session. Actions change only the own copies of the chosen mailbox and
 * only the acting account's bell entries.
 */
class InboxController extends Controller
{
    public const FOLDERS = ['inbox', 'sent', 'drafts', 'archive', 'trash'];

    public const PER_PAGE = 50;

    public const DRAFT_DAYS = 30;

    private const MOVES = ['inbox', 'archive', 'trash', 'restore'];

    private const EXCERPT = 400;

    /** GET /mail, GET /mail/{folder} */
    public function index(Request $request, string $folder = 'inbox'): Response
    {
        [$user, $mailbox, $state] = $this->context($request);

        return $this->page($user, $mailbox, $state, $folder, null);
    }

    /**
     * GET /mail/{folder}/{message}: the same workspace with the mail in the reading pane. Not in this folder of the
     * chosen mailbox (the bell links /mail/inbox/<id>): on to where the own copy really is, in the chosen mailbox if it
     * has one, else in the other mailbox this account opens.
     */
    public function show(Request $request, string $folder, MailMessage $message): Response|RedirectResponse
    {
        [$user, $mailbox, $state] = $this->context($request);
        $copies = MailDelivery::query()->visibleTo($user)->where('message_id', $message->id)->get()
            ->sortBy(fn (MailDelivery $d) => [$d->mailbox_id !== $mailbox?->id, $d->role === 'sender'])->values();
        $here = $copies->first(fn (MailDelivery $d) => $d->mailbox_id === $mailbox?->id && $d->folder === $folder);
        if ($here === null) {
            $real = $copies->first() ?? abort(404);

            return redirect("/mail/{$real->folder}/{$message->id}?mailbox={$real->mailbox_id}");
        }

        return $this->page($user, $mailbox, $state, $folder, $here->setRelation('message', $message));
    }

    /** PATCH /mail/messages/{message}/folder {folder: inbox|archive|trash|restore} */
    public function move(Request $request, MailMessage $message): JsonResponse
    {
        $folder = $request->validate(['folder' => ['required', 'string', Rule::in(self::MOVES)]])['folder'];
        [$user, $own] = $this->own($request, $message);
        abort_unless($message->status === 'sent', 404);

        if ($folder === 'restore') {
            (clone $own)->where('role', 'sender')->update(['folder' => 'sent']);
            (clone $own)->where('role', '!=', 'sender')->update(['folder' => 'inbox']);
        } else {
            $own->update(['folder' => $folder]);
        }
        if ($folder === 'trash') {
            MailType::forget($user, $message->id);
        }

        return response()->json(['folder' => $folder]);
    }

    /** PATCH /mail/messages/{message}/read {read: bool}: the own copy; reading also reads the own bell entry. */
    public function read(Request $request, MailMessage $message): JsonResponse
    {
        $read = $request->validate(['read' => ['required', 'boolean']])['read'];
        [$user, $own] = $this->own($request, $message);

        $read ? $own->whereNull('read_at')->update(['read_at' => now()]) : $own->update(['read_at' => null]);
        if ($read) {
            MailType::markRead($user, $message->id);
        }

        return response()->json(['read' => (bool) $read]);
    }

    /** PATCH /mail/messages/{message}/flag {flagged: bool} */
    public function flag(Request $request, MailMessage $message): JsonResponse
    {
        $flagged = (bool) $request->validate(['flagged' => ['required', 'boolean']])['flagged'];
        [, $own] = $this->own($request, $message);
        $own->update(['is_flagged' => $flagged]);

        return response()->json(['flagged' => $flagged]);
    }

    /**
     * DELETE /mail/messages/{message}: the own copy for good (`deleted_at`, the others keep theirs). An own draft that
     * nobody else holds a copy of goes entirely.
     */
    public function destroy(Request $request, MailMessage $message): JsonResponse
    {
        [$user, $own, $mailbox] = $this->own($request, $message);

        $foreign = $message->deliveries()->where('mailbox_id', '!=', $mailbox->id)->exists();
        // A draft goes with its attachment files (rows cascade); purge() re-checks under the lock, so a send that
        // committed meanwhile only loses the own copy, never the delivered mail.
        $purged = $message->status === 'draft' && $message->sender_mailbox_id === $mailbox->id && ! $foreign
            && app(AttachmentStorage::class)->purge($message);
        if (! $purged) {
            $own->update(['deleted_at' => now()]);
        }
        MailType::forget($user, $message->id);

        return response()->json(['deleted' => true]);
    }

    /**
     * GET /mail/addressbook?q=: deliverable mailboxes and the lists this account may write to, at most 20 of each.
     * Lists for managers only are missing for everybody else, as in the send check.
     */
    public function addressbook(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';

        $mailboxes = Mailbox::query()->deliverable()->when($q !== '', fn ($b) => SqlLike::anyOf($b, ['address', 'display_name'], $q))
            ->orderBy('display_name')->orderBy('id')->limit(20)->get(['address', 'display_name', 'kind'])
            ->map(fn (Mailbox $m) => ['address' => $m->address, 'name' => $m->display_name, 'kind' => $m->kind]);
        $lists = MailList::query()
            ->when(! $user->hasPermission(['admin', 'mail.lists.manage']), fn ($b) => $b->where('senders', 'all'))
            ->when($q !== '', fn ($b) => SqlLike::anyOf($b, ['address', 'name'], $q))
            ->orderBy('name')->orderBy('id')->limit(20)->get(['address', 'name'])
            ->map(fn (MailList $l) => ['address' => $l->address, 'name' => $l->name, 'kind' => 'list']);

        return response()->json(['results' => $mailboxes->concat($lists)->values()]);
    }

    /** @return array{User, ?Mailbox, string} the account, the chosen mailbox, ok|none|inactive|unknown */
    private function context(Request $request): array
    {
        $user = $this->user($request);
        $requested = $request->query('mailbox');
        $requested = is_string($requested) && ctype_digit($requested) ? (int) $requested : null;
        $mailbox = MailboxAccess::selected($user, $requested);
        if ($mailbox !== null) {
            MailboxAccess::remember($mailbox);

            return [$user, $mailbox, 'ok'];
        }
        $own = MailboxAccess::ownedBy($user);

        return [$user, null, match (true) {
            $requested !== null && MailboxAccess::accessibleIds($user) !== [] => 'unknown',
            $own !== null && ! $own->isOpen() => 'inactive',
            default => 'none',
        }];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermission(['admin', 'mail.use']), 403); // the page only; content follows MailDelivery::visibleTo

        return $user;
    }

    /**
     * The own copies of the mail in the chosen mailbox (`mailbox_id`, else the remembered one): a foreign mailbox is a
     * 403, a mailbox without a copy a 404.
     *
     * @return array{User, HasMany<MailDelivery, MailMessage>, Mailbox}
     */
    private function own(Request $request, MailMessage $message): array
    {
        $user = $this->user($request);
        $request->validate(['mailbox_id' => ['nullable', 'integer', 'min:1']]);
        $mailbox = MailboxAccess::selected($user, $request->filled('mailbox_id') ? $request->integer('mailbox_id') : null) ?? abort(403);
        $own = $message->deliveries()->where('mailbox_id', $mailbox->id)->whereNull('deleted_at');
        abort_unless((clone $own)->exists(), 404);

        return [$user, $own, $mailbox];
    }

    private function page(User $user, ?Mailbox $mailbox, string $state, string $folder, ?MailDelivery $open): Response
    {
        $empty = ['data' => [], 'next' => null, 'prev' => null];

        return Inertia::render('Mail/Index', [
            'folder' => $folder,
            'state' => $state,
            'draftDays' => self::DRAFT_DAYS,
            'selected' => $mailbox?->id,
            'mailbox' => $mailbox === null ? null : self::ref($mailbox),
            'mailboxes' => fn () => self::mailboxes($user),
            'counts' => fn () => $mailbox === null ? [] : self::unreadCounts($mailbox),
            'messages' => fn () => $mailbox === null ? $empty : self::list($mailbox, $folder),
            'message' => fn () => $open === null || $mailbox === null ? null : self::reading($open, $mailbox),
            'compose' => Inertia::optional(fn () => $mailbox === null ? null : ComposeController::props($user, $mailbox, 'new', [])),
        ]);
    }

    /** @return array{id: int, address: string, display_name: string, kind: string} */
    private static function ref(Mailbox $mailbox): array
    {
        return ['id' => $mailbox->id, 'address' => $mailbox->address, 'display_name' => $mailbox->display_name, 'kind' => $mailbox->kind];
    }

    /** @return list<array<string, mixed>> the switcher: every accessible mailbox with its unread inbox mail (one query) */
    private static function mailboxes(User $user): array
    {
        $all = MailboxAccess::accessible($user);
        $unread = $all->isEmpty() ? collect() : MailDelivery::query()->toBase()->whereIn('mailbox_id', $all->pluck('id'))
            ->where('folder', 'inbox')->whereNull('read_at')->whereNull('deleted_at')->where('role', '!=', 'sender')
            ->groupBy('mailbox_id')->selectRaw('mailbox_id, count(distinct message_id) as c')->pluck('c', 'mailbox_id');

        return $all->map(fn (Mailbox $m) => self::ref($m) + ['unread' => (int) ($unread[$m->id] ?? 0)])->values()->all();
    }

    /**
     * Unread received copies per folder (mail_deliveries_unread_index), one query; the sender copy is never unread.
     *
     * @return array<string, int>
     */
    public static function unreadCounts(Mailbox $mailbox): array
    {
        return MailDelivery::query()->toBase()->where('mailbox_id', $mailbox->id)
            ->whereNull('read_at')->whereNull('deleted_at')->where('role', '!=', 'sender')
            ->groupBy('folder')->selectRaw('folder, count(distinct message_id) as c')->orderBy('folder')
            ->pluck('c', 'folder')->map(fn ($c) => (int) $c)->all();
    }

    /** @return array{data: list<array<string, mixed>>, next: ?string, prev: ?string} */
    private static function list(Mailbox $mailbox, string $folder): array
    {
        $page = MailDelivery::query()
            ->where('mailbox_id', $mailbox->id)->where('folder', $folder)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('role', '!=', 'sender')->orWhereNotExists(fn ($s) => $s->select(DB::raw(1))
                ->from('mail_deliveries as o')->whereColumn('o.message_id', 'mail_deliveries.message_id')
                ->whereColumn('o.mailbox_id', 'mail_deliveries.mailbox_id')->whereColumn('o.folder', 'mail_deliveries.folder')
                ->whereNull('o.deleted_at')->where('o.role', '!=', 'sender')))
            // Never the body; of search_text only the start (the preview).
            ->with(['message' => fn ($q) => $q->select(['id', 'sender_mailbox_id', 'subject', 'header', 'status', 'sent_at', 'updated_at'])
                ->selectRaw('substr(search_text, 1, ?) as excerpt', [self::EXCERPT]), 'message.senderMailbox:id,address,display_name'])
            ->orderByDesc('sorted_at')->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)->withQueryString();

        $rows = collect($page->items())->map(function (MailDelivery $d) use ($mailbox) {
            $m = $d->setRelation('mailbox', $mailbox)->message;

            return [
                'id' => $m->id,
                'subject' => $m->subject,
                'from' => ['name' => $m->senderMailbox->display_name, 'address' => $m->senderMailbox->address],
                'to' => HeaderVisibility::for($d)['to'],
                'date' => ($m->sent_at ?? $m->updated_at)?->toIso8601String(),
                'unread' => $d->read_at === null && $d->role !== 'sender',
                'flagged' => $d->is_flagged,
                'draft' => $m->status === 'draft',
                'preview' => self::preview((string) $m->getAttribute('excerpt')),
            ];
        });

        return ['data' => $rows->values()->all(), 'next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()];
    }

    public static function preview(string $excerpt): string
    {
        $text = str_contains($excerpt, "\n") ? substr($excerpt, strpos($excerpt, "\n") + 1) : '';

        return mb_substr(trim(mb_scrub($text, 'UTF-8')), 0, 160);
    }

    /** @return array<string, mixed> the reading pane: the visible header only, the body only as renderer output */
    private static function reading(MailDelivery $copy, Mailbox $mailbox): array
    {
        $message = $copy->message->loadMissing('senderMailbox', 'sentBy.personnel');
        $copy->setRelation('mailbox', $mailbox);
        try {
            $html = MailContentRenderer::html($message->body ?? ['type' => 'doc', 'content' => []]);
            $broken = false;
        } catch (Throwable $e) {
            report($e);
            [$html, $broken] = ['', true];
        }
        // Who wrote for a group mailbox: only its members see it, in the group's own sender copy (legacy).
        $sentBy = $mailbox->isGroup() && $message->sender_mailbox_id === $mailbox->id ? $message->sentBy?->displayName() : null;

        return [
            'id' => $message->id,
            'folder' => $copy->folder,
            'role' => $copy->role,
            'subject' => $message->subject,
            'from' => ['name' => $message->senderMailbox->display_name, 'address' => $message->senderMailbox->address],
            'sentBy' => $sentBy,
            'header' => HeaderVisibility::for($copy),
            'date' => ($message->sent_at ?? $message->updated_at)?->toIso8601String(),
            'html' => $html,
            'broken' => $broken,
            'draft' => $message->status === 'draft',
            'flagged' => $copy->is_flagged,
            'unread' => $copy->read_at === null && $copy->role !== 'sender',
            'attachments' => $message->attachments()->orderBy('id')->get()
                ->map(fn (Attachment $a) => AttachmentStorage::ref($a) + ['missing' => $a->missing])->all(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Notifications\InboxNotification;
use App\Notifications\NotificationKinds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inbox (legacy InboxController): the signed-in account's notifications. Every lookup runs on its own visible
 * rows (NotificationKinds::visibleTo), so another account's id is a 404 and a hidden kind appears nowhere.
 *
 *   GET  /inbox              page, 25 per page, `filter=unread`, `type`, counts per segment
 *   GET  /inbox/popover      JSON for the bell: the last five plus the unread counter
 *   GET  /inbox/{id}/open    marks it read, then follows its link (internal paths only, else /inbox)
 *   POST /inbox/read         one (`id`) or all read, back to `return` when it is an internal page
 */
class InboxController extends Controller
{
    public const PAGE_SIZE = 25;

    public const POPOVER_LIMIT = 5;

    public function __construct(private readonly NotificationKinds $kinds) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $allowed = $this->kinds->allowed($user);
        $unreadOnly = $request->query('filter') === 'unread';
        $type = $request->query('type');
        $type = is_string($type) && in_array($type, $allowed, true) ? $type : '';

        $rows = fn (bool $unread, string $type) => $this->kinds->visibleTo($user)
            ->when($unread, fn ($q) => $q->whereNull('read_at'))
            ->when($type !== '', fn ($q) => $q->where('type', $type));
        $byType = $rows($unreadOnly, '')->reorder()->toBase()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')
            ->map(fn ($n) => (int) $n)->all();

        return Inertia::render('Inbox/Index', [
            'entries' => $rows($unreadOnly, $type)->paginate(self::PAGE_SIZE)->withQueryString()->through($this->kinds->present(...)),
            'filters' => ['filter' => $unreadOnly ? 'unread' : '', 'type' => $type],
            'types' => array_map(fn (string $key) => ['key' => $key, ...$this->kinds->describe($key)], $allowed),
            'unread' => $rows(true, '')->count(),
            'counts' => [
                'all' => $rows(false, $type)->count(),
                'unread' => $rows(true, $type)->count(),
                'types' => ['' => array_sum($byType)] + $byType,
            ],
        ]);
    }

    public function popover(Request $request): JsonResponse
    {
        $visible = $this->kinds->visibleTo($request->user());

        return response()->json([
            'entries' => (clone $visible)->limit(self::POPOVER_LIMIT)->get()->map($this->kinds->present(...))->all(),
            'unread' => $visible->whereNull('read_at')->count(),
        ]);
    }

    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $this->kinds->visibleTo($request->user())->whereKey($id)->firstOrFail();
        $notification->markAsRead();
        $link = $notification->data['link'] ?? null;

        return redirect(InboxNotification::safeLink(is_string($link) ? $link : null) ?? '/inbox');
    }

    public function read(Request $request): RedirectResponse
    {
        $id = $request->input('id');
        $all = $id === null || $id === '' || $id === '0';
        $one = is_string($id) && Str::isUuid($id);
        $count = $all || $one
            ? $this->kinds->visibleTo($request->user())->reorder()->whereNull('read_at')
                ->when($one, fn ($q) => $q->whereKey($id))
                ->update(['read_at' => now()])
            : 0;
        [$status, $params] = $all
            ? ['inbox.marked_all', ['count' => $count]]
            : [$count > 0 ? 'inbox.marked_one' : 'inbox.already_read', null];

        // Legacy pattern (`inbox?filter=unread`), with or without the leading slash: a page of this app, never a host.
        $return = $request->input('return');
        $target = is_string($return) && preg_match('~^/?[a-z][a-z0-9/_-]*(\?[^\s\x00-\x1F\x7F]*)?$~iD', $return) === 1 ? '/'.ltrim($return, '/') : '/inbox';

        return redirect($target)->with('status', $status)->with('status_params', $params);
    }
}

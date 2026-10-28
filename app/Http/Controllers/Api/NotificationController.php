<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Navigation\NavigationCounters;
use App\Notifications\NotificationKinds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The bell's API (legacy Api\NotificationController). Session login only; every lookup runs
 * on the signed-in account's own rows (NotificationKinds::visibleTo), so another account's id is a 404.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationKinds $kinds) {}

    public function poll(Request $request, NavigationCounters $counters): JsonResponse
    {
        $user = $request->user();
        $visible = $this->kinds->visibleTo($user);

        return response()->json([
            'success' => true,
            'unreadCount' => (clone $visible)->whereNull('read_at')->count(),
            'navCounters' => $counters->for($user),
            'new' => $visible->whereNull('read_at')->where('created_at', '>', self::since($request->query('since')))
                ->limit(5)->get()->map($this->kinds->present(...))->all(),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $id = $request->validate(['id' => ['required', 'string', 'uuid']])['id'];
        $notification = $this->kinds->visibleTo($request->user())->whereKey($id)->first();
        if ($notification === null) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }
        $notification->markAsRead();

        return response()->json(['success' => true, 'message' => 'Notification marked as read']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->kinds->visibleTo($request->user())->reorder()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * The legacy client sends `Y-m-d H:i:s` built with toISOString(), i.e. UTC; ISO-8601 is accepted too. Missing,
     * malformed, older than seven days or in the future (clock skew): one minute ago, the legacy default.
     */
    private static function since(mixed $raw): Carbon
    {
        $fallback = now()->subMinute();
        if (! is_string($raw) || preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d(\.\d{1,6})?)?(Z|[+-]\d\d:?\d\d)?$/D', $raw) !== 1) {
            return $fallback;
        }
        try {
            $since = Carbon::parse($raw, 'UTC');
        } catch (Throwable) {
            return $fallback;
        }

        return $since->lt(now()->subDays(7)) || $since->gt(now()) ? $fallback : $since->utc();
    }
}

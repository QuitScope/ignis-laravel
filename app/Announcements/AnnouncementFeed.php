<?php

namespace App\Announcements;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Official announcements from the hub (legacy GlobalAnnouncementManager, display and cache part only: no
 * installation id, no heartbeat, nothing but the scheduler fetches). refresh() validates every field of the foreign
 * response; a bad response, timeout or error keeps the previous cache and leaves a short code in
 * `announcements.last_error`. The cache is one forever key, replaced as a whole. visibleTo() is what the layout shows.
 */
final class AnnouncementFeed
{
    public const CACHE_KEY = 'announcements.hub';

    public const MAX_ITEMS = 50;

    public const MAX_SHOWN = 5;

    public const TYPES = ['critical', 'warning', 'update', 'success', 'info'];

    private const TIME = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/D';

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->get('announcements.hub_enabled', false) === true;
    }

    public function refresh(): RefreshResult
    {
        $base = FeedHttp::baseUrl('ignis.hub_url');
        if ($base === null) {
            return $this->finish(new RefreshResult(false, 'bad_config'));
        }
        $response = FeedHttp::get($base.'/api/hub-announcements.php', 3);
        if (is_string($response)) {
            return $this->finish(new RefreshResult(false, $response));
        }
        if ($response->status() !== 200) {
            return $this->finish(new RefreshResult(false, 'http_'.$response->status()));
        }

        // The legacy envelope {success, announcements}; a bare list is accepted too. heartbeat_requested_at is ignored.
        $data = json_decode($response->body(), true);
        $list = is_array($data) && array_is_list($data) ? $data : (is_array($data) && ($data['success'] ?? null) === true ? ($data['announcements'] ?? null) : null);
        if (! is_array($list) || ! array_is_list($list)) {
            return $this->finish(new RefreshResult(false, 'invalid_response'));
        }

        $items = [];
        $dropped = max(0, count($list) - self::MAX_ITEMS);
        foreach (array_slice($list, 0, self::MAX_ITEMS) as $raw) {
            $item = self::item($raw);
            if ($item === null || isset($items[$item['id']])) {
                $dropped++;

                continue;
            }
            $items[$item['id']] = $item;
        }
        if ($dropped > 0) {
            Log::warning("Announcements: {$dropped} invalid hub items dropped");
        }
        Cache::forever(self::CACHE_KEY, ['fetched_at' => now()->toIso8601String(), 'items' => array_values($items)]);

        return $this->finish(new RefreshResult(true, 'ok', count($items), $dropped));
    }

    /**
     * What the viewer sees: valid now, not dismissed, admin_only for admins only; priority, then severity; five at most.
     * One cache read, and no query unless something in the cache could be shown.
     *
     * @return list<array{id: string, type: string, title: string, message: ?string, link: ?string}>
     */
    public function visibleTo(User $user): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $items = Cache::get(self::CACHE_KEY)['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return [];
        }

        $now = now();
        $isAdmin = $user->hasPermission('admin');
        $items = array_values(array_filter($items, fn (array $i) => ($isAdmin || ! $i['admin_only'])
            && ($i['valid_from'] === null || Carbon::parse($i['valid_from'])->lte($now))
            && ($i['valid_until'] === null || Carbon::parse($i['valid_until'])->gte($now))));
        if ($items === []) {
            return [];
        }

        $dismissed = DB::table('announcement_dismissals')->where('user_id', $user->id)->whereIn('announcement_id', array_column($items, 'id'))->pluck('announcement_id')->all();
        $items = array_filter($items, fn (array $i) => ! in_array($i['id'], $dismissed, true));
        usort($items, fn (array $a, array $b) => [$b['priority'], array_search($a['type'], self::TYPES, true)] <=> [$a['priority'], array_search($b['type'], self::TYPES, true)]);

        return array_map(fn (array $i) => array_intersect_key($i, array_flip(['id', 'type', 'title', 'message', 'link'])), array_slice($items, 0, self::MAX_SHOWN));
    }

    public function knows(string $id): bool
    {
        return in_array($id, array_column(Cache::get(self::CACHE_KEY)['items'] ?? [], 'id'), true);
    }

    /** One validated item, or null when any field is off.
     *
     * @return ?array{id: string, type: string, title: string, message: ?string, link: ?string, priority: int, admin_only: bool, valid_from: ?string, valid_until: ?string}
     */
    private static function item(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        // A MySQL-backed hub may send ids and numbers as numbers or digit strings and booleans as 0/1.
        $id = $raw['id'] ?? null;
        $id = is_int($id) ? (string) $id : $id;
        $type = $raw['type'] ?? 'info';
        $title = $raw['title'] ?? null;
        $message = $raw['message'] ?? null;
        $link = ($raw['link'] ?? null) === '' ? null : ($raw['link'] ?? null);
        $priority = array_key_exists('priority', $raw) ? $raw['priority'] : 0;
        $priority = is_string($priority) && preg_match('/^-?\d{1,3}$/D', $priority) === 1 ? (int) $priority : $priority;
        $adminOnly = array_key_exists('admin_only', $raw) ? $raw['admin_only'] : false;
        $adminOnly = match ($adminOnly) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => null,
        };
        if (! is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $id) !== 1
            || ! is_string($type) || ! in_array($type, self::TYPES, true)
            || ! is_string($title) || ! is_int($priority) || $priority < -100 || $priority > 100
            || $adminOnly === null || ! (is_string($message) || $message === null) || ! (is_string($link) || $link === null)) {
            return null;
        }
        $title = FeedHttp::text($title);
        $message = $message === null ? null : FeedHttp::text($message, true);
        if ($title === '' || mb_strlen($title) > 255 || mb_strlen((string) $message) > 2000 || ($link !== null && ! self::link($link))) {
            return null;
        }
        $from = self::time($raw['valid_from'] ?? null);
        $until = self::time($raw['valid_until'] ?? null);
        if ($from === false || $until === false) {
            return null;
        }

        return ['id' => $id, 'type' => $type, 'title' => $title, 'message' => $message === '' ? null : $message, 'link' => $link,
            'priority' => $priority, 'admin_only' => $adminOnly, 'valid_from' => $from, 'valid_until' => $until];
    }

    private static function link(string $link): bool
    {
        $parts = parse_url($link);

        return strlen($link) <= 512 && preg_match('/^[\x21-\x7E]+$/D', $link) === 1 && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== '' && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    private static function time(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match(self::TIME, $value) !== 1) {
            return false;
        }
        try {
            return Carbon::parse($value, 'UTC')->utc()->toIso8601String();
        } catch (Throwable) {
            return false;
        }
    }

    private function finish(RefreshResult $result): RefreshResult
    {
        $this->settings->set('announcements.last_error', $result->ok ? null : $result->message);
        $this->settings->set('announcements.last_refreshed_at', now()->toIso8601String());
        if (! $result->ok) {
            Log::warning('Announcements: hub refresh failed, keeping the previous cache ('.$result->message.')');
        }

        return $result;
    }
}

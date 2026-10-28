<?php

namespace App\Announcements;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The website's announcements for the admin start page (legacy ChangelogClient): the Discourse-format category
 * listing /c/ankuendigungen/5.json, fetched conditionally (ETag / If-Modified-Since from the settings). 304 only
 * moves the timestamp; 429, 5xx, network errors and unusable bodies keep the cache and leave a code in
 * `announcements.website_last_error`. Only the scheduler fetches.
 */
final class WebsiteFeed
{
    public const CACHE_KEY = 'announcements.website';

    public const MAX_ITEMS = 10;

    private const PATH = '/c/ankuendigungen/5.json';

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->get('announcements.website_enabled', false) === true;
    }

    public function refresh(): RefreshResult
    {
        $base = FeedHttp::baseUrl('ignis.forum_url');
        if ($base === null) {
            return $this->finish(new RefreshResult(false, 'bad_config'));
        }
        $headers = [];
        if (Cache::has(self::CACHE_KEY)) {
            foreach (['If-None-Match' => 'etag', 'If-Modified-Since' => 'last_modified'] as $header => $key) {
                $value = $this->settings->get('announcements.website_'.$key);
                if (is_string($value) && $value !== '') {
                    $headers[$header] = $value;
                }
            }
        } else {
            $this->forgetValidators();
        }

        $response = FeedHttp::get($base.self::PATH, 5, $headers);
        if (is_string($response)) {
            return $this->finish(new RefreshResult(false, $response));
        }
        if ($response->status() === 304) {
            return $this->finish(new RefreshResult(true));
        }
        if ($response->status() !== 200) {
            return $this->finish(new RefreshResult(false, 'http_'.$response->status()));
        }

        $items = self::parse(json_decode($response->body(), true), $base);
        if ($items === null) {
            return $this->finish(new RefreshResult(false, 'invalid_response'));
        }
        Cache::forever(self::CACHE_KEY, ['fetched_at' => now()->toIso8601String(), 'items' => $items]);
        foreach (['etag' => 'ETag', 'last_modified' => 'Last-Modified'] as $key => $header) {
            $value = $response->header($header);
            $this->settings->set('announcements.website_'.$key, preg_match('/^[\x20-\x7E]{1,255}$/D', $value) === 1 ? $value : null);
        }

        return $this->finish(new RefreshResult(true, 'ok', count($items)));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->forgetValidators();
    }

    private function forgetValidators(): void
    {
        $this->settings->forget('announcements.website_etag');
        $this->settings->forget('announcements.website_last_modified');
    }

    /** @return list<array{id: int, title: string, url: string, excerpt: ?string, pinned: bool, created_at: string, bumped_at: ?string}> */
    public function items(): array
    {
        $items = Cache::get(self::CACHE_KEY)['items'] ?? [];

        return is_array($items) ? $items : [];
    }

    /**
     * Discourse topics to cache entries: visible ones that are not the category description, with a safe slug;
     * pinned first, then newest first, ten at most. Excerpts are HTML with entities in the feed: text only here.
     *
     * @return ?list<array<string, mixed>> null when the body has no topic_list at all
     */
    private static function parse(mixed $payload, string $base): ?array
    {
        $topics = is_array($payload) && is_array($payload['topic_list'] ?? null) ? ($payload['topic_list']['topics'] ?? null) : null;
        if (! is_array($topics)) {
            return null;
        }
        $items = [];
        foreach ($topics as $t) {
            if (! is_array($t) || ! is_int($t['id'] ?? null) || $t['id'] < 1 || ! is_string($t['title'] ?? null) || ! is_string($t['slug'] ?? null)
                || preg_match('/^[a-z0-9-]{1,255}$/D', $t['slug']) !== 1 || ($t['visible'] ?? true) !== true) {
                continue;
            }
            $title = FeedHttp::text($t['title']);
            $created = self::utc($t['created_at'] ?? null);
            if ($title === '' || $created === null || preg_match('/^(über die kategorie|about the)/iu', $title) === 1) {
                continue;
            }
            $excerpt = is_string($t['excerpt'] ?? null) ? FeedHttp::text($t['excerpt']) : '';
            $items[] = [
                'id' => $t['id'],
                'title' => mb_substr($title, 0, 255),
                'url' => $base.'/t/'.$t['slug'].'/'.$t['id'],
                'excerpt' => $excerpt === '' ? null : mb_substr($excerpt, 0, 300),
                'pinned' => ($t['pinned'] ?? false) === true,
                'created_at' => $created,
                'bumped_at' => self::utc($t['bumped_at'] ?? null),
            ];
        }
        usort($items, fn (array $a, array $b) => [$b['pinned'], $b['created_at']] <=> [$a['pinned'], $a['created_at']]);

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    private static function utc(mixed $iso): ?string
    {
        try {
            return is_string($iso) ? Carbon::parse($iso)->utc()->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function finish(RefreshResult $result): RefreshResult
    {
        $this->settings->set('announcements.website_last_error', $result->ok ? null : $result->message);
        $this->settings->set('announcements.website_last_refreshed_at', now()->toIso8601String());
        if (! $result->ok) {
            Log::warning('Announcements: website refresh failed, keeping the previous cache ('.$result->message.')');
        }

        return $result;
    }
}

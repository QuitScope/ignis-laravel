<?php

namespace App\Dashboard\Widgets;

use App\Announcements\FeedHttp;
use App\Announcements\WebsiteFeed;
use App\Dashboard\Widget;
use App\Models\User;
use App\Support\Settings;

/**
 * "Ankündigungen" (legacy index/changelog.php): the website entries from the cache, admins only and only with the
 * opt-in switch on. It never fetches. When the last refresh failed the admin sees why; with nothing to
 * show the widget stays as an empty state (like the legacy).
 */
final class AnnouncementsWidget implements Widget
{
    public function key(): string
    {
        return 'announcements';
    }

    public function area(): string
    {
        return 'side';
    }

    public function order(): int
    {
        return 40;
    }

    public function allowed(User $user): bool
    {
        return $user->hasPermission('admin') && app(WebsiteFeed::class)->enabled();
    }

    public function data(User $user): array
    {
        $problem = app(Settings::class)->get('announcements.website_last_error');
        $base = FeedHttp::baseUrl('ignis.forum_url');

        return [
            'items' => app(WebsiteFeed::class)->items(),
            'problem' => is_string($problem) && $problem !== '' ? $problem : null,
            'blog_url' => $base !== null ? $base.'/blog' : null,
        ];
    }
}

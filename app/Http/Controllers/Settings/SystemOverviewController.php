<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use App\System\SystemOverview;
use App\Update\Installation;
use App\Update\InstalledVersion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The system overview (legacy Einstellungen › System › Übersicht): version, health, scheduler, queue, counts, database.
 * The numbers load deferred so the page shell renders at once; no polling, a reload shows fresh values.
 */
class SystemOverviewController extends Controller
{
    public function index(Request $request, Settings $settings, SystemOverview $overview): Response
    {
        $this->authorize('system.admin');
        $url = $settings->get('updates.release_url');
        $latest = $settings->get('updates.latest_version');
        $blocker = Installation::selfUpdateBlocker();

        return Inertia::render('Settings/System/Index', [
            'version' => InstalledVersion::current(),
            // Written by ignis:update:check. https only: the link is rendered as href.
            'updates' => [
                'check_enabled' => (bool) $settings->get('updates.check_enabled'),
                'latest_version' => is_string($latest) && $latest !== '' ? $latest : null,
                'release_url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
            ],
            'update' => [
                'can_self_update' => $blocker === null,
                'reason' => $blocker,
                'repo_configured' => Installation::repoConfigured(),
            ],
            'overview' => Inertia::defer(fn () => $overview->build($request)),
        ]);
    }
}

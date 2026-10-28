<?php

namespace App\Http\Controllers\Settings;

use App\Announcements\AnnouncementFeed;
use App\Announcements\WebsiteFeed;
use App\Auth\ApiKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SystemConfigRequest;
use App\Support\AuditLogger;
use App\Support\SettingDefinition;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The system configuration: one tab per settings group of the registry, each saved on its own. Secrets are
 * write-only (the page only learns whether one is set), not editable keys (logo, API key) have their own UI.
 */
class SystemConfigController extends Controller
{
    private const DETAILS_MAX = 200;

    private const FEEDS = ['announcements.hub_enabled' => 'Hub', 'announcements.website_enabled' => 'Website'];

    public function index(Request $request, SettingDefinitions $defs, Settings $settings, ApiKey $apiKey): Response
    {
        $this->authorize('system.admin');

        $tabs = array_values(array_filter($defs->groups(), fn (string $g) => $g === 'interfaces' || self::editable($defs, $g) !== []));
        $tab = $request->query('tab');

        return Inertia::render('Settings/System/Config', [
            'tabs' => $tabs,
            'tab' => is_string($tab) && in_array($tab, $tabs, true) ? $tab : ($tabs[0] ?? 'general'),
            'groups' => collect($tabs)->mapWithKeys(fn (string $g) => [$g => array_map(fn (SettingDefinition $d) => self::field($d, $settings), self::editable($defs, $g))])->all(),
            'apiKeySet' => $apiKey->isConfigured(),
            'hosts' => collect(['ignis.hub_url', 'ignis.forum_url'])->map(fn (string $k) => parse_url((string) config($k), PHP_URL_HOST) ?: (string) config($k))->unique()->values()->all(),
        ]);
    }

    public function update(SystemConfigRequest $request, string $group, SettingDefinitions $defs, Settings $settings): RedirectResponse
    {
        foreach ($request->values() as $key => $new) {
            $def = $defs->definition($key);
            if ($def === null) {
                continue;
            }
            $old = $settings->get($key);

            if ($def->deletableOnly) {
                if (! $settings->has($key)) {
                    continue;
                }
                $settings->forget($key);
                $new = '';
            } elseif ($def->secret) {
                if ($new === '' || $new === $old) {
                    continue;
                }
                $settings->set($key, $new, encrypted: true);
            } else {
                $stored = $settings->has($key);
                if ($new === $old && ($stored || ! $def->alwaysStore)) {
                    continue;
                }
                $settings->set($key, $new);
                if ($new === $old) {
                    continue;
                }
            }

            if (isset(self::FEEDS[$key])) {
                $this->feedSwitched($request, $key, $new === true);
            } else {
                AuditLogger::log(
                    $request->user(),
                    "Einstellung {$key} geändert".($def->secret ? ' (geheim)' : ''),
                    $def->secret ? null : mb_substr(self::show($old).' → '.self::show($new), 0, self::DETAILS_MAX),
                    'System',
                    true,
                    ['key' => $key],
                );
            }
        }

        return redirect("/settings/system/config?tab={$group}")->with('status', 'settings.system.saved');
    }

    private function feedSwitched(Request $request, string $key, bool $on): void
    {
        if (! $on) {
            $key === 'announcements.hub_enabled' ? Cache::forget(AnnouncementFeed::CACHE_KEY) : app(WebsiteFeed::class)->forget();
        }
        AuditLogger::log($request->user(), 'Ankündigungen '.($on ? 'aktiviert' : 'deaktiviert').' ('.self::FEEDS[$key].')', null, 'Organisation', true);
    }

    /** @return list<SettingDefinition> */
    private static function editable(SettingDefinitions $defs, string $group): array
    {
        return array_values(array_filter($defs->group($group), fn (SettingDefinition $d) => $d->editable));
    }

    /** @return array<string, mixed> a secret is only reported as set, never sent */
    private static function field(SettingDefinition $d, Settings $settings): array
    {
        $field = ['key' => $d->key, 'type' => $d->type];
        if ($d->secret) {
            return $field + ['secret' => true, 'is_set' => (string) $settings->get($d->key, '') !== ''];
        }

        return $field + ['value' => $settings->get($d->key), 'options' => $d->options, 'deletable_only' => $d->deletableOnly];
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'ja' : 'nein',
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }
}

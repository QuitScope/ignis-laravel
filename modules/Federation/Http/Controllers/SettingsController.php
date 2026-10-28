<?php

namespace Modules\Federation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Federation\Jobs\SyncLink;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Models\SyncRun;
use Modules\Federation\Support\Pairing;

/**
 * Einstellungen › Vernetzung (legacy settings/federation, system.admin). Instance name and cache days live in the
 * config tab `federation`. Invite codes, tokens and secrets never reach a page prop, the session, the audit or a log:
 * the token comes back once as JSON (like the API key), errors only as codes.
 */
class SettingsController extends Controller
{
    private const FLAGS = ['consume_personnel', 'consume_enotf', 'consume_fire', 'provide_personnel', 'provide_enotf', 'provide_fire'];

    public function index(Settings $settings): Response
    {
        $this->authorize('system.admin');
        $runs = SyncRun::query()->where('created_at', '>=', now()->subDay())->orderByDesc('id')->limit(200)->get()->groupBy('link_id');

        return Inertia::render('Federation/Settings', [
            'instance' => ['id' => (string) $settings->get('federation.instance_id'), 'name' => Pairing::name()],
            'links' => FederationLink::query()->orderBy('name')->get()->map(fn (FederationLink $l) => [
                'id' => $l->id, 'instance_id' => $l->instance_id, 'name' => $l->name, 'url' => $l->url,
                ...$l->only(self::FLAGS),
                'sync_interval_minutes' => $l->sync_interval_minutes, 'is_active' => $l->is_active, 'confirmed' => $l->confirmed_at !== null,
                'last_sync_at' => $l->last_sync_at?->toIso8601String(), 'last_sync_status' => $l->last_sync_status, 'last_sync_error' => $l->last_sync_error,
                'runs' => ($runs[$l->id] ?? collect())->take(6)->map(fn (SyncRun $r) => [
                    'type' => $r->type, 'status' => $r->status, 'records' => $r->records, 'error' => $r->error, 'at' => $r->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->all(),
        ]);
    }

    /** POST /settings/federation/invite: a new one-time token (replaces an open one), 15 min. */
    public function invite(Request $request): JsonResponse
    {
        $this->authorize('system.admin');
        $token = Pairing::invite();
        AuditLogger::log($request->user(), 'Kopplungseinladung erzeugt', null, 'Federation', true);

        return response()->json(['token' => $token, 'minutes' => Pairing::TTL_MINUTES])->header('Cache-Control', 'no-store, private');
    }

    /** POST /settings/federation/pair: side B accepts a token from another instance. */
    public function pair(Request $request): RedirectResponse
    {
        $this->authorize('system.admin');
        $token = $request->validate(['token' => ['required', 'string', 'max:4000']])['token'];
        $link = Pairing::accept($token);
        if (is_string($link)) {
            return back()->withErrors(['token' => self::error($link)]);
        }
        AuditLogger::log($request->user(), 'Instanz gekoppelt', $link->name, 'Federation', true, ['link_id' => $link->id, 'instance_id' => $link->instance_id]);

        return redirect('/settings/federation')->with('status', $link->confirmed_at !== null ? 'federation.settings.paired' : 'federation.settings.paired_unconfirmed');
    }

    public function update(Request $request, FederationLink $link): RedirectResponse
    {
        $this->authorize('system.admin');
        $data = $request->validate([
            ...array_fill_keys(self::FLAGS, ['required', 'boolean']),
            'is_active' => ['required', 'boolean'],
            'sync_interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);
        foreach ([...self::FLAGS, 'is_active'] as $flag) {
            $link->{$flag} = (bool) $data[$flag];
        }
        $link->sync_interval_minutes = (int) $data['sync_interval_minutes'];
        $changed = array_keys($link->getDirty());
        $link->save();
        if ($changed !== []) {
            AuditLogger::log($request->user(), 'Verbindung geändert', $link->name.': '.implode(', ', $changed), 'Federation', true, ['link_id' => $link->id, 'instance_id' => $link->instance_id]);
        }

        return back()->with('status', 'federation.settings.saved');
    }

    public function destroy(Request $request, FederationLink $link): RedirectResponse
    {
        $this->authorize('system.admin');
        AuditLogger::log($request->user(), 'Verbindung entfernt', $link->name, 'Federation', true, ['link_id' => $link->id, 'instance_id' => $link->instance_id]);
        $link->delete();

        return redirect('/settings/federation')->with('status', 'federation.settings.deleted');
    }

    public function sync(Request $request, FederationLink $link): RedirectResponse
    {
        $this->authorize('system.admin');
        SyncLink::dispatch($link->id);
        AuditLogger::log($request->user(), 'Synchronisation angestoßen', $link->name, 'Federation', true, ['link_id' => $link->id, 'instance_id' => $link->instance_id]);

        return back()->with('status', 'federation.settings.sync_queued');
    }

    public static function error(string $code): string
    {
        $key = "federation.error.$code";
        $text = __($key);

        return $text === $key ? __('federation.error.generic', ['code' => $code]) : $text;
    }
}

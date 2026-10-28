<?php

namespace App\Http\Middleware;

use App\Announcements\AnnouncementFeed;
use App\Auth\Permissions;
use App\Branding\LogoUpload;
use App\Branding\Theme;
use App\Discord\DmRecipients;
use App\Models\FiveMCharacter;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Navigation\NavigationCounters;
use App\Notifications\NotificationKinds;
use App\Support\Settings;
use App\System\PendingMigrations;
use App\Update\InstalledVersion;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => app(Settings::class)->get('system.name') ?: config('app.name'),
                'version' => InstalledVersion::current(),
                'modules' => fn () => $request->user() ? array_keys(array_filter(app(ModuleRegistry::class)->switches())) : [],
            ],
            'branding' => Theme::branding(app(Settings::class), app(LogoUpload::class)),
            'legal' => Theme::legal(app(Settings::class)),
            'auth' => ['user' => fn () => $this->user($request->user())],
            'flash' => [
                'status' => fn () => $request->hasSession() ? $request->session()->get('status') : null,
                'status_params' => fn () => $request->hasSession() ? $request->session()->get('status_params') : null,
            ],
            'notifications' => [
                'unread' => fn () => $request->user() instanceof User
                    ? app(NotificationKinds::class)->visibleTo($request->user())->reorder()->whereNull('read_at')->count()
                    : null,
            ],
            'nav' => [
                'counters' => fn () => app(NavigationCounters::class)->for($request->user() instanceof User ? $request->user() : null),
            ],
            'announcements' => fn () => $request->user() instanceof User ? app(AnnouncementFeed::class)->visibleTo($request->user()) : [],
            'system' => [
                'pending_migrations' => function () use ($request) {
                    try {
                        return $request->user()?->can('system.admin') ? app(PendingMigrations::class)->names() : [];
                    } catch (Throwable) {
                        return [];
                    }
                },
            ],
            // One-time token for ef_bridge's character identify, only signed in inside FiveM's CEF (FiveMCharacter);
            // the layouts post it as `ignis_session` (resources/js/lib/fivemIdentify.ts).
            'fivem' => ['identifyToken' => fn () => FiveMCharacter::tokenFor($request)],
            // Realtime: null = off, pages poll exactly as before. Runtime values, not VITE_REVERB_*: images and
            // release archives are built before install. Same origin as the app (nginx proxies /app to Reverb); the
            // public `key` only, never the secret.
            'realtime' => fn () => $request->user() instanceof User ? self::realtime() : null,
            'locale' => app()->getLocale(),
            'theme' => $request->user() instanceof User ? User::themeOrDefault($request->user()->theme) : 'system',
            'discordDm' => fn () => $request->user() instanceof User ? app(DmRecipients::class)->preference($request->user()) : null,
        ];
    }

    /**
     * @return array{key: string, host: string, port: int, scheme: string, path: string}|null
     */
    private static function realtime(): ?array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }
        $url = parse_url((string) config('app.url'));
        $scheme = ($url['scheme'] ?? 'http') === 'https' ? 'https' : 'http';

        return [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'host' => (string) ($url['host'] ?? 'localhost'),
            'port' => (int) ($url['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'scheme' => $scheme,
            'path' => rtrim((string) ($url['path'] ?? ''), '/'),
        ];
    }

    /**
     * Exactly these fields reach the browser, nothing else of the user.
     *
     * @return array{id: int, name: ?string, username: string, role: array{name: string, color: string}|null, is_super_admin: bool, permissions: list<string>, personnel: array{id: int, full_name: string}|null}|null
     */
    private function user(mixed $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $role = $user->role();
        $permissions = $user->is_super_admin
            ? array_keys(Permissions::catalog())
            : $user->getAllPermissions()->pluck('name')->reject(Permissions::hidden(...))->values()->all();
        sort($permissions);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $role ? ['name' => $role->name, 'color' => $role->color] : null,
            'is_super_admin' => $user->is_super_admin,
            'permissions' => $permissions,
            'personnel' => $user->personnel ? ['id' => $user->personnel->id, 'full_name' => $user->personnel->full_name] : null,
        ];
    }
}

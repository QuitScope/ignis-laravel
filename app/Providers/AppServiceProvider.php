<?php

namespace App\Providers;

use App\Auth\LoginDecision;
use App\Auth\NoSuperAdminBypass;
use App\Auth\Permissions;
use App\Dashboard\DashboardWidgets;
use App\Dashboard\IncidentStats;
use App\Dashboard\Widgets\AnnouncementsWidget;
use App\Dashboard\Widgets\ChecklistWidget;
use App\Dashboard\Widgets\HintsWidget;
use App\Dashboard\Widgets\IncidentsKpiWidget;
use App\Dashboard\Widgets\IncidentsWidget;
use App\Dashboard\Widgets\OwnDocumentsWidget;
use App\Dashboard\Widgets\VehicleKpiWidget;
use App\Dashboard\Widgets\VehiclesWidget;
use App\Discord\DiscordBot;
use App\Discord\WebhookKind;
use App\Discord\WebhookUrlRule;
use App\Import\LegacyReader;
use App\Models\Document;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Navigation\NavigationCounters;
use App\Notifications\NotificationKinds;
use App\Policies\DocumentPolicy;
use App\Policies\PersonnelPolicy;
use App\Policies\PersonnelSettingsPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Policies\VehiclePolicy;
use App\Realtime\Realtime;
use App\Search\SearchRegistry;
use App\Search\Sources\DefectSource;
use App\Search\Sources\DocumentSource;
use App\Search\Sources\PersonnelSource;
use App\Search\Sources\TemplateSource;
use App\Search\Sources\VehicleSource;
use App\Search\VocabularyCache;
use App\Support\OwnProtocols;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use App\System\PendingMigrations;
use App\System\RunHistoryListener;
use App\Update\DockerDriver;
use App\Update\FlatSwapper;
use App\Update\Installation;
use App\Update\ProcessUpdateStarter;
use App\Update\Swapper;
use App\Update\SymlinkSwapper;
use App\Update\UpdateStarter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /** Ability prefixes super admins do not pass automatically: mail is reached only through the participant scope. */
    public const NO_SUPER_ADMIN_BYPASS = ['mail.message', 'mail.delivery'];

    public function register(): void
    {
        self::checkTimezone();
        $this->app->singleton(Settings::class);
        $this->app->singleton(RunHistoryListener::class);
        $this->app->singleton(SettingDefinitions::class, function () {
            $defs = new SettingDefinitions;
            $defs->register('system.name', 'general', 'string', '', ['max:100']);
            $defs->register('system.accent_color', 'general', 'color', '#f0500a');
            $defs->register('system.logo', 'general', 'string', '', editable: false);
            $defs->register('system.meta_image_url', 'general', 'url', '');
            $defs->register('app.locale', 'general', 'enum', 'de', options: config('ignis.locales'));
            foreach (['name', 'type', 'city', 'street', 'zip'] as $field) {
                $defs->register("organisation.{$field}", 'organisation', 'string', '');
            }
            $defs->register('auth.registration_mode', 'auth', 'enum', 'code', options: LoginDecision::MODES);
            $defs->register('auth.tablet_login', 'auth', 'bool', false);
            $defs->register('discord.client_id', 'auth', 'string', '', ['digits_between:17,20']);
            $defs->register('discord.client_secret', 'auth', 'string', '', secret: true);
            $defs->register('discord.redirect_path', 'auth', 'string', '/auth/discord/callback', ['max:100', 'regex:~^/[A-Za-z0-9/._-]*$~']);
            $defs->register('install.admin_discord_id', 'auth', 'string', '', deletableOnly: true);
            $defs->register('api.key', 'interfaces', 'string', '', secret: true, editable: false);
            $defs->register('personnel.require_character_id', 'features', 'bool', false);
            $defs->register('legal.imprint_url', 'legal', 'url', '');
            $defs->register('legal.privacy_url', 'legal', 'url', '');
            $defs->register('announcements.hub_enabled', 'privacy', 'bool', false, alwaysStore: true);
            $defs->register('announcements.website_enabled', 'privacy', 'bool', false, alwaysStore: true);
            $defs->register('updates.check_enabled', 'privacy', 'bool', false, alwaysStore: true);
            foreach (['latest_version', 'release_url', 'checked_at', 'last_error'] as $field) {
                $defs->register("updates.{$field}", 'privacy', 'string', '', editable: false);
            }
            $defs->register('discord.bot.enabled', 'discord', 'bool', false, editable: false);
            $defs->register('discord.bot.token', 'discord', 'string', '', secret: true, editable: false);
            foreach (['id', 'name', 'avatar'] as $field) {
                $defs->register("discord.bot.{$field}", 'discord', 'string', '', editable: false);
            }
            $defs->register('discord.dm_kinds', 'discord', 'list', DiscordBot::DEFAULT_DM_KINDS, editable: false);
            foreach (WebhookKind::cases() as $kind) {
                $defs->register($kind->settingKey(), 'discord', 'string', '', [WebhookUrlRule::make()], secret: true, editable: false);
            }

            return $defs;
        });
        $this->app->singleton(NotificationKinds::class, function () {
            $kinds = new NotificationKinds;
            $kinds->register('dokument', ['label' => 'notifications.kind.dokument', 'icon' => 'folder-open']);
            $kinds->register('vehicle_defect', ['label' => 'notifications.kind.vehicle_defect', 'icon' => 'wrench',
                'ability' => fn (User $u) => $u->hasPermission(['admin', 'vehicles.view', 'vehicles.manage'])]);
            $kinds->register('system', ['label' => 'notifications.kind.system', 'icon' => 'settings']);

            return $kinds;
        });
        $this->app->singleton(SearchRegistry::class, function ($app) {
            $registry = new SearchRegistry($app->make(VocabularyCache::class));
            foreach ([PersonnelSource::class, DocumentSource::class, TemplateSource::class, VehicleSource::class, DefectSource::class] as $source) {
                $registry->register(new $source);
            }

            return $registry;
        });
        $this->app->singleton(OwnProtocols::class);
        $this->app->singleton(NavigationCounters::class);
        $this->app->singleton(HintsWidget::class);
        $this->app->singleton(IncidentStats::class);
        $this->app->singleton(DashboardWidgets::class, function ($app) {
            $widgets = new DashboardWidgets;
            foreach ([ChecklistWidget::class, IncidentsKpiWidget::class, IncidentsWidget::class, VehicleKpiWidget::class, VehiclesWidget::class, OwnDocumentsWidget::class, HintsWidget::class, AnnouncementsWidget::class] as $widget) {
                $widgets->register($app->make($widget));
            }

            return $widgets;
        });
        $this->app->bind(LegacyReader::class, fn () => LegacyReader::open());
        $this->app->bind(Swapper::class, fn () => is_link(Installation::appPath()) ? new SymlinkSwapper(Installation::appPath()) : new FlatSwapper);
        $this->app->bind(UpdateStarter::class, fn ($app) => $app->make(Installation::driver() === 'docker' ? DockerDriver::class : ProcessUpdateStarter::class));
        // Order matters: Permissions must be bound before registerAll() below, modules register their rights on it.
        $this->app->singleton(Permissions::class);
        $this->app->singleton(ModuleRegistry::class, fn ($app) => new ModuleRegistry(config('ignis.modules_path'), $app->make(Settings::class)));
        $this->app->make(ModuleRegistry::class)->registerAll($this->app);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        RateLimiter::for('emd-sync', fn () => Limit::perMinute(120)->by('emd-sync'));
        RateLimiter::for('character-identify', fn () => Limit::perMinute(120)->by('character-identify'));
        RateLimiter::for('broadcast-auth', fn (Request $request) => Limit::perMinute(60)->by('broadcast-auth:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        Event::listen(NotificationSent::class, function (NotificationSent $e): void {
            if ($e->channel === 'database' && $e->notifiable instanceof User) {
                Realtime::pulse('user.'.$e->notifiable->id, 'inbox');
            }
        });
        Event::listen([ScheduledTaskStarting::class, ScheduledTaskFinished::class, ScheduledTaskFailed::class, ScheduledTaskSkipped::class], fn ($e) => $this->app->make(RunHistoryListener::class)->handle($e));
        // Secrets never come back as flashed old input, whichever form failed (registered by the core or a module).
        $secrets = array_keys(array_filter($this->app->make(SettingDefinitions::class)->all(), fn ($d) => $d->secret));
        $this->app->afterResolving(ExceptionHandler::class, function (object $handler) use ($secrets) {
            if ($handler instanceof Handler) {
                $handler->dontFlash($secrets);
            }
        });
        Event::listen(SocialiteWasCalled::class, [DiscordExtendSocialite::class, 'handle']);
        Event::listen(MigrationsEnded::class, fn () => Cache::forget(PendingMigrations::CACHE_KEY));
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Personnel::class, PersonnelPolicy::class);
        Gate::define('personnel-settings.viewAny', [PersonnelSettingsPolicy::class, 'viewAny']);
        Gate::define('personnel-settings.manage', [PersonnelSettingsPolicy::class, 'manage']);
        Gate::policy(Document::class, DocumentPolicy::class);
        foreach (['view', 'manage', 'viewAudit', 'viewIssued', 'delete'] as $ability) {
            Gate::define('document.'.$ability, [DocumentPolicy::class, $ability]);
        }
        Gate::define('document.viewPdf', [DocumentPolicy::class, 'open']);
        foreach (['view', 'manage', 'createDefect', 'deleteDefect', 'manageImport'] as $ability) {
            Gate::define('vehicle.'.$ability, [VehiclePolicy::class, $ability]);
        }
        Gate::define('system.manageDashboard', fn (User $u) => $u->hasPermission(['admin', 'dashboard.manage']));
        Gate::define('poi.view', fn (User $u) => $u->hasPermission(['admin', 'pois.view', 'pois.manage']));
        Gate::define('poi.manage', fn (User $u) => $u->hasPermission(['admin', 'pois.manage']));
        Gate::define('system.admin', fn (User $u) => $u->hasPermission(['admin']));
        Gate::before(fn (?User $user, string $ability, array $args) => $user?->is_super_admin && self::superAdminBypasses($ability, $args) ? true : null);
    }

    /**
     * Whether a super admin passes $ability without asking it. Not for UserPolicy's checks (about another account,
     * they bind super admins by rank), abilities starting with one of NO_SUPER_ADMIN_BYPASS (plain prefix, so it fails
     * closed) and policies marked NoSuperAdminBypass; those decide themselves.
     *
     * @param  array<int, mixed>  $args
     */
    public static function superAdminBypasses(string $ability, array $args): bool
    {
        $target = $args[0] ?? null;
        if ($target instanceof User && method_exists(UserPolicy::class, Str::camel($ability))) {
            return false;
        }
        if (Str::startsWith($ability, self::NO_SUPER_ADMIN_BYPASS)) {
            return false;
        }
        $policy = is_object($target) || (is_string($target) && class_exists($target)) ? Gate::getPolicyFor($target) : null;

        return ! $policy instanceof NoSuperAdminBypass;
    }

    public static function checkTimezone(): void
    {
        $zone = config('ignis.timezone');
        if (! is_string($zone) || ! in_array($zone, timezone_identifiers_list(), true)) {
            Log::warning('IGNIS_TIMEZONE is not a valid time zone, using Europe/Berlin.');
            config(['ignis.timezone' => 'Europe/Berlin']);
        }
    }
}

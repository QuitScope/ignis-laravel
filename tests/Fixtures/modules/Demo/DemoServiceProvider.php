<?php

namespace Tests\Fixtures\Modules\Demo;

use App\Auth\Permissions;
use App\Dashboard\DashboardWidgets;
use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\BelongsToModule;
use App\Modules\ModuleCommand;
use App\Modules\ModuleProvider;
use App\Notifications\NotificationKinds;
use App\Search\SearchRegistry;
use App\Search\SearchSource;
use App\Support\SettingDefinitions;
use Illuminate\Console\Scheduling\Schedule;

/** A module that uses every hook (docs/dev/modules.md); it depends on Beta, Alpha is a core module. */
class DemoServiceProvider extends ModuleProvider
{
    /** Calls of the `demo.ping` listener. */
    public static int $pings = 0;

    public function moduleName(): string
    {
        return 'Demo';
    }

    public function label(): string
    {
        return 'demo.label';
    }

    public function description(): string
    {
        return 'demo.description';
    }

    public function group(): string
    {
        return 'einsatz';
    }

    public function depends(): array
    {
        return ['beta', 'gamma']; // gamma is not installed: ignored
    }

    public function register(): void
    {
        $this->app->make(SearchRegistry::class)->register(new class implements BelongsToModule, SearchSource
        {
            public function module(): string
            {
                return 'demo';
            }

            public function key(): string
            {
                return 'demo';
            }

            public function label(): string
            {
                return 'Demo';
            }

            public function allowed(User $user): bool
            {
                return true;
            }

            public function search(User $user, string $q, int $limit): array
            {
                return [['label' => 'Demo-Treffer', 'sub' => '', 'href' => '/demo/ping']];
            }

            public function vocabulary(): ?array
            {
                return null;
            }

            public function fuzzyAllowed(User $user): bool
            {
                return false;
            }
        });
        $this->app->make(DashboardWidgets::class)->register(new class implements BelongsToModule, Widget
        {
            public function module(): string
            {
                return 'demo';
            }

            public function key(): string
            {
                return 'demo';
            }

            public function area(): string
            {
                return 'side';
            }

            public function order(): int
            {
                return 99;
            }

            public function allowed(User $user): bool
            {
                return true;
            }

            public function data(User $user): ?array
            {
                return ['pong' => true];
            }
        });
        $this->app->make(NotificationKinds::class)->register('demo_ping', ['label' => 'demo.label', 'icon' => 'bell', 'module' => 'demo']);
        $this->app->make(SettingDefinitions::class)->register('demo.greeting', 'demo', 'string', 'Hallo', module: 'demo');
        Permissions::register('demo.manage', 'demo', 'demo');
    }

    public function boot(): void
    {
        parent::boot();

        $this->listen('demo.ping', fn () => self::$pings++);
        $this->commands([new class extends ModuleCommand
        {
            protected $signature = 'demo:hello';

            public function module(): string
            {
                return 'demo';
            }

            public function handle(): int
            {
                $this->line('hallo');

                return self::SUCCESS;
            }
        }]);
    }

    /** Gated by the provider: runs only while the module is on. */
    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => null)->name('demo-tick')->hourly();
    }
}

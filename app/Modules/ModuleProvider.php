<?php

namespace App\Modules;

use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base of every module provider (docs/dev/modules.md). Always registered: migrations and translations
 * load in both states, the route files are wrapped in the gate `module:<name>`, which answers 404 while the switch is off.
 * schedule() and listen() are gated the same way; commands extend ModuleCommand. Anything else a module registers
 * (observers, policies, jobs, broadcast channels) checks ModuleRegistry::active() itself.
 * The metadata methods feed the modules page; override what differs from the defaults.
 */
abstract class ModuleProvider extends ServiceProvider
{
    abstract public function moduleName(): string;

    public function label(): string
    {
        return $this->moduleName();
    }

    public function description(): string
    {
        return '';
    }

    public function group(): string
    {
        return 'verwaltung';
    }

    /** @return list<string> names of the modules this one needs switched on */
    public function depends(): array
    {
        return [];
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    public function core(): bool
    {
        return false;
    }

    public function schedule(Schedule $schedule): void {}

    final public function moduleKey(): string
    {
        return strtolower(basename($this->moduleDir()));
    }

    /**
     * Event::listen() for a module: the listener is skipped (not even queued) while the module is off.
     *
     * @param  string|array{0: class-string|object, 1: string}|Closure  $listener
     */
    protected function listen(string $event, string|array|Closure $listener): void
    {
        $events = $this->app->make(Dispatcher::class);
        $handler = $events->makeListener($listener);
        $events->listen($event, fn (mixed ...$payload) => ModuleRegistry::active($this->moduleKey()) ? $handler($event, $payload) : null);
    }

    public function boot(): void
    {
        $dir = $this->moduleDir();
        $gate = 'module:'.$this->moduleKey();

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $before = count($schedule->events());
            $this->schedule($schedule);
            foreach (array_slice($schedule->events(), $before) as $event) {
                $event->when(ModuleRegistry::whenEnabled($this->moduleKey()));
            }
        });

        if (! $this->app->routesAreCached()) {
            if (is_file("$dir/routes/web.php")) {
                Route::middleware([$gate, 'web'])->group("$dir/routes/web.php");
            }

            if (is_file("$dir/routes/api.php")) {
                Route::middleware([$gate, 'api'])->group("$dir/routes/api.php");
            }
        }

        if (is_dir("$dir/database/migrations")) {
            $this->loadMigrationsFrom("$dir/database/migrations");
        }

        if (is_dir("$dir/lang")) {
            $this->loadJsonTranslationsFrom("$dir/lang");
        }
    }

    private function moduleDir(): string
    {
        return dirname((string) (new ReflectionClass(static::class))->getFileName());
    }
}

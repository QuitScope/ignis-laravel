<?php

namespace App\Modules;

use App\Support\Settings;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final class ModuleRegistry
{
    /** @var array<string, array<string, class-string<ModuleProvider>>> folder scans per path, kept for the process */
    private static array $discovered = [];

    /** @var array<string, ModuleInfo>|null */
    private ?array $modules = null;

    public function __construct(private readonly string $path, private readonly Settings $settings) {}

    /**
     * Scanned once per process and path (a queue worker or a test run boots the app many times); a new module needs a
     * new process anyway. ponytail: per-request cost in PHP-FPM stays one glob + token scan; cache to a file if it shows up.
     *
     * @return array<string, class-string<ModuleProvider>> folder name => provider class
     */
    public function discover(): array
    {
        return self::$discovered[$this->path] ??= $this->scan();
    }

    public static function flushDiscovery(): void
    {
        self::$discovered = [];
    }

    /** @return list<string> folder names of the modules that can be switched (the installer's choices) */
    public function switchable(): array
    {
        return array_values(array_filter(array_keys($this->discover()), fn (string $folder) => ! $this->all()[strtolower($folder)]->core));
    }

    /** @return array<string, class-string<ModuleProvider>> */
    private function scan(): array
    {
        $modules = [];

        foreach (glob($this->path.'/*/*ServiceProvider.php') ?: [] as $file) {
            require_once $file;

            $name = basename(dirname($file));
            $class = $this->classIn($file)
                ?? throw new RuntimeException("No class found in module provider file [$file].");

            if (is_subclass_of($class, ModuleProvider::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $modules[$name] = $class;
            }
        }

        ksort($modules);

        return $modules;
    }

    /**
     * The metadata each provider declares: taken from the instances registerAll() registered, else read once per registry.
     *
     * @return array<string, ModuleInfo> name => info, sorted by name
     */
    public function all(): array
    {
        return $this->modules ??= self::describe(array_map(fn (string $class) => new $class(app()), $this->discover()));
    }

    /**
     * Core modules always; others by `modules.<name>.enabled` (unset = the provider's defaultEnabled()). An unknown name is off, so a gate or hook
     * naming a module that is not installed never opens. Settings are memoised per request, so this is cheap.
     */
    public function isEnabled(string $name): bool
    {
        $module = $this->all()[strtolower($name)] ?? null;

        return $module !== null && ($module->core || (bool) $this->settings->get("modules.{$module->name}.enabled", $module->default_on));
    }

    /** @return array<string, bool> name => on, for the modules that can be switched (the `app.modules` prop) */
    public function switches(): array
    {
        $switches = [];
        foreach ($this->all() as $name => $module) {
            if (! $module->core) {
                $switches[$name] = $this->isEnabled($name);
            }
        }

        return $switches;
    }

    /**
     * Dependencies a state would break (legacy ModuleSelection::validate): a module that is on (core modules always)
     * needs every installed, switchable module it names on. Names of modules that are not installed are ignored.
     *
     * @param  array<string, bool>  $state  name => on for the switchable modules; missing = off
     * @return list<array{0: string, 1: string}> [module, dependency]
     */
    public function unmetDependencies(array $state): array
    {
        $all = $this->all();
        $unmet = [];
        foreach ($all as $name => $module) {
            if (! $module->core && ! ($state[$name] ?? false)) {
                continue;
            }
            foreach ($module->depends as $dependency) {
                if (isset($all[$dependency]) && ! $all[$dependency]->core && ! ($state[$dependency] ?? false)) {
                    $unmet[] = [$name, $dependency];
                }
            }
        }

        return $unmet;
    }

    public static function active(?string $module): bool
    {
        return $module === null || app(self::class)->isEnabled($module);
    }

    public static function whenEnabled(string $module): Closure
    {
        return fn (): bool => app(self::class)->isEnabled($module);
    }

    public function registerAll(Application $app): void
    {
        $providers = [];
        foreach ($this->discover() as $folder => $class) {
            $provider = $app->register($class);
            if ($provider instanceof ModuleProvider) {
                $providers[$folder] = $provider;
            }
        }
        $this->modules = self::describe($providers);
    }

    /**
     * @param  array<string, ModuleProvider>  $providers  folder name => instance
     * @return array<string, ModuleInfo>
     */
    private static function describe(array $providers): array
    {
        $modules = [];
        foreach ($providers as $folder => $provider) {
            $name = strtolower($folder);
            $modules[$name] = new ModuleInfo(
                $name,
                $provider->label(),
                $provider->description(),
                $provider->group() === 'einsatz' ? 'einsatz' : 'verwaltung',
                array_map('strtolower', $provider->depends()),
                $provider->core(),
                $provider->defaultEnabled(),
            );
        }

        return $modules;
    }

    /**
     * @return class-string|null
     */
    private function classIn(string $file): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $prev = null;

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE && $prev !== T_DOUBLE_COLON) {
                $namespace = '';
                for ($j = $i + 1; isset($tokens[$j]) && is_array($tokens[$j]); $j++) {
                    if (in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $tokens[$j][1];
                    }
                }
            } elseif ($token[0] === T_CLASS && ! in_array($prev, [T_DOUBLE_COLON, T_NEW], true)) {
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        /** @var class-string */
                        return ($namespace !== '' ? $namespace.'\\' : '').$tokens[$j][1];
                    }
                }
            }

            $prev = $token[0];
        }

        return null;
    }
}

<?php

namespace App\Console\Commands;

use App\Support\HealthChecks;
use App\Update\InstalledVersion;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Throwable;

/**
 * The health probe the updater runs as a child process in the code it checks (App\Update\HealthGate): booting this
 * command at all proves the version starts. Prints one JSON line {version, down, queue, pending, http}; no secrets, only
 * check names and counts. `http` is the status /healthz answers through this code's HTTP kernel (routing, middleware,
 * providers of the web side), so a release whose web requests break fails too; the response goes nowhere.
 *
 * The keys are a contract between versions: the HealthGate of the version that runs an update
 * reads the probe of the next one. Only ever add keys; `http` was added after the first release and is optional there.
 */
class UpdateHealthCommand extends Command
{
    protected $signature = 'ignis:update:health';

    protected $description = 'Health probe for the self-updater (JSON)';

    protected $hidden = true;

    public function handle(): int
    {
        $report = HealthChecks::run(Request::create('/healthz'));
        $down = [];
        foreach ($report['checks'] as $name => $check) {
            if (! is_array($check) || ($check['status'] ?? 'down') === 'down') {
                $down[] = (string) $name;
            }
        }
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            $pending = -1;
        }
        $this->line((string) json_encode([
            'version' => InstalledVersion::current(base_path()),
            'down' => $down,
            'queue' => $report['checks']['queue']['status'] ?? 'down',
            'pending' => $pending,
            'http' => $this->http(),
        ]));

        return self::SUCCESS;
    }

    private function http(): int
    {
        try {
            $kernel = app(HttpKernel::class);
            $request = Request::create('/healthz', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
            $status = $kernel->handle($request)->getStatusCode();
            app()->forgetInstance('request');

            return $status;
        } catch (Throwable) {
            return 0;
        }
    }
}

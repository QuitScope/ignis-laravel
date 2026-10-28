<?php

namespace App\Console\Commands;

use App\Auth\ApiKey;
use App\Install\InstallState;
use App\Support\HealthChecks;
use App\System\PendingMigrations;
use App\System\SystemOverview;
use Illuminate\Console\Command;
use Throwable;

/**
 * Go-live check of the local configuration and runtime. Nothing external is
 * contacted except the configured Reverb socket. `detail` never carries secrets: only states, counts and names.
 * Exit 1 when any check fails.
 */
class PreflightCommand extends Command
{
    protected $signature = 'ignis:preflight {--format=table : table or json}';

    protected $description = 'Check the production configuration before go-live (ok/warn/fail)';

    private const HEARTBEAT_MAX_AGE = 120;

    /** @var list<array{check: string, status: string, detail: string}> */
    private array $rows = [];

    public function handle(ApiKey $apiKey, InstallState $install, PendingMigrations $migrations, SystemOverview $overview): int
    {
        $env = (string) config('app.env');
        $this->add('app_env', $env === 'production' ? 'ok' : 'warn', $env);
        $this->add('app_debug', config('app.debug') ? 'fail' : 'ok', config('app.debug') ? 'APP_DEBUG=true' : 'APP_DEBUG=false');

        $url = (string) config('app.url');
        $this->add('app_url', match (true) {
            ! str_starts_with($url, 'https://') => 'warn',
            str_ends_with($url, '/') => 'warn',
            default => 'ok',
        }, str_starts_with($url, 'https://') ? (str_ends_with($url, '/') ? 'trailing slash' : 'https') : 'not https');

        $this->add('session_secure', config('session.secure') ? 'ok' : 'warn', config('session.secure') ? 'SESSION_SECURE_COOKIE=true' : 'SESSION_SECURE_COOKIE is not true');

        $debugChannels = array_filter($this->logChannels(), fn (string $c) => config("logging.channels.$c.level") === 'debug');
        $this->add('log_level', $debugChannels === [] ? 'ok' : 'warn', $debugChannels === [] ? 'no channel on debug' : 'debug: '.implode(', ', $debugChannels));

        $installed = $install->isInstalled();
        $tokenSet = filled(config('ignis.install_token'));
        $this->add('install_token', $installed && $tokenSet ? 'warn' : 'ok', match (true) {
            ! $installed => 'not installed yet',
            $tokenSet => 'installed: clear IGNIS_INSTALL_TOKEN',
            default => 'empty',
        });

        $legacy = filled(config('database.connections.legacy.password'));
        $this->add('legacy_db', $legacy ? 'warn' : 'ok', $legacy ? 'LEGACY_DB_PASSWORD set: remove after the cutover' : 'not set');

        $federation = filled(config('ignis.federation.allowed_hosts')) || config('ignis.federation.allow_private');
        $this->add('federation_exceptions', $federation ? 'warn' : 'ok', $federation ? 'FEDERATION_ALLOWED_HOSTS or FEDERATION_ALLOW_PRIVATE set' : 'none');

        $this->reverb();

        $health = HealthChecks::run(request())['checks'];
        unset($health['rewrite']);
        foreach ($health as $key => $check) {
            $state = (string) ($check['status'] ?? 'down');
            $detail = $state === 'ok' ? 'ok' : (string) ($check['error'] ?? implode(', ', (array) ($check['missing'] ?? [])));
            $this->add('health_'.$key, match ($state) {
                'ok' => 'ok',
                'degraded' => 'warn',
                default => 'fail',
            }, $detail);
        }
        $failed = (int) ($health['queue']['failed'] ?? 0);
        $this->add('failed_jobs', $failed > 0 ? 'warn' : 'ok', (string) $failed);

        $scheduler = $overview->scheduler();
        $age = $scheduler['age_seconds'];
        $this->add('scheduler', $age !== null && $age < self::HEARTBEAT_MAX_AGE ? 'ok' : 'warn', $age === null ? 'no heartbeat' : "last heartbeat {$age}s ago");

        $pending = $migrations->names();
        $this->add('migrations', $pending === [] ? 'ok' : 'fail', $pending === [] ? 'none pending' : count($pending).' pending');

        $hasKey = $this->probe(fn () => $apiKey->isConfigured());
        $this->add('api_key', $hasKey ? 'ok' : 'warn', $hasKey ? 'set' : 'not set (php artisan ignis:api-key)');

        if (is_file(base_path('VERSION'))) {
            $placeholder = in_array(config('ignis.update.public_key'), ['', 'PLACEHOLDER'], true);
            $this->add('update_public_key', $placeholder ? 'warn' : 'ok', $placeholder ? 'PLACEHOLDER: no update by click' : 'set');
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($this->rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['check', 'status', 'detail'], $this->rows);
        }

        return in_array('fail', array_column($this->rows, 'status'), true) ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $check, string $status, string $detail): void
    {
        $this->rows[] = compact('check', 'status', 'detail');
    }

    private function reverb(): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            $this->add('reverb', 'ok', 'off');

            return;
        }
        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');
        $socket = @fsockopen($host, $port, $errno, $error, 2);
        if ($socket !== false) {
            fclose($socket);
        }
        $this->add('reverb', $socket !== false ? 'ok' : 'warn', $socket !== false ? "reachable on {$host}:{$port}" : "unreachable on {$host}:{$port}");
    }

    /** @return list<string> */
    private function logChannels(): array
    {
        $default = (string) config('logging.default');

        return config("logging.channels.$default.driver") === 'stack'
            ? array_values(array_map('trim', (array) config("logging.channels.$default.channels")))
            : [$default];
    }

    private function probe(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (Throwable) {
            return false;
        }
    }
}

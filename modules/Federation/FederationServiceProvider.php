<?php

namespace Modules\Federation;

use App\Modules\ModuleProvider;
use App\Support\SettingDefinitions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Federation\Console\PruneFederationCommand;
use Modules\Federation\Console\SyncFederationCommand;

/**
 * Federation: read-only exchange of personnel, released eNOTF protocols and fire incidents between ignis instances
 * (legacy src/Federation, protocol v2: signed requests, SSRF-safe client). Reaches other servers, so off by default.
 */
class FederationServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Federation';
    }

    public function label(): string
    {
        return 'federation.label';
    }

    public function description(): string
    {
        return 'federation.description';
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    public function register(): void
    {
        $defs = $this->app->make(SettingDefinitions::class);
        $defs->register('federation.instance_name', 'federation', 'string', '', ['max:100'], module: 'federation');
        $defs->register('federation.cache_days', 'federation', 'int', 90, ['min:7', 'max:365'], module: 'federation');
        $defs->register('federation.instance_id', 'federation', 'string', '', editable: false, module: 'federation');
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('federation:sync')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('federation:prune')->dailyAt('04:00');
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([SyncFederationCommand::class, PruneFederationCommand::class]);

        RateLimiter::for('federation-peer', fn (Request $r) => Limit::perMinute(120)->by('federation-peer|'.($r->attributes->get('federation_link')->id ?? $r->ip())));
    }
}

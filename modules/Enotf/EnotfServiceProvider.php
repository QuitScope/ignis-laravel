<?php

namespace Modules\Enotf;

use App\Auth\Permissions;
use App\Dashboard\DashboardWidgets;
use App\Dashboard\IncidentStats;
use App\Dashboard\Widgets\HintsWidget;
use App\Events\VehicleOutOfService;
use App\Models\User;
use App\Modules\ModuleProvider;
use App\Modules\ModuleRegistry;
use App\Notifications\NotificationKinds;
use App\Search\SearchRegistry;
use App\Support\OwnProtocols;
use App\Support\SettingDefinitions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Enotf\Console\ExpireSharesCommand;
use Modules\Enotf\Console\PrunePatientDataCommand;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Events\ProtocolReleased;
use Modules\Enotf\Listeners\DispatchDiscordOnReleased;
use Modules\Enotf\Listeners\EndCrewsOutOfService;
use Modules\Enotf\Listeners\NotifyCrewOnReleased;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Notifications\ProtocolType;
use Modules\Enotf\Policies\EnotfProtocolPolicy;
use Modules\Enotf\Search\ProtocolSource;
use Modules\Enotf\Support\CrewService;
use Modules\Enotf\Support\LocalDateTimeFormatter;
use Modules\Enotf\Support\VehiclePulse;
use Modules\Enotf\Widgets\OpenProtocolsKpiWidget;
use Modules\Enotf\Widgets\OwnProtocolsWidget;

/** eNOTF: rescue-service patient protocols filled in on the vehicle tablet (FiveM CEF webview). Legacy plugin `enotf`. */
class EnotfServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Enotf';
    }

    public function label(): string
    {
        return 'enotf.label';
    }

    public function description(): string
    {
        return 'enotf.description';
    }

    public function group(): string
    {
        return 'einsatz';
    }

    public function register(): void
    {
        foreach (['edivi.view', 'edivi.edit', 'enotf.view', 'enotf.settings'] as $name) {
            Permissions::register($name, 'rettung', 'enotf');
        }
        $this->app->make(NotificationKinds::class)->register(ProtocolType::KEY, ProtocolType::definition());

        $this->app->make(SearchRegistry::class)->register(new ProtocolSource);
        $this->app->make(DashboardWidgets::class)->register(new OwnProtocolsWidget);
        $this->app->make(DashboardWidgets::class)->register(new OpenProtocolsKpiWidget);
        $qm = fn (User $u) => Gate::forUser($u)->allows('viewQm', EnotfProtocol::class);
        $this->app->make(IncidentStats::class)->addSource('rd', 'enotf', $qm,
            fn () => EnotfProtocol::query()->whereNull('hidden_at')->where('status', '!=', ProtocolStatus::Withdrawn), 'created_at');
        $hints = $this->app->make(HintsWidget::class);
        $hints->addSource('enotf', $qm, function () {
            $f = OpenProtocolsKpiWidget::figures();
            if ($f['open'] === 0) {
                return [];
            }
            $share = $f['week'] > 0 ? (int) round($f['week_released'] / $f['week'] * 100) : null;

            return [array_filter([
                'tone' => 'danger', 'icon' => 'circle-exclamation',
                'title' => trans_choice('enotf.hints.unreleased', $f['open'], ['count' => $f['open']]),
                'text' => $f['oldest_at'] === null ? null : trans_choice('enotf.hints.unreleased_since', $f['open']),
                'since' => $f['oldest_at'],
                'progress' => $share === null ? null : ['value' => $share, 'label' => __('enotf.hints.week_share', ['value' => $share])],
                'action' => ['label' => __('enotf.hints.action'), 'href' => $f['href']],
            ], fn ($v) => $v !== null)];
        }, top: true);
        $hints->addSource('enotf', fn (User $u) => $u->personnel_id !== null, function (User $u) {
            $count = OwnProtocolsWidget::open($u)->count();
            if ($count === 0) {
                return [];
            }
            $first = OwnProtocolsWidget::open($u)->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get();

            return [['tone' => 'info', 'icon' => 'list-check', 'title' => HintsWidget::text('enotf.hints.open', $first->pluck('enr')->all(), $count), 'href' => OwnProtocolsWidget::href($first[0])]];
        });
        $this->app->make(OwnProtocols::class)->register('enotf', 'enotf', 'enotf.search.source', fn (User $u) => $u->personnel_id === null ? [] :
            OwnProtocolsWidget::own($u)->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()->map(fn (EnotfProtocol $p) => [
                'title' => $p->enr, 'sub' => implode(' · ', array_filter([$p->vehicle_transport_label ?: $p->vehicle_na_label, LocalDateTimeFormatter::at($p->created_at)])),
                'href' => OwnProtocolsWidget::href($p),
            ])->all());

        $defs = $this->app->make(SettingDefinitions::class);
        $defs->register('enotf.pin_enabled', 'enotf', 'bool', true, module: 'enotf');
        $defs->register('enotf.pin_timeout', 'enotf', 'int', 5, ['min:2', 'max:60'], module: 'enotf');
        $defs->register('enotf.prereg_enabled', 'enotf', 'bool', true, module: 'enotf');
        $defs->register('enotf.char_lock', 'enotf', 'bool', false, module: 'enotf');
        $defs->register('enotf.job_filter', 'enotf', 'bool', false, module: 'enotf');
        $defs->register('enotf.bz_unit', 'enotf', 'enum', 'mg/dl', options: ['mg/dl', 'mmol/l'], module: 'enotf');
        $defs->register('enotf.personal_data_months', 'enotf', 'int', 12, ['min:0', 'max:120'], module: 'enotf');
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('enotf:prune')->dailyAt('03:30');
        $schedule->command('enotf:share-expire')->hourly();
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([PrunePatientDataCommand::class, ExpireSharesCommand::class]);
        $this->listen(ProtocolReleased::class, NotifyCrewOnReleased::class);
        $this->listen(ProtocolReleased::class, DispatchDiscordOnReleased::class);
        $this->listen(VehicleOutOfService::class, EndCrewsOutOfService::class);
        // The crew's own vehicle only (an ended or locked crew may still subscribe: the pulse carries nothing).
        // Never through the Gate, so a super admin without an own crew is refused.
        Broadcast::channel('enotf.vehicle.{vehicle}', fn (User $user, string $vehicle) => ModuleRegistry::active('enotf') && ctype_digit($vehicle)
            && (int) app(CrewService::class)->member($user)?->crew?->vehicle_id === (int) $vehicle);
        $this->listen('eloquent.saved: '.EnotfProtocol::class, fn (EnotfProtocol $p) => VehiclePulse::send($p->vehicle_transport_id, $p->vehicle_na_id));

        Gate::policy(EnotfProtocol::class, EnotfProtocolPolicy::class);
        RateLimiter::for('enotf-billing', fn () => Limit::perMinute(6)->by('enotf-billing'));
        RateLimiter::for('enotf-hospital-login', fn (Request $r) => Limit::perMinute(5)->by('enotf-hospital-login|'.$r->ip()));
        RateLimiter::for('enotf-clinic-code', fn (Request $r) => Limit::perMinute(10)->by('enotf-clinic-code|'.$r->ip()));
    }
}

<?php

namespace Modules\FireTab;

use App\Auth\Permissions;
use App\Dashboard\DashboardWidgets;
use App\Dashboard\IncidentStats;
use App\Dashboard\Widgets\HintsWidget;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\ModuleProvider;
use App\Modules\ModuleRegistry;
use App\Notifications\NotificationKinds;
use App\Realtime\Realtime;
use App\Search\SearchRegistry;
use App\Support\OwnProtocols;
use App\Support\SettingDefinitions;
use App\Vehicles\CrewSession;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\FireTab\Console\PrunePersonalDataCommand;
use Modules\FireTab\Console\PruneStatusQueueCommand;
use Modules\FireTab\Events\AsuEventRecorded;
use Modules\FireTab\Events\FireIncidentFinalized;
use Modules\FireTab\Events\FireIncidentUpdated;
use Modules\FireTab\Events\MapChanged;
use Modules\FireTab\Listeners\DispatchDiscordOnFinalized;
use Modules\FireTab\Listeners\NotifyLeaderOnFinalized;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Notifications\FireProtocolType;
use Modules\FireTab\Policies\FireIncidentPolicy;
use Modules\FireTab\Search\FireIncidentSource;
use Modules\FireTab\Support\OrganizationTime;
use Modules\FireTab\Widgets\OwnIncidentsWidget;

/** FireTab: fire-service operation protocols filled in on the vehicle tablet (FiveM CEF webview). */
class FireTabServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'FireTab';
    }

    public function label(): string
    {
        return 'firetab.label';
    }

    public function description(): string
    {
        return 'firetab.description';
    }

    public function group(): string
    {
        return 'einsatz';
    }

    public function register(): void
    {
        Permissions::register('fire.incident.qm', 'fire', 'firetab');

        $this->app->make(NotificationKinds::class)->register(FireProtocolType::KEY, FireProtocolType::definition());

        $this->app->make(SearchRegistry::class)->register(new FireIncidentSource);
        $this->app->make(DashboardWidgets::class)->register(new OwnIncidentsWidget);
        $this->app->make(IncidentStats::class)->addSource('fire', 'firetab', fn (User $u) => Gate::forUser($u)->allows('viewQm', FireIncident::class),
            fn () => FireIncident::query()->whereNull('archived_at'), 'started_at');
        $this->app->make(HintsWidget::class)->addSource('firetab', fn (User $u) => $u->personnel_id !== null, function (User $u) {
            $open = OwnIncidentsWidget::open($u);
            $count = (clone $open)->count();
            if ($count === 0) {
                return [];
            }
            $first = $open->orderByDesc('started_at')->orderByDesc('id')->limit(3)->get();
            $names = $first->map(fn (FireIncident $i) => $i->number ?? '#'.$i->id)->all();

            return [['tone' => 'info', 'icon' => 'list-check', 'title' => HintsWidget::text('firetab.hints.open', $names, $count),
                'href' => FireProtocolType::link($first[0], $u)]];
        });

        $this->app->make(OwnProtocols::class)->register('firetab', 'firetab', 'firetab.search.source', fn (User $u) => $u->personnel_id === null ? [] :
            FireIncident::query()->where('leader_personnel_id', $u->personnel_id)->whereNull('archived_at')->orderByDesc('started_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn (FireIncident $i) => [
                    'title' => trim(($i->number ?? '').' '.($i->keyword ?? '')) ?: '#'.$i->id,
                    'sub' => implode(' · ', array_filter([$i->location, OrganizationTime::display($i->started_at)])),
                    'href' => FireProtocolType::link($i, $u),
                ])->all());

        $defs = $this->app->make(SettingDefinitions::class);
        $defs->register('firetab.asu_consumption', 'firetab', 'int', 10, ['min:1', 'max:60'], module: 'firetab');
        $defs->register('firetab.asu_start_bar', 'firetab', 'int', 300, ['min:100', 'max:400'], module: 'firetab');
        $defs->register('firetab.personal_data_months', 'firetab', 'int', 12, ['min:0', 'max:120'], module: 'firetab');
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('firetab:prune-personal-data')->dailyAt('03:00');
        $schedule->command('firetab:prune-status-queue')->hourly();
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([PrunePersonalDataCommand::class, PruneStatusQueueCommand::class]);
        $this->listen(FireIncidentFinalized::class, NotifyLeaderOnFinalized::class);
        $this->listen(FireIncidentFinalized::class, DispatchDiscordOnFinalized::class);
        $this->realtime();

        Gate::policy(FireIncident::class, FireIncidentPolicy::class);
        RateLimiter::for('firetab-write', fn (Request $r) => Limit::perMinute(60)->by('firetab-write:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('firetab-asu', fn (Request $r) => Limit::perMinute(120)->by('firetab-asu:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('firetab-status', fn (Request $r) => Limit::perMinute(12)->by('firetab-status:'.(CrewSession::for('firetab')->current()['vehicle_id'] ?? $r->ip())));
    }

    private function realtime(): void
    {
        // Policy (NoSuperAdminBypass, explicit QM/super-admin read); the module check also covers a Gate::before bypass.
        Broadcast::channel('firetab.incident.{incident}', fn (User $user, FireIncident $incident) => ModuleRegistry::active('firetab') && $user->can('view', $incident));
        // The own crew session only: never through the Gate, so a super admin without a session is refused.
        Broadcast::channel('firetab.vehicle.{vehicle}', fn (User $user, string $vehicle) => ModuleRegistry::active('firetab') && ctype_digit($vehicle)
            && CrewSession::for('firetab')->valid($user) && (int) (CrewSession::for('firetab')->current()['vehicle_id'] ?? 0) === (int) $vehicle);

        $incident = fn (string $topic) => fn (object $e) => Realtime::pulse('firetab.incident.'.$e->incidentId, $topic);
        $this->listen(FireIncidentUpdated::class, $incident('incident'));
        $this->listen(FireIncidentFinalized::class, $incident('incident'));
        $this->listen(AsuEventRecorded::class, $incident('asu'));
        $this->listen(MapChanged::class, $incident('map'));

        $assigned = fn (FireIncidentVehicle $v) => $v->vehicle_id === null ? null : Realtime::pulse('firetab.vehicle.'.$v->vehicle_id, 'incidents');
        $this->listen('eloquent.saved: '.FireIncidentVehicle::class, $assigned);
        $this->listen('eloquent.deleted: '.FireIncidentVehicle::class, $assigned);
        $this->listen(FireIncidentFinalized::class, fn (FireIncidentFinalized $e) => FireIncidentVehicle::query()->where('fire_incident_id', $e->incidentId)
            ->whereNotNull('vehicle_id')->pluck('vehicle_id')->each(fn (int $id) => Realtime::pulse('firetab.vehicle.'.$id, 'incidents')));
        $this->listen('eloquent.saved: '.Vehicle::class, fn (Vehicle $v) => $v->wasChanged(['fms_status', 'fms_status_at'])
            ? Realtime::pulse('firetab.vehicle.'.$v->id, 'status') : null);
    }
}

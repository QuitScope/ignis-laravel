<?php

namespace Modules\ManvBoard;

use App\Auth\Permissions;
use App\Models\User;
use App\Modules\ModuleProvider;
use App\Modules\ModuleRegistry;
use App\Realtime\Realtime;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;

/** MANV-Board: mass-casualty incidents with patients, triage, resources and action log. */
class ManvBoardServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'ManvBoard';
    }

    public function label(): string
    {
        return 'manvboard.label';
    }

    public function description(): string
    {
        return 'manvboard.description';
    }

    public function group(): string
    {
        return 'einsatz';
    }

    public function register(): void
    {
        Permissions::register('mci.view', 'mci', 'manvboard');
        Permissions::register('mci.manage', 'mci', 'manvboard');
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ManvIncident::class, ManvIncidentPolicy::class);

        // Channels by policy (the module check also covers a super admin's Gate::before bypass).
        Broadcast::channel('manv', fn (User $user) => ModuleRegistry::active('manvboard') && $user->can('viewAny', ManvIncident::class));
        Broadcast::channel('manv.{incident}', fn (User $user, ManvIncident $incident) => ModuleRegistry::active('manvboard') && $user->can('view', $incident));
        $this->listen('eloquent.saved: '.ManvIncident::class, fn (ManvIncident $i) => Realtime::pulse('manv', 'list', $i->id));
    }
}

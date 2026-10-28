<?php

namespace Modules\ManvBoard\Policies;

use App\Models\User;
use App\Modules\ModuleRegistry;
use Modules\ManvBoard\Enums\ManvIncidentStatus;
use Modules\ManvBoard\Models\ManvIncident;

/** Reading needs mci.view or mci.manage, writing mci.manage. Policies stay active with the module off, so they check it. */
class ManvIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'mci.view', 'mci.manage']);
    }

    public function view(User $user, ?ManvIncident $incident = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, ?ManvIncident $incident = null): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, ?ManvIncident $incident = null): bool
    {
        return $this->manage($user);
    }

    public function isWritable(ManvIncident $incident): bool
    {
        return $incident->status === ManvIncidentStatus::Active;
    }

    /** Patient/resource writes: manage right and an active Lage. Called directly, not via Gate, so Gate::before cannot skip the status. */
    public function canWrite(?User $user, ManvIncident $incident): bool
    {
        return $user?->can('update', $incident) === true && $this->isWritable($incident);
    }

    private function manage(User $user): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'mci.manage']);
    }

    private function on(): bool
    {
        return ModuleRegistry::active('manvboard');
    }
}

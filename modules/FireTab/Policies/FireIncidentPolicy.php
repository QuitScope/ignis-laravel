<?php

namespace Modules\FireTab\Policies;

use App\Auth\NoSuperAdminBypass;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Vehicles\CrewSession;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireMapMarker;
use Modules\FireTab\Models\FireMapZone;
use Modules\FireTab\Support\CrewVehicle;

/** NoSuperAdminBypass: writes need the assigned crew; reading/QM allow super-admins explicitly. Policies stay active with the module off, so they check it. The crew vehicle comes from CrewSession (CrewVehicle). */
class FireIncidentPolicy implements NoSuperAdminBypass
{
    public function viewAny(User $user): bool
    {
        return $this->on() && ($this->crew() !== null || $this->viewQm($user));
    }

    public function view(User $user, FireIncident $incident): bool
    {
        return $this->on() && ($this->assigned($incident) || $this->viewQm($user));
    }

    public function create(User $user): bool
    {
        return $this->on() && $this->crew() !== null;
    }

    public function update(User $user, FireIncident $incident): bool
    {
        return $this->on() && $this->assigned($incident) && $this->open($incident);
    }

    public function finalize(User $user, FireIncident $incident): bool
    {
        return $this->update($user, $incident);
    }

    public function review(User $user, FireIncident $incident): bool
    {
        return $this->viewQm($user);
    }

    public function archive(User $user, FireIncident $incident): bool
    {
        return $this->viewQm($user);
    }

    public function reopen(User $user, FireIncident $incident): bool
    {
        return $this->viewQm($user);
    }

    /**
     * Lagekarte: delete a marker/zone of an open incident. Own item (same account or same personnel record) as assigned
     * crew, or QM. QM needs the real right here: a super-admin does not write crew data.
     */
    public function deleteMapItem(User $user, FireIncident $incident, FireMapMarker|FireMapZone $item): bool
    {
        if (! $this->on() || ! $this->open($incident)) {
            return false;
        }
        if ($user->hasAnyPermission(['admin', 'fire.incident.qm'])) {
            return true;
        }

        return self::owns($user, $item) && $this->assigned($incident);
    }

    public static function owns(User $user, FireMapMarker|FireMapZone $item): bool
    {
        $personnel = CrewSession::for('firetab')->current()['personnel_id'] ?? null;

        return $item->created_by_user_id === $user->id || ($personnel !== null && $item->personnel_id === (int) $personnel);
    }

    public function viewQm(User $user): bool
    {
        return $this->on() && ($user->is_super_admin || $user->hasPermission(['admin', 'fire.incident.qm']));
    }

    private function open(FireIncident $incident): bool
    {
        return $incident->finalized_at === null && $incident->archived_at === null;
    }

    private function assigned(FireIncident $incident): bool
    {
        $vehicle = $this->crew();

        return $vehicle !== null && $incident->vehicles()->where('vehicle_id', $vehicle)->exists();
    }

    private function crew(): ?int
    {
        return app(CrewVehicle::class)->id();
    }

    private function on(): bool
    {
        return ModuleRegistry::active('firetab');
    }
}

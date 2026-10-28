<?php

namespace Modules\Enotf\Policies;

use App\Auth\NoSuperAdminBypass;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\CrewContext;

/**
 * NoSuperAdminBypass: a super-admin reads and does QM by explicit allowance, but never writes
 * protocol data, not even with a crew session; edivi.edit counts only as a real (role) permission.
 * The crew reads open and released protocols of its vehicle (or ones naming its personnel record) and writes open ones;
 * withdrawn and hidden ones are QM-only. Policies stay active with the module off, so they check it.
 */
class EnotfProtocolPolicy implements NoSuperAdminBypass
{
    public function viewAny(User $user): bool
    {
        return $this->on() && $this->crew()->vehicleId() !== null;
    }

    public function view(User $user, EnotfProtocol $protocol): bool
    {
        return $this->on() && ($this->crewReads($protocol) || $this->ownReleased($user, $protocol) || $this->viewQm($user));
    }

    private function ownReleased(User $user, EnotfProtocol $protocol): bool
    {
        return $user->personnel_id !== null && $protocol->status === ProtocolStatus::Released && $protocol->hidden_at === null
            && $protocol->crew()->where('personnel_id', $user->personnel_id)->exists();
    }

    public function print(User $user, EnotfProtocol $protocol): bool
    {
        return $this->view($user, $protocol);
    }

    public function create(User $user): bool
    {
        return $this->on() && ! $user->is_super_admin && $this->crew()->vehicleId() !== null;
    }

    public function update(User $user, EnotfProtocol $protocol): bool
    {
        return $this->on() && ($this->crewWrites($user, $protocol)
            || ($this->editor($user) && $protocol->status !== ProtocolStatus::Withdrawn));
    }

    public function release(User $user, EnotfProtocol $protocol): bool
    {
        return $this->on() && $protocol->isOpen() && ($this->crewWrites($user, $protocol) || $this->editor($user));
    }

    public function withdraw(User $user, EnotfProtocol $protocol): bool
    {
        return $this->on() && $this->crewWrites($user, $protocol) && $protocol->origin !== ProtocolOrigin::Dispatch;
    }

    public function share(User $user, EnotfProtocol $protocol): bool
    {
        return $this->on() && $this->crewWrites($user, $protocol);
    }

    public function handover(User $user, EnotfProtocol $protocol): bool
    {
        return $this->on() && ! $user->is_super_admin && $protocol->hidden_at === null
            && in_array($protocol->status, [ProtocolStatus::Open, ProtocolStatus::Released], true)
            && $protocol->hasVehicle($this->crew()->vehicleId());
    }

    public function viewQm(User $user): bool
    {
        return $this->on() && ($user->is_super_admin || $user->hasAnyPermission(['admin', 'edivi.view', 'edivi.edit']));
    }

    public function review(User $user): bool
    {
        return $this->on() && $this->editor($user);
    }

    public function bulkDelete(User $user): bool
    {
        return $this->on() && $this->editor($user);
    }

    public function editProtocol(User $user): bool
    {
        return $this->on() && $this->editor($user);
    }

    private function editor(User $user): bool
    {
        return ! $user->is_super_admin && $user->hasAnyPermission(['admin', 'edivi.edit']);
    }

    private function crewWrites(User $user, EnotfProtocol $protocol): bool
    {
        return ! $user->is_super_admin && $protocol->isOpen() && $protocol->hasVehicle($this->crew()->vehicleId());
    }

    private function crewReads(EnotfProtocol $protocol): bool
    {
        if ($protocol->status === ProtocolStatus::Withdrawn || $protocol->hidden_at !== null) {
            return false;
        }
        if ($protocol->hasVehicle($this->crew()->vehicleId())) {
            return true;
        }
        $personnel = $this->crew()->personnelId();

        return $personnel !== null && $protocol->crew()->where('personnel_id', $personnel)->exists();
    }

    private function crew(): CrewContext
    {
        return app(CrewContext::class);
    }

    private function on(): bool
    {
        return ModuleRegistry::active('enotf');
    }
}

<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Who may do what with accounts. Gate::before does NOT bypass this policy for user targets
 * (see AppServiceProvider), so the rank rule also binds the super admin.
 */
class UserPolicy
{
    public function viewList(User $actor): bool
    {
        return $actor->hasPermission(['admin', 'users.view']);
    }

    public function createRegistrationCode(User $actor): bool
    {
        return $actor->hasPermission(['admin', 'users.create']);
    }

    public function viewAuditLog(User $actor): bool
    {
        return $actor->hasPermission(['admin', 'audit.view']);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermission(['admin', 'users.edit']) && $this->canModify($actor, $target);
    }

    public function linkPersonnel(User $actor, User $target): bool
    {
        return $this->update($actor, $target) || ($actor->is_super_admin && $actor->is($target));
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->hasPermission(['admin', 'users.delete']) && $this->canModify($actor, $target);
    }

    public function toggleActive(User $actor, User $target): bool
    {
        return $this->delete($actor, $target);
    }

    public function assignRole(User $actor, Role $role): bool
    {
        return $role->priority > $actor->rank();
    }

    private function canModify(User $actor, User $target): bool
    {
        return $actor->isNot($target)
            && ! $target->is_super_admin
            && $target->rank() > $actor->rank();
    }
}

<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/** Gate::before already lets the super admin through; create/update/delete are super-admin only (legacy full_admin). */
class RolePolicy
{
    public function viewList(User $user): bool
    {
        return $user->hasPermission(['admin', 'users.view']);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Role $role): bool
    {
        return false;
    }

    public function delete(User $user, Role $role): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Models\User;

/**
 * Legacy VehiclePolicy abilities, registered as the `vehicle.*` gates. None takes a model, as before.
 */
class VehiclePolicy
{
    public function view(User $user): bool
    {
        return $user->hasPermission(['admin', 'vehicles.view']);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(['admin', 'vehicles.manage']);
    }

    public function createDefect(User $user): bool
    {
        return $user->hasPermission(['admin', 'vehicles.manage', 'vehicles.view']);
    }

    public function deleteDefect(User $user): bool
    {
        return $user->hasPermission(['admin']);
    }

    public function manageImport(User $user): bool
    {
        return $user->hasPermission(['admin', 'vehicles.manage']);
    }
}

<?php

namespace App\Policies;

use App\Models\User;

/**
 * Personnel catalogues (ranks, qualifications, specialties, titles). Legacy: listing needs
 * personnel.viewList (admin or personnel.view), every change needs system.admin.
 * Registered as the gates personnel-settings.viewAny / personnel-settings.manage (no model to bind to).
 */
class PersonnelSettingsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(['admin', 'personnel.view']);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission(['admin']);
    }
}

<?php

namespace App\Policies;

use App\Models\Personnel;
use App\Models\User;

/**
 * Legacy PersonnelPolicy abilities. The model argument is optional so the same method answers both
 * "may this user list/create" (class) and "may they touch this person" (record); rights are global.
 */
class PersonnelPolicy
{
    public function viewList(User $user): bool
    {
        return $user->hasPermission(['admin', 'personnel.view']);
    }

    public function view(User $user, ?Personnel $personnel = null): bool
    {
        return $this->viewList($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(['admin', 'personnel.edit']);
    }

    public function update(User $user, ?Personnel $personnel = null): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, ?Personnel $personnel = null): bool
    {
        return $user->hasPermission(['admin', 'personnel.delete']);
    }

    public function manageDocs(User $user, ?Personnel $personnel = null): bool
    {
        return $user->hasPermission(['admin', 'personnel.documents.manage']);
    }

    public function viewDoc(User $user, ?Personnel $personnel = null): bool
    {
        return $user->hasPermission(['admin', 'personnel.documents.view', 'personnel.documents.manage', 'personnel.view']);
    }

    public function deleteComments(User $user, ?Personnel $personnel = null): bool
    {
        return $user->hasPermission(['admin', 'personnel.comment.delete']);
    }
}

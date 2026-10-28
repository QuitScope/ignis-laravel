<?php

namespace Modules\Forms\Policies;

use App\Models\User;
use App\Modules\ModuleRegistry;
use Modules\Forms\Models\FormSubmission;

/**
 * View or edit reads every submission, the owner reads their own, only edit decides and never one's own.
 * Super admins pass through Gate::before, so the controller checks the own-submission rule for them too
 * (FormAdminController::canDecide). Policies stay active with the module off, so they check it.
 */
class FormSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'application.view', 'application.edit']);
    }

    public function view(User $user, FormSubmission $submission): bool
    {
        return $this->on() && ($submission->isOwnedBy($user) || $this->viewAny($user));
    }

    public function create(User $user): bool
    {
        return $this->on() && $user->personnel_id !== null;
    }

    public function decide(User $user, FormSubmission $submission): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'application.edit']) && ! $submission->isOwnedBy($user);
    }

    private function on(): bool
    {
        return ModuleRegistry::active('forms');
    }
}

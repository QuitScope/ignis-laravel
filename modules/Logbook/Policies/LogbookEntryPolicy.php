<?php

namespace Modules\Logbook\Policies;

use App\Models\User;
use App\Modules\ModuleRegistry;

/** Reading needs view or manage; writing needs manage. Policies stay active with the module off, so they check it. */
class LogbookEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'logbook.view', 'logbook.manage']);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user): bool
    {
        return $this->manage($user);
    }

    private function manage(User $user): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'logbook.manage']);
    }

    private function on(): bool
    {
        return ModuleRegistry::active('logbook');
    }
}

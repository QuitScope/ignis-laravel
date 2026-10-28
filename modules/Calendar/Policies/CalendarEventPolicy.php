<?php

namespace Modules\Calendar\Policies;

use App\Models\User;
use App\Modules\ModuleRegistry;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\Visibility;

/**
 * Reading follows Visibility (calendar.manage reads everything, so it opens the calendar too); writing is the creator
 * (still holding a calendar right) or calendar.manage, never for a mirrored absence. Super admins pass Gate::before, so the controller checks
 * CalendarEvent::isLocked() itself as well. Policies stay active with the module off, so they check it.
 */
class CalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'calendar.view', 'calendar.manage']);
    }

    public function view(User $user, CalendarEvent $event): bool
    {
        return $this->viewAny($user) && Visibility::allows($user, $event);
    }

    public function create(User $user): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'calendar.create']);
    }

    public function lookup(User $user): bool
    {
        return $this->create($user) || ($this->viewAny($user) && Visibility::seesAll($user));
    }

    public function update(User $user, CalendarEvent $event): bool
    {
        return $this->viewAny($user) && ! $event->isLocked()
            && (($event->created_by !== null && $event->created_by === $user->id) || Visibility::seesAll($user));
    }

    public function delete(User $user, CalendarEvent $event): bool
    {
        return $this->update($user, $event);
    }

    private function on(): bool
    {
        return ModuleRegistry::active('calendar');
    }
}

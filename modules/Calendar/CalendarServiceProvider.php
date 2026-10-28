<?php

namespace Modules\Calendar;

use App\Auth\Permissions;
use App\Models\User;
use App\Modules\ModuleProvider;
use App\Notifications\NotificationKinds;
use Illuminate\Support\Facades\Gate;
use Modules\Calendar\Console\BackfillAbsencesCommand;
use Modules\Calendar\Listeners\RemoveAbsenceOnFormDeleted;
use Modules\Calendar\Listeners\SyncAbsenceOnFormDecided;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Policies\CalendarEventPolicy;

/** Kalender: events and series with visibility levels, invitations and responses. */
class CalendarServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Calendar';
    }

    public function label(): string
    {
        return 'calendar.label';
    }

    public function description(): string
    {
        return 'calendar.description';
    }

    public function register(): void
    {
        Permissions::register('calendar.view', 'calendar', 'calendar');
        Permissions::register('calendar.create', 'calendar', 'calendar');
        Permissions::register('calendar.manage', 'calendar', 'calendar');

        $this->app->make(NotificationKinds::class)->register('calendar', [
            'label' => 'calendar.notification.kind', 'icon' => 'calendar', 'module' => 'calendar',
            'ability' => fn (User $u) => $u->hasPermission(['admin', 'calendar.view', 'calendar.manage']),
        ]);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(CalendarEvent::class, CalendarEventPolicy::class);
        $this->commands([BackfillAbsencesCommand::class]);

        if (class_exists('Modules\Forms\Events\FormDecided')) {
            $this->listen('Modules\Forms\Events\FormDecided', SyncAbsenceOnFormDecided::class);
            $this->listen('Modules\Forms\Events\FormDeleted', RemoveAbsenceOnFormDeleted::class);
        }
    }
}

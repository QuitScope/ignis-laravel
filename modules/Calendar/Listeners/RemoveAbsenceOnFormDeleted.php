<?php

namespace Modules\Calendar\Listeners;

use App\Modules\ModuleRegistry;
use Modules\Calendar\Support\AbsenceSync;
use Modules\Forms\Events\FormDeleted;

/** The FK cascade usually removed the event already; this is a no-op then. */
class RemoveAbsenceOnFormDeleted
{
    public function handle(FormDeleted $event): void
    {
        if (ModuleRegistry::active('calendar')) {
            AbsenceSync::remove($event->submissionId);
        }
    }
}

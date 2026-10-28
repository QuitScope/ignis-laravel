<?php

namespace Modules\Calendar\Listeners;

use App\Modules\ModuleRegistry;
use Modules\Calendar\Support\AbsenceSync;
use Modules\Forms\Events\FormDecided;

/** Mirrors (or un-mirrors) the absence of a decided application; inert while forms is off. */
class SyncAbsenceOnFormDecided
{
    public function handle(FormDecided $event): void
    {
        if (ModuleRegistry::active('forms') && ModuleRegistry::active('calendar')) {
            AbsenceSync::sync($event->submission);
        }
    }
}

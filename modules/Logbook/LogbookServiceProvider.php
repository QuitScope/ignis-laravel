<?php

namespace Modules\Logbook;

use App\Auth\Permissions;
use App\Modules\ModuleProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Policies\LogbookEntryPolicy;

/** Fahrtenbuch: trip log with an admin page and LogbookService as the hook for eNOTF/FireTab. */
class LogbookServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Logbook';
    }

    public function label(): string
    {
        return 'logbook.label';
    }

    public function description(): string
    {
        return 'logbook.description';
    }

    public function group(): string
    {
        return 'einsatz';
    }

    public function register(): void
    {
        Permissions::register('logbook.view', 'logbook', 'logbook');
        Permissions::register('logbook.manage', 'logbook', 'logbook');
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(LogbookEntry::class, LogbookEntryPolicy::class);
    }
}

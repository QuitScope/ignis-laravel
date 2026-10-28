<?php

use App\Jobs\RunScheduledCommand;
use App\Support\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:prune')->daily();
Schedule::command('announcements:refresh')->hourly();
Schedule::command('changelog:refresh')->everyThirtyMinutes();
Schedule::command('ignis:heartbeat')->everyMinute()->evenInMaintenanceMode();
Schedule::command('queue:prune-failed --hours='.RunScheduledCommand::PRUNE_FAILED_HOURS)->daily();
Schedule::command('model:prune')->daily();
Schedule::command('ignis:storage:cleanup')->weekly();
Schedule::command('ignis:update:check')->daily()->when(fn () => (bool) app(Settings::class)->get('updates.check_enabled'));

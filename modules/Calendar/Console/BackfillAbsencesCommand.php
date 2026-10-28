<?php

namespace Modules\Calendar\Console;

use App\Modules\ModuleCommand;
use App\Modules\ModuleRegistry;
use Modules\Calendar\Support\AbsenceSync;
use Modules\Forms\Models\FormSubmission;

/**
 * Brings all mirrors in line (AbsenceSync::backfill): accepted submissions of absence types are created/updated, mirrors
 * whose submission is no longer an accepted absence are removed. Idempotent. The legacy import does this itself
 * (CalendarImporter); the command stays as a repair tool.
 */
class BackfillAbsencesCommand extends ModuleCommand
{
    protected $signature = 'calendar:backfill-absences {--dry-run : Count what would change, write nothing}';

    protected $description = 'Mirror accepted absence applications into the calendar';

    public function module(): string
    {
        return 'calendar';
    }

    public function handle(): int
    {
        if (! class_exists(FormSubmission::class) || ! ModuleRegistry::active('forms')) {
            $this->line('Modul forms ist deaktiviert');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        $n = AbsenceSync::backfill($dry);

        $this->info(sprintf('%d created, %d updated, %d removed, %d skipped (invalid)%s.', $n[AbsenceSync::CREATED], $n[AbsenceSync::UPDATED],
            $n[AbsenceSync::REMOVED], $n[AbsenceSync::SKIPPED_INVALID], $dry ? ' (dry run, nothing written)' : ''));

        return self::SUCCESS;
    }
}

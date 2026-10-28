<?php

namespace Modules\FireTab\Console;

use App\Modules\ModuleCommand;
use App\Support\AuditLogger;
use App\Support\Settings;
use Modules\FireTab\Models\FireIncident;

/** Clears caller and owner data N months after completion (setting firetab.personal_data_months, 0 = never). Daily via schedule(). */
class PrunePersonalDataCommand extends ModuleCommand
{
    protected $signature = 'firetab:prune-personal-data {--dry-run : Count only, change nothing}';

    protected $description = 'Clear caller and owner data of completed fire protocols after the retention period';

    public function module(): string
    {
        return 'firetab';
    }

    public function handle(Settings $settings): int
    {
        $months = (int) $settings->get('firetab.personal_data_months', 12);
        if ($months <= 0) {
            $this->info('Retention is off (0 months), nothing to do.');

            return self::SUCCESS;
        }

        $cols = ['caller_name', 'caller_contact', 'owner_name', 'owner_contact'];
        $query = FireIncident::query()->whereNotNull('finalized_at')->where('finalized_at', '<', now()->subMonths($months))
            ->where(function ($q) use ($cols) {
                foreach ($cols as $c) {
                    $q->orWhere(fn ($b) => $b->whereNotNull($c)->where($c, '!=', ''));
                }
            });
        $n = $query->count();
        $dry = (bool) $this->option('dry-run');
        if (! $dry && $n > 0) {
            $n = $query->update(array_fill_keys($cols, null));
            AuditLogger::log(null, 'Personendaten bereinigt', "$n Protokolle", 'Feuerwehr', true, ['count' => $n, 'months' => $months]);
        }
        $this->info($dry ? "$n protocol(s) would be cleared (dry run)." : "$n protocol(s) cleared.");

        return self::SUCCESS;
    }
}

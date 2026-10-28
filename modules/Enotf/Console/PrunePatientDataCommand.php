<?php

namespace Modules\Enotf\Console;

use App\Modules\ModuleCommand;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\ClinicCode;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\Preregistration;
use Modules\Enotf\Support\CrewService;

/**
 * Clears patient name and birthdate N months after release (withdrawn: after withdrawal; setting enotf.personal_data_months, 0 = never); clinical
 * data stays for QM. Also removes expired clinic codes and pre-registrations older than 24 h. All identifying fields of the form are header columns (FieldSchema), the data JSON holds none; the
 * importer may keep legacy patient values in `legacy` (a patname that differs from the split names, a name or birthdate
 * the form type rejected), so those keys are removed there too. Also ends crews idle for 12 h. Daily via schedule().
 */
class PrunePatientDataCommand extends ModuleCommand
{
    private const COLUMNS = ['patient_first_name', 'patient_last_name', 'patient_birthdate'];

    public const LEGACY_KEYS = ['patname', 'pat_vorname', 'pat_nachname', 'patgebdat'];

    protected $signature = 'enotf:prune {--dry-run : Count only, change nothing}';

    protected $description = 'Clear patient name and birthdate of released eNOTF protocols after the retention period';

    public function module(): string
    {
        return 'enotf';
    }

    public function handle(Settings $settings, CrewService $crews): int
    {
        $idle = EnotfCrew::query()->whereNull('ended_at')->whereRaw('COALESCE(last_activity_at, started_at) < ?', [now()->subHours(12)->toDateTimeString()])->get();
        if (! $this->option('dry-run')) {
            $idle->each(fn (EnotfCrew $c) => $crews->close($c, 'timeout'));
        }
        $this->info($idle->count().' idle crew(s) ended.');

        $codes = ClinicCode::query()->where('expires_at', '<=', now());
        $preregs = Preregistration::query()->where('created_at', '<', now()->subDay());
        [$c, $r] = $this->option('dry-run') ? [$codes->count(), $preregs->count()] : [$codes->delete(), $preregs->delete()];
        $this->info("$c clinic code(s), $r pre-registration(s) removed.");

        $months = (int) $settings->get('enotf.personal_data_months', 12);
        if ($months <= 0) {
            $this->info('Retention is off (0 months), nothing to do.');

            return self::SUCCESS;
        }

        $query = EnotfProtocol::query()->whereIn('status', [ProtocolStatus::Released, ProtocolStatus::Withdrawn])
            ->whereRaw('COALESCE(released_at, withdrawn_at) < ?', [now()->subMonths($months)->toDateTimeString()])
            ->where(function (Builder $q) {
                foreach (self::COLUMNS as $c) {
                    $q->orWhere(fn (Builder $b) => $b->whereNotNull($c)->where($c, '!=', ''));
                }
                foreach (self::LEGACY_KEYS as $key) {
                    $q->orWhere('legacy', 'like', '%"'.$key.'"%');
                }
            });
        $n = $query->count();
        $dry = (bool) $this->option('dry-run');
        if (! $dry && $n > 0) {
            $n = 0;
            $query->chunkById(500, function ($protocols) use (&$n) {
                foreach ($protocols as $protocol) {
                    $legacy = array_diff_key($protocol->legacy ?? [], array_flip(self::LEGACY_KEYS));
                    $protocol->forceFill(array_fill_keys(self::COLUMNS, null) + ['legacy' => $legacy === [] ? null : $legacy]);
                    $protocol->save();
                    $n++;
                }
            });
            // Counters only, never the cleared content.
            AuditLogger::log(null, 'Patientendaten bereinigt', "$n Protokolle", 'eNOTF', true, ['count' => $n, 'months' => $months]);
        }
        $this->info($dry ? "$n protocol(s) would be cleared (dry run)." : "$n protocol(s) cleared.");

        return self::SUCCESS;
    }
}

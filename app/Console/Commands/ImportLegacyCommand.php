<?php

namespace App\Console\Commands;

use App\Import\ChecksLegacyState;
use App\Import\IdMap;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\ImportPlan;
use App\Import\ImportReport;
use App\Import\LegacyClock;
use App\Import\LegacyConnectionGuard;
use App\Import\LegacyDbClock;
use App\Import\LegacyReader;
use App\Install\InstallState;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class ImportLegacyCommand extends Command
{
    private const ANALYSED_VERSION = 20261016100001;

    private const UPLOAD_FOLDERS = ['storage/profile-pictures', 'storage/documents', 'storage/private/editor-documents', 'storage/branding', 'storage/kb-images', 'storage/private/mail-attachments'];

    protected $signature = 'ignis:import-legacy
        {--dry-run : Run everything in a transaction that is rolled back}
        {--only=* : Only these entities (and what they depend on)}
        {--format=table : table or json}
        {--uploads= : Root folder of the legacy installation; profile pictures, document PDFs and the logo are copied from its storage folder}
        {--import-api-key : Take over the legacy API_KEY (stored encrypted); without it the new instance keeps or generates its own}
        {--overwrite-settings : Legacy configuration values replace settings already stored in the new instance}';

    protected $description = 'Import data from the legacy intraRP database (read-only source; only adds and updates)';

    public function handle(InstallState $state, IdMap $ids, Settings $settings): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('--format must be table or json.');

            return self::FAILURE;
        }
        $json = $format === 'json';
        $dry = (bool) $this->option('dry-run');
        $uploads = $this->option('uploads');
        $uploads = is_string($uploads) && $uploads !== '' ? $uploads : null;
        if ($uploads !== null && ! is_dir($uploads)) {
            $this->error("--uploads: folder {$uploads} does not exist.");

            return self::FAILURE;
        }
        if ($uploads !== null && array_filter(self::UPLOAD_FOLDERS, fn (string $f) => is_dir($uploads.'/'.$f)) === []) {
            $this->error("--uploads: {$uploads} has none of the folders ".implode(', ', self::UPLOAD_FOLDERS).'; pass the root folder of the legacy installation.');

            return self::FAILURE;
        }
        $only = array_values(array_filter(array_map('trim', explode(',', implode(',', (array) $this->option('only'))))));

        try {
            if (! $state->isInstalled()) {
                throw new RuntimeException('The app is not installed yet. Run ignis:install first.');
            }
            LegacyClock::legacyZone();
            LegacyDbClock::configuredZone();
            LegacyConnectionGuard::assertSeparate(
                (array) config('database.connections.legacy'),
                (array) config('database.connections.'.config('database.default')),
            );
            $reader = app(LegacyReader::class);
            try {
                $reader->select('select 1');
            } catch (Throwable $e) {
                throw new RuntimeException('The legacy database is not reachable: '.$e->getMessage());
            }

            /** @var list<Importer> $importers */
            $importers = array_map(fn (string $class) => app($class), (array) config('ignis.importers'));
            $plan = ImportPlan::resolve($importers, $only);

            $missing = array_values(array_unique(array_filter(
                array_merge(...array_map(fn (Importer $i) => $i->requiredTables(), $plan) ?: [[]]),
                fn (string $t) => ! $reader->tableExists($t),
            )));
            if ($missing) {
                throw new RuntimeException('Legacy tables missing: '.implode(', ', $missing).'. Altsystem nicht auf dem letzten Stand?');
            }
            foreach ($plan as $importer) {
                if ($importer instanceof ChecksLegacyState) {
                    $importer->checkLegacyState($reader);
                }
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report = new ImportReport($dry);
        $this->checkPhinxlog($reader, $report, $json);
        $ctx = new ImportContext($reader, $dry, $ids, $report, $settings, $uploads,
            (bool) $this->option('import-api-key'), (bool) $this->option('overwrite-settings'));

        $sharedCache = config('cache.default');
        if ($dry) {
            config(['cache.default' => 'array']);
            DB::beginTransaction();
        }
        try {
            $failed = [];
            $ran = [];
            foreach ($plan as $importer) {
                if (array_intersect($importer->dependsOn(), $failed)) {
                    $report->skipped($importer->entity(), 'dependency failed');
                    $failed[] = $importer->entity();

                    continue;
                }
                try {
                    $importer->import($ctx);
                    $ran[] = $importer->entity();
                } catch (Throwable $e) {
                    $report->failed($importer->entity(), $e::class.': '.$e->getMessage());
                    $failed[] = $importer->entity();
                }
            }
            if (in_array('audit', $ran, true) && ! in_array('vehicle_audit_context', $ran, true)
                && DB::table('activity_log')->where('log_name', 'Fahrzeuge')->whereNotNull('legacy_id')->exists()) {
                $report->warn('Imported Fahrzeuge audit entries still carry legacy vehicle/defect ids because vehicle_audit_context did not run; '
                    .'vehicle pages may show the history of other vehicles. Run the import again with vehicle_audit_context (or without --only).');
            }
        } finally {
            if ($dry) {
                DB::rollBack();
            }
            config(['cache.default' => $sharedCache]);
            $settings->refresh();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (! $dry) {
            AuditLogger::log(null, 'Altdaten-Import ausgeführt', $this->summary($report), 'System', true);
        }

        $this->line($json ? (string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ($dry ? "DRY RUN (nothing was written)\n" : '').$report->render());

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function checkPhinxlog(LegacyReader $reader, ImportReport $report, bool $json): void
    {
        if (! $reader->tableExists('phinxlog')) {
            if (! $json) {
                $this->comment('Notice: the legacy database has no phinxlog table; its migration state cannot be checked.');
            }

            return;
        }
        $version = (int) $reader->table('phinxlog')->max('version');
        if ($version < self::ANALYSED_VERSION) {
            $report->warn("Legacy phinxlog is at {$version}, older than the last analysed migration ".self::ANALYSED_VERSION.'. Altsystem nicht auf dem letzten Stand?');
        }
    }

    private function summary(ImportReport $report): string
    {
        $parts = [];
        foreach ($report->toArray()['entities'] as $entity => $c) {
            $parts[] = "{$entity}: {$c['read']} gelesen, {$c['written']} geschrieben, {$c['skipped']} übersprungen, {$c['failed']} fehlgeschlagen";
        }

        return implode('; ', $parts);
    }
}

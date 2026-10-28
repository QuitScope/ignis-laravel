<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Normalizer;
use Throwable;

/**
 * intra_fahrzeuge_tz_templates => tactical_symbol_templates, mapped by legacy_id_map (entity
 * `tactical_symbol_template`). Written directly, so templates without a Grundzeichen survive (the form requires
 * none either). Symbol values follow the vehicle rules (VehicleImporter::symbol: allowlist only, texts <= 100).
 *
 * Names are trimmed and cut to 100 characters, and unique regardless of case and accents (the MariaDB collation of
 * the new table folds both, SQLite folds neither): the first legacy row wins, later ones are skipped (one
 * warning); an unmapped template of the same name in the new instance is adopted and overwritten (note).
 */
final class TacticalSymbolTemplateImporter implements Importer
{
    private const ENTITY = 'tactical_symbol_template';

    private const NAME_MAX = 100;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user'];
    }

    public function requiredTables(): array
    {
        return ['intra_fahrzeuge_tz_templates'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $users = $ctx->ids()->forEntity('user');
        $mapped = $ctx->ids()->forEntity(self::ENTITY);
        /** @var array<string, int> $byName folded name => target id of templates not mapped to a legacy row */
        $byName = [];
        foreach (DB::table('tactical_symbol_templates')->whereNotIn('id', array_values($mapped))->orderBy('id')->get(['id', 'name']) as $t) {
            $byName[self::fold((string) $t->name)] ??= (int) $t->id;
        }
        $seen = [];
        $warn = ['duplicate' => [], 'noSymbol' => [], 'creator' => []];
        /** @var array<string, list<string>> $problems */
        $problems = [];
        $rows = $ctx->legacy()->tableWithInstants('intra_fahrzeuge_tz_templates', ['created_at', 'updated_at'])->orderBy('id')->get();

        DB::transaction(function () use ($ctx, $report, $users, $rows, &$byName, &$seen, &$warn, &$problems) {
            foreach ($rows as $row) {
                $report->read(self::ENTITY);
                $legacyId = (string) $row->id;
                $name = rtrim(mb_substr(trim(str_replace(["\0", ']]>'], '', (string) $row->name)), 0, self::NAME_MAX));
                $key = self::fold($name);
                if ($name === '' || isset($seen[$key])) {
                    $warn['duplicate'][] = $legacyId;
                    $report->skippedCount(self::ENTITY, 1);

                    continue;
                }
                $seen[$key] = true;
                $rowProblems = [];
                $symbol = VehicleImporter::symbol($row, $rowProblems) ?? [];
                if ($symbol === []) {
                    $warn['noSymbol'][] = $legacyId;
                }
                $creator = null;
                if ($row->created_by !== null) {
                    $creator = $users[(string) $row->created_by] ?? null;
                    if ($creator === null) {
                        $warn['creator'][] = $legacyId;
                    }
                }
                $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                $values = [
                    'name' => $name,
                    'symbol' => json_encode((object) $symbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_by' => $creator,
                    'created_at' => $created,
                    'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
                ];
                $adopt = $ctx->ids()->has(self::ENTITY, $legacyId) ? null : ($byName[$key] ?? null);
                try {
                    DB::transaction(function () use ($ctx, $legacyId, $values, $adopt) {
                        if ($adopt !== null) {
                            $ctx->ids()->put(self::ENTITY, $legacyId, $adopt);
                        }
                        $ctx->ids()->write(self::ENTITY, $legacyId, 'tactical_symbol_templates', $values);
                    });
                } catch (Throwable $e) {
                    $report->failed(self::ENTITY, "legacy template {$legacyId}: ".ImportReport::describe($e));

                    continue;
                }
                if ($adopt !== null) {
                    unset($byName[$key]);
                    $report->note(self::ENTITY." {$legacyId}: adopted existing template {$name} (id {$adopt}).");
                }
                $report->written(self::ENTITY);
                foreach (array_unique($rowProblems) as $what) {
                    $problems[$what][] = $legacyId;
                }
            }
        });

        $report->warnRows('template(s) with an empty name or a name that differs from an earlier one only in case or accents were skipped', $warn['duplicate']);
        $report->warnRows('template(s) without any known symbol value, imported with an empty symbol', $warn['noSymbol']);
        $report->warnRows('template(s) by an account that was not imported, imported without creator', $warn['creator']);
        foreach ($problems as $what => $legacyIds) {
            $report->warnRows("template(s): {$what}", $legacyIds);
        }
    }

    private static function fold(string $name): string
    {
        $decomposed = Normalizer::normalize($name, Normalizer::FORM_D);

        return mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $name : $decomposed));
    }
}

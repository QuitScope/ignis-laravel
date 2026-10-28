<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One legacy lookup table (ranks, qualifications, specialties, titles) => its new table. Mapped rows are
 * updated; otherwise an unmapped row of the same name (case-insensitive, compared in PHP so no collation
 * folds accents) is adopted and overwritten with the legacy values (e.g. an installer default); otherwise
 * a row is created. Duplicate legacy names stay separate rows. Rows only the new instance has are kept.
 */
abstract class LookupImporter implements Importer
{
    abstract protected function legacyTable(): string;

    abstract protected function table(): string;

    /** @return array<string, mixed> new column values (must contain `name`) */
    abstract protected function values(object $row): array;

    /**
     * The not yet mapped target row to adopt for $values, or null. Default: same name, case-insensitive.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $taken  new id => legacy id
     */
    protected function adoptable(array $values, array $taken): ?object
    {
        $name = mb_strtolower(trim((string) $values['name']));

        return DB::table($this->table())->orderBy('id')->get(['id', 'name'])
            ->first(fn ($r) => ! isset($taken[$r->id]) && mb_strtolower(trim((string) $r->name)) === $name);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return [$this->legacyTable()];
    }

    public function import(ImportContext $ctx): void
    {
        $entity = $this->entity();
        $table = $this->table();
        $report = $ctx->report();
        $rows = $ctx->legacy()->table($this->legacyTable())->orderBy('id')->get();
        /** @var array<int, string> $taken new id => legacy id */
        $taken = array_flip($ctx->ids()->forEntity($entity));
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($ctx, $entity, $table, $report, $rows, &$taken, $now) {
            foreach ($rows as $row) {
                $report->read($entity);
                $legacyId = (string) $row->id;
                try {
                    [$id, $note] = DB::transaction(function () use ($ctx, $entity, $table, $row, $legacyId, $taken, $now) {
                        $at = LegacyClock::toApp($row->created_at ?? null) ?? $now;
                        $values = $this->values($row) + ['created_at' => $at, 'updated_at' => $at];
                        $note = null;
                        $id = $ctx->ids()->get($entity, $legacyId);
                        if ($id !== null && ! DB::table($table)->where('id', $id)->exists()) {
                            $id = null;
                        }
                        if ($id === null) {
                            $match = $this->adoptable($values, $taken);
                            if ($match) {
                                $id = (int) $match->id;
                                $note = "{$entity} {$legacyId}: adopted existing row {$match->name} (id {$id}).";
                            }
                        }
                        if ($id === null) {
                            $id = (int) DB::table($table)->insertGetId($values);
                        } else {
                            DB::table($table)->where('id', $id)->update($values);
                        }
                        $ctx->ids()->put($entity, $legacyId, $id);

                        return [$id, $note];
                    });
                } catch (Throwable $e) {
                    $report->failed($entity, "legacy {$this->legacyTable()} {$legacyId}: ".ImportReport::describe($e));

                    continue;
                }
                $taken[$id] = $legacyId;
                if ($note !== null) {
                    $report->note($note);
                }
                $report->written($entity);
            }

            $only = DB::table($table)->whereNotIn('id', array_keys($taken))->orderBy('id')->pluck('name')->all();
            if ($only) {
                $report->warn("{$entity}: rows that exist only in the new instance (kept): ".implode(', ', $only));
            }
        });
    }
}

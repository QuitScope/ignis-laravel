<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_registration_codes => registration_codes. Entity `registration_code` in legacy_id_map is
 * the key the personnel import needs to turn the legacy mitarbeiter_id into personnel_id.
 */
final class RegistrationCodeImporter implements Importer
{
    private const ENTITY = 'registration_code';

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
        return ['intra_registration_codes'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $rows = $ctx->legacy()->table('intra_registration_codes')->orderBy('id')->get();
        $users = $ctx->ids()->forEntity('user');
        /** @var array<int, string> $taken new code id => legacy id */
        $taken = array_flip($ctx->ids()->forEntity(self::ENTITY));
        /** @var array<string, string> $seen lowercase code => legacy id */
        $seen = [];
        $usedAtFilled = [];
        $badDates = [];

        DB::transaction(function () use ($ctx, $report, $rows, $users, &$taken, &$seen, &$usedAtFilled, &$badDates) {
            foreach ($rows as $row) {
                $report->read(self::ENTITY);
                $legacyId = (string) $row->id;
                $code = (string) $row->code;

                if (trim($code) === '' || mb_strlen($code) > 64) {
                    $report->skipped(self::ENTITY, 'code is empty or longer than 64 characters', ['legacy_id' => $legacyId]);

                    continue;
                }
                if (isset($seen[mb_strtolower($code)])) {
                    $report->skipped(self::ENTITY, 'duplicate code, the first one wins', ['legacy_id' => $legacyId, 'kept_legacy_id' => $seen[mb_strtolower($code)]]);

                    continue;
                }
                $seen[mb_strtolower($code)] = $legacyId;

                try {
                    $result = DB::transaction(function () use ($ctx, $row, $code, $users, $taken) {
                        $legacyId = (string) $row->id;
                        $mapped = $ctx->ids()->get(self::ENTITY, $legacyId);
                        $existing = $mapped ? DB::table('registration_codes')->where('id', $mapped)->first() : DB::table('registration_codes')->where('code', $code)->first();
                        if ($existing && ! $mapped && isset($taken[$existing->id])) {
                            return null;
                        }

                        $bad = array_filter([$row->created_at, $row->used_at, $row->expires_at], LegacyClock::unusable(...)) !== [];
                        $createdAt = LegacyClock::toApp($row->created_at) ?? $existing->created_at ?? now()->toDateTimeString();
                        $usedAt = LegacyClock::toApp($row->used_at);
                        $filled = false;
                        if ($row->is_used && $usedAt === null) {
                            $usedAt = $createdAt;
                            $filled = true;
                        }
                        $values = [
                            'code' => $code,
                            'label' => $row->label,
                            'created_by' => $row->created_by !== null ? ($users[$row->created_by] ?? null) : null,
                            'used_by' => $row->used_by !== null ? ($users[$row->used_by] ?? null) : null,
                            'used_at' => $usedAt,
                            'expires_at' => LegacyClock::toApp($row->expires_at),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                        if ($existing) {
                            DB::table('registration_codes')->where('id', $existing->id)->update($values);
                            $id = (int) $existing->id;
                        } else {
                            $id = (int) DB::table('registration_codes')->insertGetId($values);
                        }
                        $ctx->ids()->put(self::ENTITY, $legacyId, $id);

                        return [$id, $filled, $bad];
                    });
                } catch (Throwable $e) {
                    $report->failed(self::ENTITY, "legacy code {$legacyId}: ".ImportReport::describe($e));

                    continue;
                }
                if ($result === null) {
                    $report->skipped(self::ENTITY, 'code already belongs to another imported invitation', ['legacy_id' => $legacyId]);

                    continue;
                }
                [$id, $filled, $bad] = $result;
                if ($bad) {
                    $badDates[] = $legacyId;
                }
                $taken[$id] = $legacyId;
                if ($filled) {
                    $usedAtFilled[] = $legacyId;
                }
                $report->written(self::ENTITY);
            }
        });

        if ($badDates) {
            $report->warn(count($badDates).' invitation(s) had a zero or invalid date, imported as empty (legacy ids: '.implode(', ', array_slice($badDates, 0, 20)).(count($badDates) > 20 ? ', ...' : '').')');
        }
        if ($usedAtFilled) {
            $report->warn(count($usedAtFilled).' used invitation(s) had no used_at, set to created_at (legacy ids: '.implode(', ', array_slice($usedAtFilled, 0, 20)).(count($usedAtFilled) > 20 ? ', ...' : '').')');
        }
    }
}

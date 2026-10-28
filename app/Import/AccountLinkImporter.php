<?php

namespace App\Import;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * intra_users.aktenid => users.personnel_id (+ users.name = personnel.full_name) and
 * intra_registration_codes.mitarbeiter_id => registration_codes.personnel_id, through legacy_id_map.
 * One account per record: the account with the smallest legacy id wins, later ones are skipped with a
 * warning; a link that already exists in the new instance is never moved. Accounts without a record stay as they are.
 */
final class AccountLinkImporter implements Importer
{
    private const ENTITY = 'account_link';

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user', 'registration_code', 'personnel'];
    }

    public function requiredTables(): array
    {
        return ['intra_users', 'intra_registration_codes'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $users = $ctx->ids()->forEntity('user');
        $codes = $ctx->ids()->forEntity('registration_code');
        $personnel = $ctx->ids()->forEntity('personnel');
        $conflicts = [];

        DB::transaction(function () use ($ctx, $report, $users, $codes, $personnel, &$conflicts) {
            foreach ($ctx->legacy()->table('intra_users')->whereNotNull('aktenid')->orderBy('id')->get(['id', 'aktenid']) as $row) {
                $report->read(self::ENTITY);
                $context = ['legacy_user_id' => (string) $row->id, 'legacy_personnel_id' => (string) $row->aktenid];
                $userId = $users[(string) $row->id] ?? null;
                $personnelId = $personnel[(string) $row->aktenid] ?? null;
                if ($userId === null || $personnelId === null) {
                    $report->skipped(self::ENTITY, $userId === null ? 'account not imported' : 'personnel record not imported', $context);

                    continue;
                }
                $current = DB::table('users')->where('id', $userId)->value('personnel_id');
                if ($current !== null && (int) $current !== $personnelId) {
                    $report->skipped(self::ENTITY, 'account is already linked to another record', $context);
                    $conflicts[] = (string) $row->id;

                    continue;
                }
                $holder = DB::table('users')->where('personnel_id', $personnelId)->where('id', '!=', $userId)->value('id');
                if ($holder !== null) {
                    $report->skipped(self::ENTITY, 'record is already linked to another account, the first one wins', $context);
                    $conflicts[] = (string) $row->id;

                    continue;
                }
                $name = DB::table('personnel')->where('id', $personnelId)->value('full_name');
                try {
                    DB::transaction(fn () => DB::table('users')->where('id', $userId)->update(['personnel_id' => $personnelId, 'name' => $name]));
                } catch (UniqueConstraintViolationException) {
                    $report->skipped(self::ENTITY, 'record is already linked to another account, the first one wins', $context);
                    $conflicts[] = (string) $row->id;

                    continue;
                }
                $report->written(self::ENTITY);
            }

            foreach ($ctx->legacy()->table('intra_registration_codes')->whereNotNull('mitarbeiter_id')->orderBy('id')->get(['id', 'mitarbeiter_id']) as $row) {
                $report->read(self::ENTITY);
                $codeId = $codes[(string) $row->id] ?? null;
                $personnelId = $personnel[(string) $row->mitarbeiter_id] ?? null;
                if ($codeId === null || $personnelId === null) {
                    $report->skipped(self::ENTITY, $codeId === null ? 'invitation not imported' : 'personnel record not imported',
                        ['legacy_code_id' => (string) $row->id, 'legacy_personnel_id' => (string) $row->mitarbeiter_id]);

                    continue;
                }
                DB::table('registration_codes')->where('id', $codeId)->update(['personnel_id' => $personnelId]);
                $report->written(self::ENTITY);
            }
        });

        if ($conflicts) {
            $report->warn(count($conflicts).' account(s) not linked because the account or the record is already linked to another account (legacy user ids: '.implode(', ', array_slice($conflicts, 0, 20)).(count($conflicts) > 20 ? ', ...' : '').')');
        }
    }
}

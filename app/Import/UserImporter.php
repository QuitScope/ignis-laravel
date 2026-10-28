<?php

namespace App\Import;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * intra_users => users. The link to the personnel file (aktenid) is not imported here: AccountLinkImporter
 * finds the new user through legacy_id_map (entity `user`) and fills users.personnel_id.
 *
 * Super admin is only ever raised (existing || legacy): the import never demotes the person running it.
 */
final class UserImporter implements Importer
{
    private const ENTITY = 'user';

    private const LIST_LIMIT = 20;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['role'];
    }

    public function requiredTables(): array
    {
        return ['intra_users'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $rows = $ctx->legacy()->table('intra_users')->orderBy('id')->get();

        $default = Role::default();
        /** @var array<int, string> $taken new user id => legacy id */
        $taken = array_flip($ctx->ids()->forEntity(self::ENTITY));
        /** @var array<string, string> $winners discord id => legacy id of the account that keeps it */
        $winners = [];
        /** @var array<string, list<string>> $problems warning key => legacy ids (only rows that succeeded) */
        $problems = [];
        /** @var array<string, string> $deactivatedBy legacy id => deactivated_by legacy id (second pass) */
        $deactivatedBy = [];
        /** @var array<string, int> $secondPass legacy id => new id of rows whose deactivation is copied */
        $secondPass = [];
        $linked = 0;
        $noDiscord = 0;

        try {
            DB::transaction(function () use ($ctx, $report, $rows, $default, &$taken, &$winners, &$problems, &$deactivatedBy, &$secondPass, &$linked, &$noDiscord) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $discord = trim((string) $row->discord_id);

                    if ($discord === '') {
                        $noDiscord++;
                        $report->skipped(self::ENTITY, 'discord_id is empty', ['legacy_id' => $legacyId]);

                        continue;
                    }
                    if (mb_strlen($discord) > 20) {
                        $report->skipped(self::ENTITY, 'discord_id is longer than 20 characters', ['legacy_id' => $legacyId]);

                        continue;
                    }
                    if (isset($winners[$discord])) {
                        $report->skipped(self::ENTITY, 'duplicate discord_id, the account with the smallest legacy id wins', ['legacy_id' => $legacyId, 'kept_legacy_id' => $winners[$discord]]);

                        continue;
                    }
                    $winners[$discord] = $legacyId;

                    try {
                        $result = DB::transaction(fn () => $this->importRow($ctx, $row, $discord, $default, $taken));
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy user {$legacyId}: ".ImportReport::describe($e));
                        app(PermissionRegistrar::class)->forgetCachedPermissions();

                        continue;
                    }
                    if (is_string($result)) {
                        $report->skipped(self::ENTITY, $result, ['legacy_id' => $legacyId]);

                        continue;
                    }

                    [$user, $note, $rowProblems, $keptActive] = $result;
                    $taken[$user->id] = $legacyId;
                    foreach ($rowProblems as $what) {
                        $problems[$what][] = $legacyId;
                    }
                    if (! $keptActive) {
                        $secondPass[$legacyId] = $user->id;
                        if ($row->deactivated_by !== null) {
                            $deactivatedBy[$legacyId] = (string) $row->deactivated_by;
                        }
                    }
                    $linked += $row->aktenid !== null ? 1 : 0;
                    if ($note) {
                        $report->note($note);
                    }
                    $report->written(self::ENTITY);
                }

                foreach ($secondPass as $legacyId => $newId) {
                    $by = isset($deactivatedBy[$legacyId]) ? $ctx->ids()->get(self::ENTITY, $deactivatedBy[$legacyId]) : null;
                    DB::table('users')->where('id', $newId)->update(['deactivated_by' => $by]);
                }
            });
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $this->report($report, $problems, $linked, $noDiscord);
    }

    /**
     * @param  array<int, string>  $taken
     * @return array{0: User, 1: ?string, 2: list<string>, 3: bool}|string user, adoption note, problems of this row, operator kept active; or the reason the row is skipped
     */
    private function importRow(ImportContext $ctx, object $row, string $discord, ?Role $default, array $taken): array|string
    {
        $legacyId = (string) $row->id;
        $note = null;
        $problems = [];

        $user = ($mapped = $ctx->ids()->get(self::ENTITY, $legacyId)) ? User::find($mapped) : null;
        if (! $user) {
            $user = User::where('discord_id', $discord)->first();
            if ($user && isset($taken[$user->id])) {
                return 'discord_id already used by another imported account';
            }
            if ($user) {
                $note = "Legacy user {$legacyId}: adopted existing user (id {$user->id}); super admin and active state are kept if set.";
            }
        } elseif ($user->discord_id !== $discord && ($other = User::where('discord_id', $discord)->whereKeyNot($user->id)->first())) {
            return isset($taken[$other->id]) ? 'discord_id already used by another imported account' : 'discord_id already used by a non-imported target account';
        }
        $user ??= new User;

        $username = trim((string) $row->username);
        if ($username === '') {
            $username = "user-{$legacyId}";
            $problems[] = 'empty username replaced by user-<id>';
        } elseif (mb_strlen($username) > 100) {
            $username = mb_substr($username, 0, 100);
            $problems[] = 'username truncated to 100 characters';
        }
        $theme = User::themeOrDefault($row->theme ?? null);
        if ($theme !== ($row->theme ?? 'dark')) {
            $problems[] = 'theme is not dark/light/system, set to dark';
        }
        if (LegacyClock::unusable($row->created_at) || LegacyClock::unusable($row->deactivated_at)) {
            $problems[] = 'zero or invalid date, imported as empty';
        }
        $createdAt = LegacyClock::toApp($row->created_at) ?? $user->created_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s');

        // The person running the import must never be locked out or demoted by legacy data.
        $operator = $user->exists && $user->is_super_admin;
        $legacyActive = $row->is_active === null ? true : (bool) $row->is_active;
        $keptActive = $operator && ! $legacyActive && $user->is_active;

        $user->timestamps = false;
        $user->discord_id = $discord;
        $user->username = $username;
        $user->name = trim((string) $row->fullname) ?: null;
        $user->is_super_admin = (bool) $user->is_super_admin || (bool) $row->full_admin;
        if ($keptActive) {
            $problems[] = 'operator account kept active (legacy account was inactive)';
        } else {
            $user->is_active = $legacyActive;
            $user->deactivated_at = LegacyClock::toApp($row->deactivated_at);
        }
        $user->theme = $theme;
        $user->discord_dm = (bool) (int) ($row->discord_dm ?? 1);
        $user->created_at = $createdAt;
        $user->updated_at = $createdAt;
        $user->save();

        $role = ($roleId = $ctx->ids()->get('role', (string) $row->role)) ? Role::find($roleId) : null;
        if (! $role) {
            $role = $default;
            $problems[] = $default ? 'role missing or not imported, default role assigned' : 'role missing or not imported and no default role exists, user has no role';
        }
        if ($role) {
            $user->setRole($role);
        }

        $ctx->ids()->put(self::ENTITY, $legacyId, $user->id);

        return [$user, $note, $problems, $keptActive];
    }

    /** @param array<string, list<string>> $problems */
    private function report(ImportReport $report, array $problems, int $linked, int $noDiscord): void
    {
        foreach ($problems as $what => $ids) {
            $shown = implode(', ', array_slice($ids, 0, self::LIST_LIMIT)).(count($ids) > self::LIST_LIMIT ? ', ...' : '');
            $report->warn(count($ids)." user(s): {$what} (legacy ids: {$shown})");
        }
        if ($noDiscord > 0) {
            $report->note("{$noDiscord} Konten ohne Discord-ID nicht übernommen (z. B. Fabrica-Konten)");
        }
        if ($linked > 0 && ! in_array(AccountLinkImporter::class, (array) config('ignis.importers'), true)) {
            $report->note("{$linked} Konten mit Aktenverknüpfung warten auf den Personal-Import");
        }
    }
}

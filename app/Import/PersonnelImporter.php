<?php

namespace App\Import;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * intra_mitarbeiter => personnel (+ personnel_specialty). Rows are written with the query builder, so no
 * PersonnelSaved listener (auto link, name sync) runs; account links come from AccountLinkImporter.
 *
 * - Service number: trimmed; duplicates (case-insensitive) keep the first legacy row. An unmapped record
 *   with the same number in the new instance is adopted.
 * - Rank missing => row failed. Fire/EMS qualification missing => the "Keine" row (is_none) + warning.
 * - discordtag is kept only when it is a Discord ID (17-20 digits).
 * - fachdienste: JSON list of specialty numbers (legacy sgnr, as strings), see legacy UpdateFachdiensteRequest.
 * - pfp: only the file name after `storage/profile-pictures/` is used; the default picture means none. The file
 *   is copied by ProfilePictureCopier (only with --uploads); without it, stored pictures are kept as they are.
 * - Dates (gebdatum, einstdatum) are calendar dates and are not shifted; createdate goes through LegacyClock.
 */
final class PersonnelImporter implements Importer
{
    private const ENTITY = 'personnel';

    private const LIST_LIMIT = 20;

    public function __construct(private readonly ProfilePictureCopier $copier) {}

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['rank', 'fire_qualification', 'ems_qualification', 'specialty', 'personnel_title'];
    }

    public function requiredTables(): array
    {
        return ['intra_mitarbeiter', 'intra_mitarbeiter_fdquali'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $ids = $ctx->ids();
        $maps = [
            'rank' => $ids->forEntity('rank'), 'fire' => $ids->forEntity('fire_qualification'),
            'ems' => $ids->forEntity('ems_qualification'), 'title' => $ids->forEntity('personnel_title'),
        ];
        $none = [
            'fire' => DB::table('fire_qualifications')->where('is_none', true)->orderBy('id')->value('id'),
            'ems' => DB::table('ems_qualifications')->where('is_none', true)->orderBy('id')->value('id'),
        ];
        $specialties = $ids->forEntity('specialty');
        /** @var array<string, int> $bySgnr legacy specialty number => new specialty id (first legacy row wins) */
        $bySgnr = [];
        foreach ($ctx->legacy()->table('intra_mitarbeiter_fdquali')->orderBy('id')->get(['id', 'sgnr']) as $fd) {
            if (isset($specialties[$fd->id])) {
                $bySgnr[(string) $fd->sgnr] ??= $specialties[$fd->id];
            }
        }
        /** @var array<string, int> $bySn lowercase service number => target personnel id */
        $bySn = [];
        foreach (DB::table('personnel')->pluck('service_number', 'id') as $id => $sn) {
            $bySn[mb_strtolower((string) $sn)] ??= (int) $id;
        }
        /** @var array<int, string> $taken new id => legacy id */
        $taken = array_flip($ids->forEntity(self::ENTITY));
        $state = ['seen' => [], 'problems' => [], 'unknownSpecialties' => 0, 'unknownSpecialtyIds' => [], 'notCopied' => 0];

        $rows = $ctx->legacy()->table('intra_mitarbeiter')->orderBy('id')->get();
        DB::transaction(function () use ($ctx, $report, $rows, $maps, $none, $bySgnr, &$bySn, &$taken, &$state) {
            foreach ($rows as $row) {
                $report->read(self::ENTITY);
                $legacyId = (string) $row->id;
                $sn = trim((string) $row->dienstnr);
                $key = mb_strtolower($sn);
                if ($sn === '') {
                    $report->skipped(self::ENTITY, 'service number is empty', ['legacy_id' => $legacyId]);

                    continue;
                }
                if (isset($state['seen'][$key])) {
                    $report->skipped(self::ENTITY, 'duplicate service number, the first one wins', ['legacy_id' => $legacyId, 'kept_legacy_id' => $state['seen'][$key]]);

                    continue;
                }
                $state['seen'][$key] = $legacyId;

                $copied = null;
                try {
                    [$id, $note, $problems, $unknown, $notCopied] = DB::transaction(function () use ($ctx, $row, $sn, $maps, $none, $bySgnr, $bySn, $key, $taken, &$copied) {
                        return $this->importRow($ctx, $row, $sn, $maps, $none, $bySgnr, $bySn[$key] ?? null, $taken, $copied);
                    });
                } catch (Throwable $e) {
                    if ($copied !== null && ! $ctx->dryRun()) {
                        Storage::disk(ProfilePictureCopier::DISK)->delete($copied);
                    }
                    $report->failed(self::ENTITY, "legacy personnel {$legacyId}: ".($e instanceof DomainException ? $e->getMessage() : ImportReport::describe($e)));

                    continue;
                }
                $taken[$id] = $legacyId;
                $bySn[$key] = $id;
                foreach ($problems as $what) {
                    $state['problems'][$what][] = $legacyId;
                }
                if ($unknown > 0) {
                    $state['unknownSpecialties'] += $unknown;
                    $state['unknownSpecialtyIds'][] = $legacyId;
                }
                $state['notCopied'] += $notCopied ? 1 : 0;
                if ($note !== null) {
                    $report->note($note);
                }
                $report->written(self::ENTITY);
            }
        });

        foreach ($state['problems'] as $what => $legacyIds) {
            $report->warn(count($legacyIds)." personnel record(s): {$what} (legacy ids: ".self::list($legacyIds).')');
        }
        if ($state['unknownSpecialties'] > 0) {
            $report->warn("{$state['unknownSpecialties']} unknown specialty number(s) in fachdienste ignored (legacy ids: ".self::list($state['unknownSpecialtyIds']).')');
        }
        if ($state['notCopied'] > 0) {
            $report->note("{$state['notCopied']} Profilbilder nicht kopiert (ohne --uploads; vorhandene Bilder bleiben)");
        }
    }

    /**
     * @param  array<string, array<int|string, int>>  $maps
     * @param  array<string, mixed>  $none
     * @param  array<string, int>  $bySgnr
     * @param  array<int, string>  $taken
     * @return array{0: int, 1: ?string, 2: list<string>, 3: int, 4: bool} id, adoption note, problems, unknown specialty entries, picture not copied
     */
    private function importRow(ImportContext $ctx, object $row, string $sn, array $maps, array $none, array $bySgnr, ?int $sameNumber, array $taken, ?string &$copied): array
    {
        $legacyId = (string) $row->id;
        $problems = [];
        $note = null;

        $rankId = $maps['rank'][$row->dienstgrad] ?? null;
        if ($rankId === null) {
            throw new DomainException("rank {$row->dienstgrad} was not imported");
        }
        $birth = self::date($row->gebdatum);
        $hired = self::date($row->einstdatum);
        if ($birth === null || $hired === null) {
            throw new DomainException('birth date or hire date is not a valid date');
        }
        $qualification = [];
        foreach (['fire' => $row->qualifw2, 'ems' => $row->qualird] as $area => $legacy) {
            $qualification[$area] = $maps[$area][$legacy] ?? null;
            if ($qualification[$area] === null) {
                $qualification[$area] = $none[$area] ?? throw new DomainException("{$area} qualification {$legacy} was not imported and there is no \"Keine\" row");
                $problems[] = "{$area} qualification not imported, \"Keine\" used";
            }
        }
        $titleId = null;
        if ($row->titel_id !== null) {
            $titleId = $maps['title'][$row->titel_id] ?? null;
            if ($titleId === null) {
                $problems[] = 'title not imported, imported without title';
            }
        }
        $discord = trim((string) $row->discordtag);
        if ($discord !== '' && preg_match('/^\d{17,20}$/D', $discord) !== 1) {
            $problems[] = 'discord tag is not a Discord ID (17-20 digits), imported as empty';
        }
        $gender = (int) $row->geschlecht;
        if (! in_array($gender, [0, 1, 2], true)) {
            $problems[] = "gender {$gender} is unknown, set to diverse";
            $gender = 2;
        }
        [$specialtyIds, $unknown, $badList] = self::specialties($row->fachdienste, $bySgnr);
        if ($badList) {
            $problems[] = 'fachdienste is not a JSON list, imported without specialties';
        }

        $id = $ctx->ids()->get(self::ENTITY, $legacyId);
        $existing = $id !== null ? DB::table('personnel')->where('id', $id)->first(['id', 'profile_picture', 'created_at']) : null;
        if ($existing === null && $sameNumber !== null && ! isset($taken[$sameNumber])) {
            $existing = DB::table('personnel')->where('id', $sameNumber)->first(['id', 'profile_picture', 'created_at']);
            $note = "Legacy personnel {$legacyId}: adopted existing record with service number {$sn} (id {$sameNumber}).";
        }

        [$picture, $notCopied] = $this->picture($ctx, $row->pfp, $existing?->profile_picture, $problems);
        if ($picture !== null && $picture !== $existing?->profile_picture) {
            // ponytail: a replaced legacy picture leaves the previous copy on disk; clean up by hand if it matters.
            $copied = $picture;
        }

        $at = LegacyClock::toApp($row->createdate) ?? $existing->created_at ?? now()->toDateTimeString();
        $values = [
            'title_id' => $titleId,
            'full_name' => (string) $row->fullname,
            'birth_date' => $birth,
            'character_id' => $row->charakterid,
            'gender' => $gender,
            'forum_profile' => $row->forumprofil !== null && (int) $row->forumprofil >= 0 ? (int) $row->forumprofil : null,
            'discord_id' => preg_match('/^\d{17,20}$/D', $discord) === 1 ? $discord : null,
            'phone' => $row->telefonnr,
            'service_number' => $sn,
            'hired_at' => $hired,
            'rank_id' => $rankId,
            'fire_qualification_id' => $qualification['fire'],
            'ems_qualification_id' => $qualification['ems'],
            'extra_qualification' => $row->zusatz,
            'profile_picture' => $picture,
            'created_at' => $at,
            'updated_at' => $at,
        ];
        if ($existing !== null) {
            $id = (int) $existing->id;
            DB::table('personnel')->where('id', $id)->update($values);
        } else {
            $id = (int) DB::table('personnel')->insertGetId($values);
        }
        DB::table('personnel_specialty')->where('personnel_id', $id)->delete();
        DB::table('personnel_specialty')->insert(array_map(fn (int $s) => ['personnel_id' => $id, 'specialty_id' => $s], $specialtyIds));
        $ctx->ids()->put(self::ENTITY, $legacyId, $id);

        return [$id, $note, $problems, $unknown, $notCopied];
    }

    /**
     * @param  list<string>  $problems
     * @return array{0: ?string, 1: bool} new profile_picture, true when a legacy picture was not copied (no --uploads)
     */
    private function picture(ImportContext $ctx, ?string $pfp, ?string $current, array &$problems): array
    {
        $pfp = trim((string) $pfp);
        if ($pfp === '' || str_ends_with($pfp, 'assets/img/empty_user.png')) {
            return [null, false];
        }
        // Only a plain path (legacy BASE_PATH + storage/profile-pictures/<name>); no scheme, no host.
        if (preg_match('#^(?!//)/?(?:[^/:]+/)*storage/profile-pictures/(.+)$#D', $pfp, $m) !== 1) {
            $problems[] = 'picture path not recognised, imported without picture';

            return [null, false];
        }
        if ($ctx->uploadsDir() === null) {
            return [$current, true];
        }
        $name = $this->copier->copy($ctx->uploadsDir(), $m[1], $ctx->dryRun(), $current);
        if ($name === null) {
            $problems[] = "picture file missing or invalid ({$this->copier->error}), imported without picture";
        }

        return [$name, false];
    }

    /**
     * @param  array<string, int>  $bySgnr
     * @return array{0: list<int>, 1: int, 2: bool} specialty ids, unknown entries, column unusable
     */
    private static function specialties(?string $raw, array $bySgnr): array
    {
        if ($raw === null || trim($raw) === '') {
            return [[], 0, false];
        }
        $list = json_decode($raw, true);
        if (! is_array($list)) {
            return [[], 0, true];
        }
        $ids = [];
        $unknown = 0;
        foreach ($list as $number) {
            if (is_scalar($number) && isset($bySgnr[(string) $number])) {
                $ids[$bySgnr[(string) $number]] = true;
            } else {
                $unknown++;
            }
        }

        return [array_keys($ids), $unknown, false];
    }

    public static function date(mixed $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $value), $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /** @param list<string> $ids */
    private static function list(array $ids): string
    {
        return implode(', ', array_slice($ids, 0, self::LIST_LIMIT)).(count($ids) > self::LIST_LIMIT ? ', ...' : '');
    }
}

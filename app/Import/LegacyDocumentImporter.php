<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * intra_mitarbeiter_dokumente (documents from before the editor, states T0-T2) => legacy_documents, every row,
 * upserted by legacy_id in blocks. Nothing is converted; what has no column goes into
 * legacy_payload as it was: inhalt, suspendtime, the rank columns, template_id (wrong for old rows, analysis
 * 1.2: `type` is the truth) and custom_data as raw text (also when it is not valid JSON).
 *
 * - Dates are calendar dates; a zero or invalid date becomes null and its raw value goes into legacy_payload.
 * - Recipient record via the id map (profileid); a record that was not imported leaves personnel_id empty
 *   (the column is nullable), so no document is lost.
 * - Issuer account via the id map (aussteller_user_id); otherwise empty, the Discord ID (ausstellerid) stays.
 * - PDF (only with --uploads): `storage/documents/{pdf_path ?: docid.pdf}` is copied by LegacyPdfCopier to
 *   `legacy/{docid}.pdf` and pdf_path becomes `{docid}.pdf`: the name is the id, so public links
 *   keep working. Only docids of the two legacy forms get a PDF, and no 7-digit docid that is also an editor docid
 *   (that file is the editor's older PDF). Missing files: row without PDF. storage/documents missing
 *   below --uploads counts as "not copied" and keeps the current pdf_path.
 */
final class LegacyDocumentImporter implements ChecksLegacyState, Importer
{
    private const ENTITY = 'legacy_document';

    private const CHUNK = 1000;

    private const LIST_LIMIT = 20;

    public const PDF_FOLDER = 'storage/documents';

    private const ID_PATTERN = '/^(?:[0-9]{7}|[A-Z2-9]{4}(?:-[A-Z2-9]{4}){2})$/D';

    /** @var array<string, int> intra_documents docids (flipped) */
    private array $editorIds = [];

    public function __construct(private readonly LegacyPdfCopier $copier) {}

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['personnel', 'user'];
    }

    public function requiredTables(): array
    {
        return ['intra_mitarbeiter_dokumente', 'intra_documents'];
    }

    public function checkLegacyState(LegacyReader $legacy): void
    {
        DocumentTemplateImporter::assertEditorState($legacy);
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $maps = ['personnel' => $ctx->ids()->forEntity('personnel'), 'user' => $ctx->ids()->forEntity('user')];
        $this->editorIds = array_flip($ctx->legacy()->table('intra_documents')->pluck('docid')->map(fn ($d) => trim((string) $d))->all());
        $s = [
            'missingFolder' => false, 'chunks' => 0, 'problems' => [], 'noPdf' => [], 'noPersonnel' => [], 'referenced' => [],
            'pdf' => ['copied' => 0, 'unchanged' => 0, 'notCopied' => 0],
        ];

        DB::transaction(function () use ($ctx, $report, $maps, &$s) {
            $ctx->legacy()->table('intra_mitarbeiter_dokumente')->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $maps, &$s) {
                $s['chunks']++;
                $report->read(self::ENTITY, count($rows));
                $current = DB::table('legacy_documents')
                    ->whereIn('legacy_id', $rows->pluck('id')->all())
                    ->orWhereIn('public_id', $rows->map(fn ($r) => trim((string) $r->docid))->all())
                    ->get(['legacy_id', 'public_id', 'pdf_path']);
                $byPublic = $current->keyBy('public_id');
                $byLegacy = $current->whereNotNull('legacy_id')->keyBy('legacy_id');

                $batch = [];
                /** @var array<int, string> $new legacy id => PDF written for that row only */
                $new = [];
                foreach ($rows as $row) {
                    $s['referenced'][trim((string) $row->pdf_path) ?: trim((string) $row->docid).'.pdf'] = true;
                    $other = $byPublic->get(trim((string) $row->docid));
                    if ($other !== null && (string) $other->legacy_id !== (string) $row->id) {
                        $report->failed(self::ENTITY, "legacy document {$row->id}: public id ".trim((string) $row->docid).' is already used by another legacy document of the new instance');

                        continue;
                    }
                    [$values, $problems, $state] = $this->values($ctx, $row, $maps, $byLegacy->get($row->id)?->pdf_path, $new, $s['noPersonnel']);
                    foreach ($problems as $what) {
                        $s['problems'][$what][] = (string) $row->id;
                    }
                    if (is_array($state)) {
                        $s['noPdf'][$state[0]][] = $values['public_id'];
                    } elseif ($state === 'folder') {
                        $s['missingFolder'] = true;
                        $s['pdf']['notCopied']++;
                    } elseif ($state !== null) {
                        $s['pdf'][$state]++;
                    }
                    $batch[] = $values;
                }
                $this->write($ctx, $report, $batch, $new);
            }, 'id');
        });

        $this->summary($ctx, $report, $s);
    }

    /**
     * @param  array<string, array<int|string, int>>  $maps
     * @param  array<int, string>  $new
     * @param  array<string, true>  $noPersonnel
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|array{0: string}|null}
     */
    private function values(ImportContext $ctx, object $row, array $maps, ?string $currentPdf, array &$new, array &$noPersonnel): array
    {
        $docid = trim((string) $row->docid);
        $problems = [];
        $int = fn (mixed $v) => $v === null ? null : (int) $v;
        $payload = [
            'inhalt' => $row->inhalt,
            'suspendtime' => $row->suspendtime,
            'erhalter_rang' => $int($row->erhalter_rang),
            'erhalter_rang_rd' => $int($row->erhalter_rang_rd),
            'erhalter_quali' => $int($row->erhalter_quali),
            'aussteller_rang' => $int($row->aussteller_rang),
            'template_id' => $int($row->template_id),
            'custom_data' => $row->custom_data,
        ];
        if ($row->custom_data !== null && trim((string) $row->custom_data) !== '' && ! json_validate((string) $row->custom_data)) {
            $problems[] = 'custom_data is not valid JSON, kept as text in legacy_payload';
        }
        $dates = [];
        foreach (['erhalter_gebdat', 'ausstellungsdatum'] as $column) {
            $dates[$column] = PersonnelImporter::date($row->{$column});
            if ($dates[$column] === null && trim((string) $row->{$column}) !== '') {
                $payload[$column] = (string) $row->{$column};
                $problems[] = 'zero or invalid date, kept in legacy_payload';
            }
        }
        $personnelId = $row->profileid === null ? null : ($maps['personnel'][(string) $row->profileid] ?? null);
        if ($personnelId === null) {
            $noPersonnel[$row->profileid === null ? 'none' : (string) $row->profileid] = true;
            $problems[] = 'personnel record not imported or none, imported without record';
        }
        $issuer = null;
        if ($row->aussteller_user_id !== null) {
            $issuer = $maps['user'][(string) $row->aussteller_user_id] ?? null;
            if ($issuer === null) {
                $problems[] = 'issuing account not imported, Discord ID kept';
            }
        } elseif (trim((string) $row->ausstellerid) !== '') {
            // The legacy backfill left aussteller_user_id empty when the Discord ID was not unique.
            $problems[] = 'issuer without account (unknown or ambiguous Discord ID), Discord ID kept';
        }
        $createdAt = LegacyClock::toApp($row->timestamp);
        if ($createdAt === null) {
            $createdAt = now()->toDateTimeString();
            $problems[] = 'zero or invalid timestamp, import time used';
        }

        [$pdfPath, $state] = $this->pdf($ctx, $row, $docid, $currentPdf, $new);
        if ($state === 'badId') {
            $problems[] = 'docid in an unknown form, imported without PDF';
            $state = null;
        } elseif ($state === 'editorId') {
            $problems[] = 'docid is also an editor document id, imported without PDF';
            $state = null;
        }

        return [[
            'legacy_id' => (int) $row->id,
            'public_id' => $docid,
            'type' => (int) $row->type,
            'personnel_id' => $personnelId,
            'recipient_name' => $row->erhalter,
            'recipient_discord_id' => $row->discordid,
            'salutation' => (int) $row->anrede,
            'recipient_birthdate' => $dates['erhalter_gebdat'],
            'issued_on' => $dates['ausstellungsdatum'],
            'issuer_user_id' => $issuer,
            'issuer_discord_id' => $row->ausstellerid === null ? null : (string) $row->ausstellerid,
            'issuer_name' => $row->aussteller_name,
            'is_archived' => (bool) $row->is_archived,
            'pdf_path' => $pdfPath,
            'pdf_generated_at' => LegacyClock::toApp($row->pdf_generated_at),
            'legacy_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => $createdAt,
        ], array_values(array_unique($problems)), $state];
    }

    /**
     * @param  array<int, string>  $new
     * @return array{0: ?string, 1: string|array{0: string}|null} pdf_path, state (copied|unchanged|notCopied|badId, [reason] when missing)
     */
    private function pdf(ImportContext $ctx, object $row, string $docid, ?string $current, array &$new): array
    {
        if (preg_match(self::ID_PATTERN, $docid) !== 1) {
            return [null, 'badId'];
        }
        if (isset($this->editorIds[$docid])) {
            return [null, 'editorId'];
        }
        $name = $docid.'.pdf';
        if ($ctx->uploadsDir() === null) {
            return [$current === $name ? $name : null, 'notCopied'];
        }
        if (! is_dir($ctx->uploadsDir().'/'.self::PDF_FOLDER)) {
            return [$current === $name ? $name : null, 'folder'];
        }
        $target = 'legacy/'.$name;
        $existed = Storage::disk(LegacyPdfCopier::DISK)->exists($target);
        if (! $this->copier->copy($ctx->uploadsDir(), [self::PDF_FOLDER], trim((string) $row->pdf_path) ?: $name, $target, $ctx->dryRun())) {
            return [null, [(string) $this->copier->error]];
        }
        if ($this->copier->wrote && ! $existed) {
            $new[(int) $row->id] = $target;
        }

        return [$name, $this->copier->changed ? 'copied' : 'unchanged'];
    }

    /**
     * Upserts the block; when it fails, row by row so one bad row costs one failure (and its new PDF).
     *
     * @param  list<array<string, mixed>>  $batch
     * @param  array<int, string>  $new
     */
    private function write(ImportContext $ctx, ImportReport $report, array $batch, array $new): void
    {
        if ($batch === []) {
            return;
        }
        try {
            DB::transaction(fn () => $this->upsert($batch));
            $report->written(self::ENTITY, count($batch));

            return;
        } catch (Throwable) {
        }
        foreach ($batch as $values) {
            try {
                DB::transaction(fn () => $this->upsert([$values]));
                $report->written(self::ENTITY);
            } catch (Throwable $e) {
                if (isset($new[$values['legacy_id']]) && ! $ctx->dryRun()) {
                    Storage::disk(LegacyPdfCopier::DISK)->delete($new[$values['legacy_id']]);
                }
                $report->failed(self::ENTITY, "legacy document {$values['legacy_id']}: ".ImportReport::describe($e));
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(array $rows): void
    {
        DB::table('legacy_documents')->upsert($rows, ['legacy_id'], array_values(array_diff(array_keys($rows[0]), ['legacy_id'])));
    }

    /** @param array<string, mixed> $s */
    private function summary(ImportContext $ctx, ImportReport $report, array $s): void
    {
        $report->note(self::ENTITY.": {$s['chunks']} chunks of up to ".self::CHUNK.' rows');
        foreach ($s['problems'] as $what => $legacyIds) {
            $report->warn(count($legacyIds)." legacy document(s): {$what} (legacy ids: ".self::list($legacyIds).')');
        }
        if ($s['noPersonnel'] !== []) {
            $report->warn('legacy documents without imported record belong to profileid: '.self::list(array_map('strval', array_keys($s['noPersonnel']))));
        }
        foreach ($s['noPdf'] as $reason => $docids) {
            $report->warn(count($docids)." legacy document(s) imported without PDF: {$reason} (docid: ".self::list($docids).')');
        }
        if ($ctx->uploadsDir() === null) {
            if ($s['pdf']['notCopied'] > 0) {
                $report->note("{$s['pdf']['notCopied']} Altdokument-PDFs nicht kopiert (ohne --uploads; vorhandene bleiben)");
            }

            return;
        }
        if ($s['missingFolder']) {
            $report->warn(self::ENTITY.': folder '.self::PDF_FOLDER.' missing below --uploads; those PDFs were not copied, existing copies kept');
        }
        $report->note(self::ENTITY.": {$s['pdf']['copied']} PDF(s) ".($ctx->dryRun() ? 'would be copied' : 'copied').", {$s['pdf']['unchanged']} unchanged");
        // storage/documents also holds older editor PDFs ({docid}.pdf of intra_documents): not orphans.
        $known = array_merge(
            array_map('strval', array_keys($s['referenced'])),
            array_map(fn ($d) => $d.'.pdf', array_keys($this->editorIds)),
        );
        $orphans = DocumentImporter::orphanFiles($ctx->uploadsDir().'/'.self::PDF_FOLDER, $known);
        if ($orphans > 0) {
            $report->warn("{$orphans} PDF file(s) in ".self::PDF_FOLDER.' without a document row (not imported)');
        }
    }

    /** @param list<string> $ids */
    private static function list(array $ids): string
    {
        return implode(', ', array_slice($ids, 0, self::LIST_LIMIT)).(count($ids) > self::LIST_LIMIT ? ', ...' : '');
    }
}

<?php

namespace App\Import;

use App\Documents\DocumentValidator;
use App\Documents\InvalidDocument;
use App\Models\Document;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * intra_documents (editor documents, legacy state T3) => documents, 1:1 (the JSON is already the target format).
 *
 * - docid stays the public id; a public id the new instance already uses for another document fails the row.
 * - mitarbeiter_id, template_id, created_by, issued_by through the id map. A document whose record was not
 *   imported is skipped (documents.personnel_id is required); a missing template or account leaves the column
 *   empty (warning).
 * - content must pass DocumentValidator, frozen_values must be a JSON object of texts; otherwise the row fails
 *   (counted, the run goes on). Content the validator accepts but the editor would refuse is imported with a
 *   warning ({@see self::editorShapeProblem()}).
 * - Status entwurf => draft, ausgestellt => issued. Timestamps through LegacyClock.
 * - PDF of an issued document (only with --uploads): the legacy pdf_path when it is one of the two locations the
 *   legacy PdfGenerator::read accepts; with an empty pdf_path `storage/private/editor-documents/{docid}.pdf`, then
 *   `storage/documents/{docid}.pdf`. Copied by LegacyPdfCopier to `editor/{public_id}.pdf` (the path PdfGenerator
 *   writes). pdf_path is set only when the file is there; issued documents without PDF are reported. A source
 *   folder missing below --uploads counts as "not copied" and keeps the current pdf_path.
 * - A document already issued in the new instance is never turned back into a draft: the row is kept as it is.
 */
final class DocumentImporter implements ChecksLegacyState, Importer
{
    private const ENTITY = 'document';

    private const CHUNK = 500;

    private const LIST_LIMIT = 20;

    public const PDF_FOLDERS = ['storage/private/editor-documents', 'storage/documents'];

    private const STATUS = ['entwurf' => Document::DRAFT, 'ausgestellt' => Document::ISSUED];

    private const NON_EMPTY = ['docSection', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'table', 'tableCell', 'tableHeader'];

    public function __construct(private readonly LegacyPdfCopier $copier) {}

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['personnel', 'user', 'document_template'];
    }

    public function requiredTables(): array
    {
        return ['intra_documents'];
    }

    public function checkLegacyState(LegacyReader $legacy): void
    {
        DocumentTemplateImporter::assertEditorState($legacy);
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $maps = ['personnel' => $ctx->ids()->forEntity('personnel'), 'template' => $ctx->ids()->forEntity('document_template'), 'user' => $ctx->ids()->forEntity('user')];
        /** @var array<string, list<string>> $problems warning => legacy ids */
        $problems = [];
        /** @var array<string, list<string>> $noPdf reason => docids of issued documents without PDF */
        $noPdf = [];
        $pdf = ['copied' => 0, 'unchanged' => 0, 'notCopied' => 0];
        /** @var array<string, true> $missingFolders source folders not found below --uploads */
        $missingFolders = [];
        /** @var array<string, true> $orphans mitarbeiter_id of skipped documents */
        $orphans = [];
        $orphanCount = 0;

        DB::transaction(function () use ($ctx, $report, $maps, &$problems, &$noPdf, &$pdf, &$missingFolders, &$orphans, &$orphanCount) {
            $ctx->legacy()->table('intra_documents')->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $maps, &$problems, &$noPdf, &$pdf, &$missingFolders, &$orphans, &$orphanCount) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $personnelId = $maps['personnel'][(string) $row->mitarbeiter_id] ?? null;
                    if ($personnelId === null) {
                        $orphanCount++;
                        $orphans[(string) $row->mitarbeiter_id] = true;

                        continue;
                    }
                    $written = null;
                    try {
                        [$rowProblems, $state] = DB::transaction(function () use ($ctx, $row, $personnelId, $maps, &$written) {
                            return $this->importRow($ctx, $row, $personnelId, $maps, $written);
                        });
                    } catch (Throwable $e) {
                        if ($written !== null && ! $ctx->dryRun()) {
                            Storage::disk(LegacyPdfCopier::DISK)->delete($written);
                        }
                        $report->failed(self::ENTITY, "legacy document {$legacyId}: ".($e instanceof DomainException ? $e->getMessage() : ImportReport::describe($e)));

                        continue;
                    }
                    foreach ($rowProblems as $what) {
                        $problems[$what][] = $legacyId;
                    }
                    if (is_string($state) && str_starts_with($state, 'folder:')) {
                        $missingFolders[substr($state, 7)] = true;
                        $pdf['notCopied']++;
                    } elseif (is_string($state) && isset($pdf[$state])) {
                        $pdf[$state]++;
                    } elseif (is_array($state)) {
                        $noPdf[$state[0]][] = (string) $row->docid;
                    }
                    $report->written(self::ENTITY);
                }
            }, 'id');
        });

        if ($orphanCount > 0) {
            $report->skippedCount(self::ENTITY, $orphanCount);
            $ids = array_keys($orphans);
            $report->warn("{$orphanCount} document(s) of ".count($ids).' personnel record(s) that were not imported were skipped (mitarbeiter_id: '.self::list($ids).')');
        }
        foreach ($problems as $what => $legacyIds) {
            $report->warn(count($legacyIds)." document(s): {$what} (legacy ids: ".self::list($legacyIds).')');
        }
        foreach ($noPdf as $reason => $docids) {
            $report->warn(count($docids)." issued document(s) imported without PDF: {$reason} (docid: ".self::list($docids).')');
        }
        foreach (array_keys($missingFolders) as $folder) {
            $report->warn("document: folder {$folder} missing below --uploads; those PDFs were not copied, existing copies kept");
        }
        if ($ctx->uploadsDir() !== null) {
            $report->note("document: {$pdf['copied']} PDF(s) ".($ctx->dryRun() ? 'would be copied' : 'copied').", {$pdf['unchanged']} unchanged");
            $known = $ctx->legacy()->table('intra_documents')->pluck('docid')->map(fn ($d) => trim((string) $d).'.pdf')->all();
            $orphanFiles = self::orphanFiles($ctx->uploadsDir().'/'.self::PDF_FOLDERS[0], $known);
            if ($orphanFiles > 0) {
                $report->warn("{$orphanFiles} PDF file(s) in ".self::PDF_FOLDERS[0].' without a document row (not imported)');
            }
        } elseif ($pdf['notCopied'] > 0) {
            $report->note("{$pdf['notCopied']} Dokument-PDFs nicht kopiert (ohne --uploads; vorhandene bleiben)");
        }
    }

    /**
     * @param  array<string, array<int|string, int>>  $maps
     * @return array{0: list<string>, 1: string|array{0: string}|null} problems, PDF state (copied|unchanged|notCopied, [reason] when missing)
     */
    private function importRow(ImportContext $ctx, object $row, int $personnelId, array $maps, ?string &$written): array
    {
        $docid = trim((string) $row->docid);
        if (preg_match('/^[0-9]{7}$/D', $docid) !== 1) {
            throw new DomainException('docid "'.DocumentValidator::short($docid).'" is not 7 digits');
        }
        $status = self::STATUS[(string) $row->status] ?? throw new DomainException('unknown status "'.DocumentValidator::short((string) $row->status).'"');
        $content = json_decode((string) $row->content, true);
        if (! is_array($content)) {
            throw new DomainException('content is not a JSON object');
        }
        try {
            $content = DocumentValidator::validate($content, false);
        } catch (InvalidDocument $e) {
            throw new DomainException('content rejected by the validator: '.$e->getMessage());
        }
        $frozen = self::frozen($row->frozen_values);

        $problems = [];
        if (($shape = self::editorShapeProblem($content)) !== null) {
            $problems[] = "would not open in the editor ({$shape}), imported as is";
        }
        $templateId = null;
        if ($row->template_id !== null) {
            $templateId = $maps['template'][(string) $row->template_id] ?? null;
            if ($templateId === null) {
                $problems[] = 'template not imported, imported without template';
            }
        }
        $accounts = [];
        foreach (['created_by', 'issued_by'] as $column) {
            $accounts[$column] = $row->{$column} === null ? null : ($maps['user'][(string) $row->{$column}] ?? null);
            if ($row->{$column} !== null && $accounts[$column] === null) {
                $problems[] = "{$column} account not imported, left empty";
            }
        }
        $createdAt = LegacyClock::toApp($row->created_at);
        if ($createdAt === null) {
            $createdAt = now()->toDateTimeString();
            $problems[] = 'zero or invalid created_at, import time used';
        }

        $id = $ctx->ids()->get(self::ENTITY, (string) $row->id);
        $existing = $id !== null ? DB::table('documents')->where('id', $id)->first(['id', 'pdf_path', 'status']) : null;
        if ($existing === null && DB::table('documents')->where('public_id', $docid)->exists()) {
            throw new DomainException("public id {$docid} is already used by another document of the new instance");
        }
        if ($existing !== null && $existing->status === Document::ISSUED && $status !== Document::ISSUED) {
            // Issuing happens once; an issued document is read-only, so nothing of the legacy draft is taken over.
            return [['issued in the new instance but a draft in the legacy system, kept as issued'], null];
        }

        [$pdfPath, $state] = $this->pdf($ctx, $status, $docid, (string) $row->pdf_path, $existing?->pdf_path, $written);

        $values = [
            'public_id' => $docid,
            'template_id' => $templateId,
            'personnel_id' => $personnelId,
            'title' => (string) $row->title,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'frozen_values' => $frozen === null ? null : json_encode($frozen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pdf_path' => $pdfPath,
            'created_by' => $accounts['created_by'],
            'issued_by' => $accounts['issued_by'],
            'issued_at' => LegacyClock::toApp($row->issued_at),
            'created_at' => $createdAt,
            'updated_at' => LegacyClock::toApp($row->updated_at) ?? $createdAt,
        ];
        if ($existing !== null) {
            DB::table('documents')->where('id', $existing->id)->update($values + ['lock_version' => DB::raw('lock_version + 1')]);
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table('documents')->insertGetId($values);
        }
        $ctx->ids()->put(self::ENTITY, (string) $row->id, $id);

        return [$problems, $state];
    }

    /**
     * @param  string  $legacyPath  intra_documents.pdf_path
     * @return array{0: ?string, 1: string|array{0: string}|null} pdf_path, state (copied|unchanged|notCopied|folder:<missing folder>, [reason] when missing)
     */
    private function pdf(ImportContext $ctx, string $status, string $docid, string $legacyPath, ?string $current, ?string &$written): array
    {
        $target = 'editor/'.$docid.'.pdf';
        if ($status !== Document::ISSUED) {
            return [null, null];
        }
        $kept = $current === $target ? $target : null;
        if ($ctx->uploadsDir() === null) {
            return [$kept, 'notCopied'];
        }
        $name = $docid.'.pdf';
        $legacyPath = trim($legacyPath);
        $folders = self::PDF_FOLDERS;
        if ($legacyPath !== '') {
            // Only the two paths the legacy PdfGenerator::read serves; anything else had no readable PDF there either.
            $folders = array_values(array_filter(self::PDF_FOLDERS, fn (string $f) => $legacyPath === $f.'/'.$name));
            if ($folders === []) {
                return [null, ['pdf_path is not a legacy PDF location']];
            }
        }
        foreach ($folders as $folder) {
            if (! is_dir($ctx->uploadsDir().'/'.$folder)) {
                return [$kept, 'folder:'.$folder];
            }
        }
        $existed = Storage::disk(LegacyPdfCopier::DISK)->exists($target);
        if (! $this->copier->copy($ctx->uploadsDir(), $folders, $name, $target, $ctx->dryRun())) {
            return [null, [(string) $this->copier->error]];
        }
        if ($this->copier->wrote && ! $existed) {
            $written = $target;
        }

        return [$target, $this->copier->changed ? 'copied' : 'unchanged'];
    }

    /**
     * Why the Vue editor would refuse a validated document, or null. Its schema is stricter than the validator:
     * `doc` holds sections only (`docSection+`), containers need content, a list item starts with a paragraph.
     * ponytail: covers the shapes known to differ; the editor's own load check stays the final word.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function editorShapeProblem(array $doc): ?string
    {
        $content = $doc['content'] ?? [];
        if ($content === [] || array_filter($content, fn ($n) => $n['type'] !== 'docSection') !== []) {
            return 'top level is not only sections';
        }

        return self::emptyContainer($content);
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function emptyContainer(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            $children = $node['content'] ?? [];
            if ($children === [] && in_array($node['type'], self::NON_EMPTY, true)) {
                return "empty {$node['type']}";
            }
            if ($node['type'] === 'listItem' && ($children[0]['type'] ?? null) !== 'paragraph') {
                return 'list item without a leading paragraph';
            }
            if ($children !== [] && ($problem = self::emptyContainer($children)) !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** @return ?array<string, string> */
    private static function frozen(mixed $raw): ?array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        $values = json_decode((string) $raw, true);
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new DomainException('frozen_values is not a JSON object');
        }
        foreach ($values as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new DomainException('frozen_values holds a value that is not text');
            }
            $values[$key] = (string) $value;
        }

        return $values;
    }

    /**
     * Counts `*.pdf` files in $folder that are not in $known (file names). Missing folder: 0.
     *
     * @param  list<string>  $known
     */
    public static function orphanFiles(string $folder, array $known): int
    {
        if (! is_dir($folder)) {
            return 0;
        }
        $known = array_flip($known);
        $count = 0;
        foreach (scandir($folder) ?: [] as $file) {
            if (str_ends_with(strtolower($file), '.pdf') && is_file($folder.'/'.$file) && ! isset($known[$file])) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<string> $ids */
    private static function list(array $ids): string
    {
        return implode(', ', array_slice($ids, 0, self::LIST_LIMIT)).(count($ids) > self::LIST_LIMIT ? ', ...' : '');
    }
}

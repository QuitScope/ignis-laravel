<?php

namespace App\Http\Controllers;

use App\Documents\DocumentId;
use App\Documents\DocumentRenderer;
use App\Documents\DocumentValidator;
use App\Documents\InvalidDocument;
use App\Documents\PdfGenerator;
use App\Documents\SectionGuard;
use App\Documents\VariableCatalog;
use App\Events\DocumentIssued;
use App\Http\Requests\CreateDocumentRequest;
use App\Http\Requests\SaveDocumentRequest;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Personnel;
use App\Personnel\PersonnelLogger;
use App\Support\ActorName;
use App\Support\AuditLogger;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Editor documents of a personnel record: create from a template, save the draft, issue it once, delete
 * (legacy EditorDocumentController). Rights come from DocumentPolicy.
 *
 * Request data never goes into Document::create/update as a whole (status, lock_version, frozen_values and
 * pdf_path are fillable): every write lists its columns. Two races are decided in the database:
 * - save: `UPDATE … WHERE lock_version = ? AND status = 'draft'` bumps lock_version; 0 rows = someone saved or
 *   issued in between (409). The bump also makes an unchanged autosave hit its row.
 * - issue: row lock + status check in one transaction with the PDF; the loser sees `issued` and does nothing.
 */
class DocumentController extends Controller
{
    public const JSON_DEPTH = 2 * DocumentValidator::MAX_DEPTH + 8;

    public function store(CreateDocumentRequest $request, Personnel $personnel): RedirectResponse
    {
        $template = DocumentTemplate::where('is_active', true)->findOrFail((int) $request->validated('template_id'));
        $title = $request->validated('title');
        $title = $title !== null && $title !== '' ? (string) $title : $template->name;
        $userId = $request->user()->id;

        $document = DocumentId::create(fn (string $publicId) => Document::create([
            'public_id' => $publicId,
            'template_id' => $template->id,
            'personnel_id' => $personnel->id,
            'title' => $title,
            'content' => $template->content,
            'status' => Document::DRAFT,
            'created_by' => $userId,
        ]));

        $this->audit($request, 'Dokument angelegt', $document);

        return redirect("/documents/{$document->id}/edit");
    }

    public function show(Request $request, Document $document): Response|RedirectResponse
    {
        $this->authorize('document.viewPdf', $document);
        if ($document->status === Document::DRAFT) {
            return redirect("/documents/{$document->id}/edit");
        }

        $actor = $request->user();
        $document->load(['personnel', 'issuer.personnel']);

        return Inertia::render('Documents/Show', [
            'document' => [
                'id' => $document->id,
                'public_id' => $document->public_id,
                'title' => $document->title,
                'issued_at' => $document->issued_at?->toIso8601String(),
                'issuer' => $document->issuer ? ActorName::for($document->issuer) : null,
                'personnel' => ['id' => $document->personnel->id, 'name' => $document->personnel->displayName()],
                'pdf_url' => $document->pdf_path !== null ? "/documents/{$document->id}/pdf" : null,
            ],
            'can' => [
                'delete' => $actor->can('delete', $document),
                'view_personnel' => $actor->can('view', $document->personnel),
            ],
        ]);
    }

    public function edit(Request $request, Document $document): Response|RedirectResponse
    {
        $this->authorize('document.manage');
        if ($document->status === Document::ISSUED) {
            return redirect("/documents/{$document->id}");
        }

        $document->load(['personnel', 'template']);
        $template = $document->template;
        $base = "/documents/{$document->id}";

        return Inertia::render('Documents/Edit', [
            'document' => [
                'id' => $document->id,
                'public_id' => $document->public_id,
                'title' => $document->title,
                'content' => $document->content,
                'lock_version' => $document->lock_version,
                'gender' => $document->personnel->gender,
                'personnel' => ['id' => $document->personnel->id, 'name' => $document->personnel->displayName()],
            ],
            'template' => $template ? ['id' => $template->id, 'name' => $template->name, 'is_active' => $template->is_active] : null,
            'variables' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label],
                array_keys(VariableCatalog::catalog()), VariableCatalog::catalog()),
            'values' => VariableCatalog::resolve($document),
            'missingReasons' => VariableCatalog::missingReasons($document),
            'readOnly' => $template === null || ! $template->is_active,
            'urls' => ['save' => "{$base}/save", 'issue' => "{$base}/issue", 'delete' => $base, 'personnel' => "/personnel/{$document->personnel_id}?tab=documents"],
            'can' => ['delete' => $request->user()->can('delete', $document)],
        ]);
    }

    /** Button and autosave: {lock_version, warnings}; 409 when stale, issued or read-only; 422 for bad content. */
    public function save(SaveDocumentRequest $request, Document $document): JsonResponse
    {
        if ($document->status !== Document::DRAFT) {
            return $this->conflict('issued');
        }
        $template = $document->template;
        if ($template === null || ! $template->is_active) {
            return $this->conflict('read_only');
        }

        $posted = json_decode((string) $request->validated('content'), true, self::JSON_DEPTH);
        if (! is_array($posted)) {
            throw ValidationException::withMessages(['content' => trans('documents.error.invalid')]);
        }

        try {
            // Locked sections come from the current template, whatever the browser sent.
            $guarded = (new SectionGuard)->restoreLockedSections($template->content, $posted);
            $content = DocumentValidator::validate($guarded->document, false);
        } catch (InvalidDocument $e) {
            throw ValidationException::withMessages(['content' => $e->reasons]);
        } catch (InvalidArgumentException $e) {
            Log::warning("Template {$template->id} of document {$document->id} is broken: ".$e->getMessage());
            throw ValidationException::withMessages(['content' => trans('documents.error.template_broken')]);
        }

        $lock = (int) $request->validated('lock_version');
        $title = $request->validated('title');
        $hit = Document::whereKey($document->id)->where('status', Document::DRAFT)->where('lock_version', $lock)->update([
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'lock_version' => DB::raw('lock_version + 1'),
        ] + (is_string($title) && $title !== '' ? ['title' => $title] : []));

        if ($hit === 0) {
            return $this->conflict(Document::whereKey($document->id)->value('status') === Document::DRAFT ? 'stale' : 'issued');
        }

        if (! $request->boolean('autosave')) {
            $this->audit($request, 'Dokument gespeichert', $document->refresh());
        }

        // Warnings are plain text naming sections and fields; the page prints them escaped.
        return response()->json(['lock_version' => $lock + 1, 'warnings' => $guarded->warnings]);
    }

    /**
     * Freezes the variables, writes the PDF and sets `issued`, all under the row lock: a failed PDF rolls the
     * status back, a parallel request waits and then finds `issued`. Event and audit only after the commit.
     */
    public function issue(Request $request, Document $document, PdfGenerator $pdf, PersonnelLogger $log): RedirectResponse|JsonResponse
    {
        $this->authorize('document.manage');
        $user = $request->user();
        $acceptMissing = $request->boolean('accept_missing');
        $leftEmpty = [];
        $written = null;

        try {
            $issued = DB::transaction(function () use ($document, $user, $acceptMissing, $pdf, $log, &$leftEmpty, &$written): ?Document {
                $doc = Document::with('personnel')->lockForUpdate()->find($document->id);
                if ($doc === null || $doc->status !== Document::DRAFT) {
                    return null;
                }

                $values = VariableCatalog::resolve($doc);
                $required = self::emptyRequiredFields($doc, $values);
                if ($required !== []) {
                    throw ValidationException::withMessages(['fields' => trans('documents.error.required_empty', ['fields' => implode(', ', $required)])]);
                }

                $catalog = VariableCatalog::catalog();
                $leftEmpty = array_map(fn (string $name) => $catalog[$name] ?? $name, DocumentRenderer::unresolvedVariables($doc->content, $values));
                if ($leftEmpty !== [] && ! $acceptMissing) {
                    throw ValidationException::withMessages(['variables' => trans('documents.error.unresolved', ['variables' => implode(', ', $leftEmpty)])]);
                }

                $doc->forceFill(['status' => Document::ISSUED, 'frozen_values' => $values, 'issued_by' => $user->id, 'issued_at' => now()]);
                $written = $pdf->generate($doc);
                $doc->forceFill(['pdf_path' => $written])->save();
                $log->documentIssued($doc->personnel, $doc, ActorName::for($user));

                return $doc;
            });
        } catch (Throwable $e) {
            // The path is derived from the public id, so after a lost race the file may be the winner's: only a row
            // that is still a draft (re-read, the transaction is over) owns nothing there.
            if ($written !== null && Document::whereKey($document->id)->value('status') === Document::DRAFT) {
                Storage::disk('documents')->delete($written);
            }
            if ($e instanceof QueryException || $e instanceof DeadlockException) {
                // Lock wait (QueryException) or deadlock (DeadlockException): nothing is wrong with the PDF, the writer can simply retry.
                // Class and code only: a QueryException message carries the bindings (document content).
                Log::warning("Issuing document {$document->public_id} hit a database error", ['exception' => $e::class, 'code' => $e->getCode()]);
                throw ValidationException::withMessages(['issue' => trans('documents.error.try_again')]);
            }
            if (! $e instanceof RuntimeException) {
                throw $e;
            }
            Log::error("Issuing document {$document->public_id} failed", ['exception' => $e::class, 'code' => $e->getCode()]);
            throw ValidationException::withMessages(['issue' => trans('documents.error.pdf_failed')]);
        }

        if ($issued === null) {
            return $request->expectsJson() ? $this->conflict('issued') : redirect("/documents/{$document->id}");
        }

        event(new DocumentIssued($issued));
        $this->audit($request, 'Dokument ausgestellt', $issued, $leftEmpty !== [] ? ', leer gelassen: '.implode(', ', $leftEmpty) : '');

        return redirect("/documents/{$issued->id}");
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($document) {
            $doc = Document::lockForUpdate()->findOrFail($document->id);
            $this->authorize('delete', $doc);
            $doc->delete();

            return $doc;
        });

        // Only the path PdfGenerator writes: a tampered pdf_path never deletes another file.
        if ($deleted->pdf_path === 'editor/'.$deleted->public_id.'.pdf') {
            Storage::disk('documents')->delete($deleted->pdf_path);
        }
        $this->audit($request, 'Dokument gelöscht', $deleted);

        return redirect("/personnel/{$deleted->personnel_id}?tab=documents");
    }

    /**
     * Labels of empty required fields; the renderer reports them as warnings.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private static function emptyRequiredFields(Document $doc, array $values): array
    {
        $prefix = 'Pflichtfeld leer: ';

        return array_values(array_map(fn (string $w) => substr($w, strlen($prefix)), array_filter(
            (new DocumentRenderer)->render($doc->content, $values)->warnings,
            fn (string $w) => str_starts_with($w, $prefix),
        )));
    }

    /** @param 'stale'|'issued'|'read_only' $reason */
    private function conflict(string $reason): JsonResponse
    {
        return response()->json(['message' => trans('documents.error.'.$reason), 'reason' => $reason], 409);
    }

    private function audit(Request $request, string $action, Document $document, string $extra = ''): void
    {
        AuditLogger::log($request->user(), $action, 'Kennung: '.$document->public_id.', Titel: '.$document->title.$extra,
            'Dokumente', true, ['document_id' => $document->id, 'personnel_id' => $document->personnel_id]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documents from before the editor (legacy PersonnelController::showDocument/deleteDocument and
 * Api\PersonnelDocumentController): viewer page, JSON for the profile modal, archive switch, delete.
 * Rows are looked up by their public id (`docid`).
 *
 * Who may open one: `personnel.viewDoc` holders, the recipient (the account linked to the row's record)
 * and the issuer. "Own document" in the legacy system meant the issuer, and stays so (`issuer_user_id`).
 * The PDF is the file `legacy/<pdf_path>`, delivered by StorageFileController::legacyPdf; rows whose name does
 * not fit the public pattern (7 digits) have no download, the data sheet is all there is.
 */
class LegacyDocumentController extends Controller
{
    public function show(Request $request): Response
    {
        $document = $this->find($this->text($request->query('docid')));
        $actor = $request->user();
        $recipient = $actor->personnel_id !== null && (int) $actor->personnel_id === (int) $document->personnel_id;
        abort_unless($recipient || $this->issuedBy($actor, $document) || $actor->can('viewDoc', Personnel::class), 403);

        return Inertia::render('Documents/LegacyShow', [
            'document' => [
                'public_id' => $document->public_id,
                'type' => $document->type,
                'type_label' => $document->typeLabel(),
                'recipient' => $document->recipient_name ?? $document->personnel?->full_name,
                'issuer' => $document->issuerLabel(),
                'issued_on' => $document->issued_on?->toDateString(),
                'is_archived' => $document->is_archived,
                'pdf_url' => $this->pdfUrl($document),
            ],
            'can' => [
                'manage' => $actor->can('manageDocs', Personnel::class),
                'view_personnel' => $document->personnel !== null && $actor->can('view', $document->personnel),
                'personnel_id' => $document->personnel_id,
            ],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $docid = $this->text($request->query('docid'));
        if ($docid === '') {
            return response()->json(['success' => false, 'error' => 'docid ist erforderlich'], 400);
        }
        $document = LegacyDocument::with(['personnel', 'issuerUser.personnel'])->where('public_id', $docid)->first();
        if ($document === null) {
            return response()->json(['success' => false, 'error' => 'Dokument nicht gefunden'], 404);
        }
        if (! $this->issuedBy($request->user(), $document) && ! $request->user()->can('document.view')) {
            return response()->json(['success' => false, 'error' => 'Keine Berechtigung'], 403);
        }

        $url = $this->pdfUrl($document);

        return response()->json(['success' => true, 'document' => [
            'id' => $document->id,
            'docid' => $document->public_id,
            'type' => $document->type,
            'type_label' => $document->typeLabel(),
            'erhalter' => $document->recipient_name,
            'empfaenger_fullname' => $document->personnel?->full_name,
            'ersteller_name' => $document->issuerLabel(),
            'ausstellungsdatum' => $document->issued_on?->toDateString(),
            'ausstellungsdatum_formatted' => $document->issued_on?->format('d.m.Y') ?? '',
            'timestamp' => $document->created_at?->toDateTimeString(),
            'is_archived' => $document->is_archived,
            'pdf_url' => $url,
            'pdf_exists' => $url !== null,
            'profileid' => $document->personnel_id,
        ]]);
    }

    public function archive(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('document.manage'), 403);
        $docid = $this->text($request->input('docid'));
        if ($docid === '') {
            return response()->json(['success' => false, 'error' => 'docid ist erforderlich'], 400);
        }
        $archived = $request->boolean('archived', true);

        $document = LegacyDocument::where('public_id', $docid)->first();
        if ($document === null) {
            return response()->json(['success' => false, 'error' => 'Dokument nicht gefunden'], 404);
        }
        $document->update(['is_archived' => $archived]);
        AuditLogger::log($request->user(), 'Dokument '.($archived ? 'archiviert' : 'wiederhergestellt')." [ID: {$docid}]", null, 'Mitarbeiter', true);

        return response()->json(['success' => true, 'archived' => $archived]);
    }

    /** Row and PDF go; the audit `details` carry the record id of the row (the posted `pid` is not trusted). */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manageDocs', Personnel::class), 403);
        $data = $request->validate(['docid' => ['required', 'string', 'max:15'], 'pid' => ['nullable', 'string', 'max:20']]);
        $document = $this->find($data['docid']);

        DB::transaction(fn () => $document->delete());
        // After the commit; basename() so a bad row can only ever point into legacy/.
        if ($document->pdf_path !== null && $document->pdf_path !== '') {
            Storage::disk('documents')->delete('legacy/'.basename($document->pdf_path));
        }
        AuditLogger::log($request->user(), "Dokument gelöscht [ID: {$document->public_id}]", $document->personnel_id !== null ? (string) $document->personnel_id : null, 'Mitarbeiter', true);

        return redirect($document->personnel_id !== null ? "/personnel/{$document->personnel_id}?tab=documents" : '/');
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function find(string $docid): LegacyDocument
    {
        abort_if($docid === '', 404);

        return LegacyDocument::with(['personnel', 'issuerUser.personnel'])->where('public_id', $docid)->firstOrFail();
    }

    private function issuedBy(User $user, LegacyDocument $document): bool
    {
        return $document->issuer_user_id !== null && (int) $document->issuer_user_id === $user->id;
    }

    private function pdfUrl(LegacyDocument $document): ?string
    {
        $name = (string) $document->pdf_path;

        return preg_match(LegacyDocument::PUBLIC_PDF_PATTERN, $name) === 1 && Storage::disk('documents')->fileExists('legacy/'.$name)
            ? '/storage/documents/'.$name : null;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /documents/{document}/pdf`: the stored editor PDF, signed-in users with the `document.viewPdf` right
 * only. The file is the one `PdfGenerator` wrote for this document (`editor/{public_id}.pdf`); any other
 * `pdf_path` is treated as missing, so a bad row can never point the response at another file.
 */
final class DocumentPdfController extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        // The single authorisation point (DocumentPolicy::open: viewers and the recipient).
        Gate::authorize('document.viewPdf', $document);

        $disk = Storage::disk('documents');
        abort_unless($document->status === Document::ISSUED && $document->pdf_path === 'editor/'.$document->public_id.'.pdf' && $disk->fileExists($document->pdf_path), 404);

        return $disk->response($document->pdf_path, null, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}

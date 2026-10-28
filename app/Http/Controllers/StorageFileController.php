<?php

namespace App\Http\Controllers;

use App\Models\LegacyDocument;
use App\Models\Personnel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `GET /storage/{area}/{file}` (legacy StorageFileController, same URLs): uploaded files from private disks,
 * signed-in users only. The area is a fixed list (and the disk name); the file must match the area's strict
 * name pattern, so `..`, slashes, encoded variants and foreign extensions never reach the disk. The
 * Content-Type comes from the extension table, nosniff keeps browsers from guessing another one.
 *
 * One exception: `GET /storage/documents/{file}` is public for imported legacy PDFs, because the
 * old links were printed and sent around. {@see self::legacyPdf()} serves only a name of the strict
 * `XXXX-XXXX-XXXX.pdf` form that has a `legacy_documents` row and a file under `legacy/`. Editor PDFs, 7-digit
 * names and everything else are 404, signed in or not.
 */
final class StorageFileController extends Controller
{
    /** @var array<string, string> area => file-name pattern */
    private const AREAS = ['profile-pictures' => Personnel::PICTURE_PATTERN];

    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __invoke(string $area, string $file): BinaryFileResponse
    {
        $pattern = self::AREAS[$area] ?? null;
        abort_unless($pattern !== null && preg_match($pattern, $file) === 1, 404);
        $type = self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? null;
        $disk = Storage::disk($area);
        abort_unless($type !== null && $disk->fileExists($file), 404);

        // Not response()->file(): BinaryFileResponse defaults to `public`, these are per-user files.
        return new BinaryFileResponse($disk->path($file), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], public: false);
    }

    /** Registered without the `auth` middleware (and without a session, so no cookie lands in a shared cache). */
    public function legacyPdf(string $file): BinaryFileResponse
    {
        $disk = Storage::disk('documents');
        abort_unless(
            preg_match(LegacyDocument::PUBLIC_PDF_PATTERN, $file) === 1
                // The name is `<public_id>.pdf`; public_id is unique and indexed, pdf_path must still match exactly.
                && LegacyDocument::where('public_id', substr($file, 0, -4))->where('pdf_path', $file)->exists()
                && $disk->fileExists('legacy/'.$file),
            404,
        );

        return new BinaryFileResponse($disk->path('legacy/'.$file), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ], public: true);
    }
}

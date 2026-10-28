<?php

namespace App\Http\Controllers;

use App\Branding\LogoUpload;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `GET /branding/{file}`: the logo, public (the login page needs it). Registered without session, so no cookie
 * lands in a shared cache. Only the CURRENT `system.logo` is served: a replaced or leaked name is 404.
 */
final class BrandingController extends Controller
{
    public function __invoke(string $file, LogoUpload $logo): BinaryFileResponse
    {
        $disk = Storage::disk(LogoUpload::DISK);
        abort_unless(preg_match(LogoUpload::PATTERN, $file) === 1 && $logo->current() === $file && $disk->fileExists($file), 404);

        return new BinaryFileResponse($disk->path($file), 200, [
            'Content-Type' => LogoUpload::MIME[pathinfo($file, PATHINFO_EXTENSION)],
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ], public: true);
    }
}

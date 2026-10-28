<?php

namespace Modules\KnowledgeBase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveUser;
use App\Support\ImageUpload;
use App\Support\Settings;
use App\Support\UploadRejected;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\KnowledgeBase\Models\KbEntry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Images of the knowledge base editor (legacy POST /api/knowledgebase/images, same file URLs /storage/kb-images/…).
 * Upload: kb.edit, ImageUpload's content checks, random name. Delivery: only names of the strict pattern from the
 * private `kb-images` disk; public while `knowledgebase.public_access` is on, else signed-in accounts only.
 */
final class KbImageController extends Controller
{
    public const DISK = 'kb-images';

    public const PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/D';

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    private const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    /** `POST /lexicon/images` (multipart `image`): `{src, alt: ''}` for the editor's image node. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('edit', KbEntry::class);
        $file = $request->file('image');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['image' => trans('upload.missing')]);
        }

        try {
            $name = ImageUpload::store($file, self::DISK, self::MAX_BYTES, self::TYPES);
        } catch (UploadRejected $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        return response()->json(['src' => '/storage/kb-images/'.$name, 'alt' => ''], 201);
    }

    /**
     * `GET /storage/kb-images/{file}`. The route runs without session (like BrandingController), so a public answer
     * never carries a Set-Cookie into a shared cache. With the switch off, session, active-account check and `auth`
     * run here, so guests get the login redirect (401 for JSON) as on every other auth route, before the disk is asked.
     */
    public function show(Request $request, string $file, Settings $settings): Response
    {
        abort_unless(preg_match(self::PATTERN, $file) === 1, 404);

        if ($settings->get('knowledgebase.public_access') === true) {
            return $this->file($file, true);
        }

        return (new Pipeline(app()))->send($request)
            ->through([StartSession::class, EnsureActiveUser::class, Authenticate::class])
            ->then(fn () => $this->file($file, false));
    }

    private function file(string $file, bool $public): BinaryFileResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->fileExists($file), 404);

        return new BinaryFileResponse($disk->path($file), 200, [
            'Content-Type' => self::MIME[pathinfo($file, PATHINFO_EXTENSION)],
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => ($public ? 'public' : 'private').', max-age=3600',
        ], public: $public);
    }
}

<?php

namespace App\Support;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Checks an uploaded image and stores it under a random name (legacy src/Support/FileUpload.php, stricter).
 * The client's name and MIME type are never used: finfo and getimagesize must agree on an allowed type, the
 * declared size must stay under MAX_PIXELS, and GD must actually decode the pixels. The extension comes from
 * the detected type. SVG never passes (finfo says image/svg+xml, which no caller allows).
 */
final class ImageUpload
{
    /**
     * Decompression-bomb guard, checked from the header before decoding. A small file can declare a huge
     * canvas; GD needs about 4-5 bytes per pixel, so 4096x4096 (16.7 MP, ~80 MB) is the most we decode
     * under a 256 MB memory_limit. Phone photos (12 MP) fit, 24 MP camera shots do not.
     */
    public const MAX_PIXELS = 4096 * 4096;

    /**
     * @param  array<string, string>  $mimeToExt  detected MIME type => stored extension
     * @return string the stored file name, `bin2hex(random_bytes(16)).ext`
     *
     * @throws UploadRejected
     */
    public static function store(UploadedFile $file, string $disk, int $maxBytes, array $mimeToExt): string
    {
        $path = $file->getRealPath();
        if (! $file->isValid() || $path === false) {
            throw new UploadRejected(trans('upload.failed'));
        }
        $name = bin2hex(random_bytes(16)).'.'.self::check($path, $maxBytes, $mimeToExt);
        if (Storage::disk($disk)->put($name, self::clean($path, $maxBytes)) === false) {
            throw new UploadRejected(trans('upload.failed'));
        }

        return $name;
    }

    /**
     * Size, type and pixel checks of a local file (also used by the legacy import).
     *
     * @param  array<string, string>  $mimeToExt  detected MIME type => stored extension
     * @return string the extension for the detected type
     *
     * @throws UploadRejected
     */
    public static function check(string $path, int $maxBytes, array $mimeToExt): string
    {
        if ((int) @filesize($path) > $maxBytes) {
            throw new UploadRejected(trans('upload.too_large', ['max' => self::readable($maxBytes)]));
        }

        $mime = @(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! isset($mimeToExt[$mime])) {
            throw new UploadRejected(trans('upload.invalid_type', ['types' => implode(', ', array_unique($mimeToExt))]));
        }

        $size = @getimagesize($path);
        if ($size === false || $size['mime'] !== $mime || $size[0] < 1 || $size[1] < 1) {
            throw new UploadRejected(trans('upload.broken'));
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new UploadRejected(trans('upload.too_many_pixels', ['side' => (int) sqrt(self::MAX_PIXELS)]));
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new UploadRejected(trans('upload.broken'));
        }
        imagedestroy($image);

        return $mimeToExt[$mime];
    }

    /**
     * The bytes to store for a file that passed check(): re-encoded (see reencode), GIF unchanged. Also used by the
     * legacy import, so imported pictures lose their metadata too.
     *
     * @throws UploadRejected
     */
    public static function clean(string $path, int $maxBytes): string
    {
        $bytes = self::reencode($path) ?? file_get_contents($path);
        if ($bytes === false) {
            throw new UploadRejected(trans('upload.failed'));
        }
        if (strlen($bytes) > $maxBytes) {
            throw new UploadRejected(trans('upload.too_large', ['max' => self::readable($maxBytes)]));
        }

        return $bytes;
    }

    private static function reencode(string $path): ?string
    {
        $type = (@getimagesize($path) ?: [])[2] ?? null;
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new UploadRejected(trans('upload.broken'));
        }
        // ponytail: rotations only; mirrored orientations (2, 4, 5, 7) are rare from phones and stay unmirrored.
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $angle = [3 => 180, 6 => -90, 8 => 90][(int) ((@exif_read_data($path) ?: [])['Orientation'] ?? 1)] ?? 0;
            $image = $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
        }
        imagesavealpha($image, true);
        ob_start();
        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, 90),
            IMAGETYPE_PNG => imagepng($image),
            IMAGETYPE_WEBP => imagewebp($image, null, 90),
        };
        $bytes = (string) ob_get_clean();
        if (! $ok || $bytes === '') {
            throw new UploadRejected(trans('upload.failed'));
        }

        return $bytes;
    }

    private static function readable(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}

<?php

namespace App\Import;

use App\Models\Personnel;
use App\Support\ImageUpload;
use App\Support\UploadRejected;
use Illuminate\Support\Facades\Storage;

/**
 * Copies one legacy profile picture (`<uploads>/storage/profile-pictures/<name>`) to the `profile-pictures`
 * disk under a new random name. The legacy name is never reused: only `^[A-Za-z0-9._-]+$` without `..`,
 * the resolved real path (symlinks followed) must stay inside the pictures folder, and the file must pass
 * the same size, type and pixel checks as an upload (ImageUpload::check). What is written are the re-encoded bytes
 * (ImageUpload::clean, metadata dropped), not the file itself. A legacy `.jpeg` becomes `.jpg`.
 */
final class ProfilePictureCopier
{
    public const DISK = 'profile-pictures';

    public ?string $error = null;

    /**
     * @param  string  $legacyName  bare legacy file name (no path)
     * @param  ?string  $existing  current picture of the record; kept (no copy) when its content is identical
     * @return ?string the new file name (on a dry run the name it would get; nothing is written), or null
     */
    public function copy(string $uploadsDir, string $legacyName, bool $dryRun, ?string $existing = null): ?string
    {
        $this->error = null;
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $legacyName) !== 1 || str_contains($legacyName, '..')) {
            return $this->fail('unsafe file name');
        }
        $base = realpath($uploadsDir.'/storage/profile-pictures');
        $path = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$legacyName);
        if ($base === false || $path === false || ! is_file($path)) {
            return $this->fail('file missing');
        }
        if (! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return $this->fail('file outside the pictures folder');
        }
        try {
            $ext = ImageUpload::check($path, Personnel::PICTURE_MAX_BYTES, Personnel::PICTURE_TYPES);
            $bytes = ImageUpload::clean($path, Personnel::PICTURE_MAX_BYTES);
        } catch (UploadRejected $e) {
            return $this->fail($e->getMessage());
        }

        $disk = Storage::disk(self::DISK);
        if ($existing !== null && preg_match(Personnel::PICTURE_PATTERN, $existing) === 1 && $disk->exists($existing)
            && hash('sha256', (string) $disk->get($existing)) === hash('sha256', $bytes)) {
            return $existing;
        }
        $name = bin2hex(random_bytes(16)).'.'.$ext;
        if ($dryRun) {
            return $name;
        }

        return $disk->put($name, $bytes) ? $name : $this->fail('copy failed');
    }

    private function fail(string $reason): null
    {
        $this->error = $reason;

        return null;
    }
}

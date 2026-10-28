<?php

namespace App\Import;

use Illuminate\Support\Facades\Storage;

/**
 * Copies one legacy document PDF from below the legacy installation (`--uploads`) to the `documents` disk.
 * Same guards as ProfilePictureCopier: only names of a legacy document id (`1234567.pdf`, `ABCD-EFGH-JKLM.pdf`),
 * the resolved real path (symlinks followed) must stay inside the folder it was looked up in, at most
 * MAX_BYTES and the file must start with the PDF magic `%PDF-`. An identical target is left alone, so a
 * second run copies nothing.
 */
final class LegacyPdfCopier
{
    public const DISK = 'documents';

    public const MAX_BYTES = 20 * 1024 * 1024;

    public const NAME_PATTERN = '/^(?:[0-9]{7}|[A-Z2-9]{4}(?:-[A-Z2-9]{4}){2})\.pdf$/D';

    public ?string $error = null;

    public bool $wrote = false;

    public bool $changed = false;

    /**
     * @param  list<string>  $folders  folders below $uploadsDir to look in; the first one holding the file wins
     * @param  string  $name  bare legacy file name (no path)
     * @param  string  $target  path on the documents disk
     * @return bool true when $target holds the file afterwards (on a dry run: would hold it; nothing is written)
     */
    public function copy(string $uploadsDir, array $folders, string $name, string $target, bool $dryRun): bool
    {
        $this->error = null;
        $this->wrote = false;
        $this->changed = false;
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return $this->fail('unsafe file name');
        }
        $path = null;
        foreach ($folders as $folder) {
            $base = realpath($uploadsDir.'/'.$folder);
            $candidate = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$name);
            if ($base !== false && $candidate !== false && is_file($candidate)) {
                if (! str_starts_with($candidate, $base.DIRECTORY_SEPARATOR)) {
                    return $this->fail('file outside the documents folder');
                }
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
            return $this->fail('file missing');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            return $this->fail('larger than '.(self::MAX_BYTES / 1024 / 1024).' MB');
        }
        if (@file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
            return $this->fail('not a PDF');
        }

        $disk = Storage::disk(self::DISK);
        if ($disk->exists($target) && hash('sha256', (string) $disk->get($target)) === hash_file('sha256', $path)) {
            return true;
        }
        $this->changed = true;
        if ($dryRun) {
            return true;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return $this->fail('file not readable');
        }
        try {
            $this->wrote = $disk->writeStream($target, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->wrote ?: $this->fail('copy failed');
    }

    private function fail(string $reason): false
    {
        $this->error = $reason;

        return false;
    }
}

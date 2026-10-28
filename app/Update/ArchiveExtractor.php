<?php

namespace App\Update;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Unpacks a release archive into a fresh staging directory. Every entry is checked before anything is written:
 * no `..`, absolute paths, drive letters, backslashes or symlinks; at most $maxEntries entries, $maxFileBytes per file and
 * $maxTotalBytes in all (counted while streaming, so a lying header cannot get past). The archive is either flat or has
 * one root directory `ignis-<version>/`. Only the code entries the swapper uses are written (FlatSwapper::CODE); `.env`
 * or `.git` in an archive is refused, `storage/` and anything else is ignored, so an archive can never bring those in.
 * VERSION must name the expected version; artisan, vendor/ and public/build must exist. bootstrap/cache is created
 * (the trial migration runs artisan from here).
 */
final class ArchiveExtractor
{
    public function __construct(
        private int $maxEntries = 20_000,
        private int $maxTotalBytes = 600 * 1024 * 1024,
        private int $maxFileBytes = 100 * 1024 * 1024,
    ) {}

    public function extract(string $zipPath, string $destDir, string $expectedVersion): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw self::invalid('not a readable zip');
        }
        $created = false;
        try {
            $entries = $this->entries($zip, $expectedVersion);
            if (file_exists($destDir) && (! is_dir($destDir) || (scandir($destDir) ?: []) !== ['.', '..'])) {
                throw self::invalid('staging directory not empty');
            }
            if (! is_dir($destDir)) {
                if (! @mkdir($destDir, 0775, true)) {
                    throw self::invalid('staging directory cannot be created');
                }
                $created = true;
            }
            $this->write($zip, $entries, $destDir);
            $version = trim((string) @file_get_contents("$destDir/VERSION"));
            if ($version !== $expectedVersion) {
                throw self::invalid('VERSION does not match the manifest');
            }
            foreach (['artisan' => 'is_file', 'vendor' => 'is_dir', 'public/build' => 'is_dir'] as $rel => $check) {
                if (! $check("$destDir/$rel")) {
                    throw self::invalid("$rel missing");
                }
            }
            if (! is_dir("$destDir/bootstrap/cache") && ! @mkdir("$destDir/bootstrap/cache", 0775, true)) {
                throw self::invalid('bootstrap/cache cannot be created');
            }
        } catch (Throwable $e) {
            if ($created) {
                (new Filesystem)->deleteDirectory($destDir);
            }
            throw $e instanceof UpdateException ? $e : self::invalid('cannot be unpacked');
        } finally {
            $zip->close();
        }
    }

    /**
     * Checked entry list: index => relative target path ('' = skipped); a trailing '/' marks a directory.
     *
     * @return array<int, string>
     */
    private function entries(ZipArchive $zip, string $version): array
    {
        if ($zip->numFiles > $this->maxEntries) {
            throw self::invalid('too many entries');
        }
        $names = [];
        $declared = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i) ?: throw self::invalid('unreadable entry');
            $name = (string) $stat['name'];
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name)) {
                throw self::invalid('unsafe path');
            }
            $parts = explode('/', rtrim($name, '/'));
            if (in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true)) {
                throw self::invalid('unsafe path');
            }
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw self::invalid('symlink');
            }
            if ((int) $stat['size'] > $this->maxFileBytes) {
                throw self::invalid('file too large');
            }
            $declared += (int) $stat['size'];
            if ($declared > $this->maxTotalBytes) {
                throw self::invalid('archive too large');
            }
            $names[$i] = $name;
        }

        $root = "ignis-$version/";
        $prefixed = $names !== [] && array_filter($names, fn (string $n) => ! str_starts_with($n, $root) && $n !== rtrim($root, '/')) === [];
        $out = [];
        foreach ($names as $i => $name) {
            $rel = $prefixed ? substr($name, strlen($root)) : $name;
            $top = explode('/', $rel)[0];
            if (in_array($top, ['.env', '.git'], true)) {
                throw self::invalid("$top in archive");
            }
            $out[$i] = $rel !== '' && in_array($top, FlatSwapper::CODE, true) ? $rel : '';
        }

        return $out;
    }

    /** @param array<int, string> $entries */
    private function write(ZipArchive $zip, array $entries, string $dest): void
    {
        $total = 0;
        foreach ($entries as $i => $rel) {
            if ($rel === '') {
                continue;
            }
            $target = "$dest/".rtrim($rel, '/');
            if (str_ends_with($rel, '/')) {
                if (! is_dir($target) && ! @mkdir($target, 0775, true)) {
                    throw new RuntimeException("$rel cannot be created");
                }

                continue;
            }
            if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0775, true)) {
                throw new RuntimeException("$rel cannot be created");
            }
            $in = $zip->getStreamIndex($i);
            $out = @fopen($target, 'xb');
            if ($in === false || $out === false) {
                throw new RuntimeException("$rel cannot be written");
            }
            try {
                $size = 0;
                while (! feof($in)) {
                    $chunk = (string) fread($in, 1024 * 1024);
                    $size += strlen($chunk);
                    $total += strlen($chunk);
                    if ($size > $this->maxFileBytes) {
                        throw self::invalid('file too large');
                    }
                    if ($total > $this->maxTotalBytes) {
                        throw self::invalid('archive too large');
                    }
                    if (fwrite($out, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException("$rel cannot be written");
                    }
                }
            } finally {
                fclose($in);
                fclose($out);
            }
        }
    }

    private static function invalid(string $detail): UpdateException
    {
        return new UpdateException('archive_invalid', ['detail' => $detail]);
    }
}

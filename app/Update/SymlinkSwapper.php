<?php

namespace App\Update;

use App\Update\Backup\BackupManager;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Weg A, release layout: <root>/current is a symlink to <root>/releases/<version>; .env and storage of a release are
 * symlinks into <root>/shared. The release moves to releases/<version>, gets the same shared links as the current one,
 * and `current` is switched with one rename of a fresh link over it (atomic on POSIX; on Windows the old link is
 * removed first). Journal releases/.swap.json until commit(); revert() points `current` back and removes the new release.
 */
final class SymlinkSwapper implements Swapper
{
    public ?UpdateLog $log = null;

    private string|false|null $journal = null;

    /** @param string $current the `current` symlink (the app path) */
    public function __construct(private string $current) {}

    public function swap(string $stagingDir): void
    {
        $this->journal = false;
        if ($this->pending() !== []) {
            throw $this->fail('an earlier swap is unfinished (run ignis:update:recover)');
        }
        $previous = (string) readlink($this->current);
        $version = trim((string) @file_get_contents("$stagingDir/VERSION"));
        if (! InstalledVersion::isValid($version)) {
            throw $this->fail('staged release has no valid VERSION');
        }
        $new = $this->releases()."/$version";
        if (realpath($new) !== false && realpath($new) === realpath($this->current)) {
            throw $this->fail("$new is the current release");
        }
        $links = [];
        foreach (['.env', 'storage'] as $shared) {
            if (file_exists("$this->current/$shared") && ! is_link("$this->current/$shared")) {
                throw $this->fail("$shared of the current release is not a link into shared/");
            }
        }
        foreach (scandir($this->current) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_link("$this->current/$name") && ! file_exists("$stagingDir/$name")) {
                $links[$name] = (string) readlink("$this->current/$name");
            }
        }
        if (is_link("$this->current/public/storage") && ! file_exists("$stagingDir/public/storage")) {
            $links['public/storage'] = (string) readlink("$this->current/public/storage");
        }
        if (is_dir($new)) {
            (new Filesystem)->deleteDirectory($new);
        }
        try {
            UpdateState::writeAtomic($this->journalPath(), (string) json_encode(['previous' => $previous, 'new' => $new], JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            throw $this->fail('journal cannot be written');
        }
        $this->journal = $this->journalPath();
        if (! BackupManager::retry(fn () => @rename($stagingDir, $new))) {
            throw $this->fail("release cannot be moved to $new");
        }
        foreach ($links as $rel => $target) {
            if (! @symlink($target, "$new/$rel")) {
                throw $this->fail("link $rel cannot be created");
            }
        }
        $this->point($new);
        $this->log?->write("current now points to $new");
    }

    public function revert(): void
    {
        $journal = $this->journal === null ? ($this->pending()[0] ?? null) : ($this->journal ?: null);
        if ($journal === null || ! is_file($journal)) {
            return;
        }
        $data = json_decode((string) @file_get_contents($journal), true);
        if (! is_array($data) || ! is_string($data['previous'] ?? null) || ! is_string($data['new'] ?? null)) {
            throw $this->fail('journal unreadable');
        }
        if ((string) @readlink($this->current) !== $data['previous']) {
            $this->point($data['previous']);
        }
        if (is_dir($data['new']) && realpath($data['new']) !== realpath($this->current)) {
            (new Filesystem)->deleteDirectory($data['new']);
        }
        if (! @unlink($journal)) {
            throw $this->fail('journal cannot be removed');
        }
        $this->journal = null;
        $this->log?->write('current points back to '.$data['previous']);
    }

    public function commit(): void
    {
        // ponytail: older releases stay in releases/; prune them when disk space matters
        if (is_string($this->journal) && is_file($this->journal) && ! @unlink($this->journal)) {
            throw $this->fail('journal cannot be removed');
        }
        $this->journal = null;
    }

    public function discard(): void
    {
        if (is_file($this->journalPath()) && ! @unlink($this->journalPath())) {
            throw $this->fail('journal cannot be removed');
        }
        $this->journal = null;
    }

    /**
     * Refuses a layout the switch would break: a database file inside the release directory would stay behind in
     * the old release (it belongs into shared/, see docs/dev/update-recovery.md).
     *
     * @param  list<string>  $databaseFiles
     */
    public function check(array $databaseFiles): void
    {
        $release = realpath($this->current);
        foreach ($databaseFiles as $file) {
            $real = realpath(dirname($file));
            if ($release !== false && $real !== false && is_file($file)
                && str_starts_with(str_replace('\\', '/', $real).'/', str_replace('\\', '/', $release).'/')) {
                throw new UpdateException('release_layout_db');
            }
        }
    }

    /** @return list<string> */
    public function pending(): array
    {
        return is_file($this->journalPath()) ? [$this->journalPath()] : [];
    }

    private function point(string $target): void
    {
        $tmp = dirname($this->current).'/.current-'.bin2hex(random_bytes(4));
        if (! @symlink($target, $tmp)) {
            throw $this->fail('link cannot be created');
        }
        if (! @rename($tmp, $this->current)) {
            if (! (@unlink($this->current) || @rmdir($this->current)) || ! @rename($tmp, $this->current)) {
                @unlink($tmp);
                throw $this->fail('current cannot be switched');
            }
        }
    }

    private function releases(): string
    {
        return dirname($this->current).'/releases';
    }

    private function journalPath(): string
    {
        return $this->releases().'/.swap.json';
    }

    private function fail(string $detail): UpdateException
    {
        $this->log?->write('swap failed: '.$detail);

        return new UpdateException('swap_failed');
    }
}

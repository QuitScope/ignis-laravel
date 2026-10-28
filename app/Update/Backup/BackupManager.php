<?php

namespace App\Update\Backup;

use App\Update\Installation;
use App\Update\UpdateException;
use App\Update\UpdateLog;
use App\Update\UpdateState;
use Closure;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backups before an update: <backup_path>/<UTC time>-<from>-to-<to>/ with the database dump, the code as code.zip
 * (app_path without storage/, node_modules/, .git/, .update-old/, the SQLite file and the backups themselves),
 * a copy of .env (file env) and manifest.json with a SHA-256 per artifact. Owner-only rights. Uploads are never
 * copied: the update does not touch storage/.
 *
 * A restore runs in phases with their own failure reasons, so the caller knows what state is left:
 * checks and unpacking the code into a staging dir (`backup_checksum`, `disk_space`, `restore_prepare_failed`:
 * nothing changed), database (`restore_db_failed`: nothing changed; `restore_db_partial`: database may be empty),
 * code and .env (`restore_code_failed`: database already restored, code partly moved).
 */
final class BackupManager
{
    public const RESERVE_BYTES = 100 * 1024 * 1024;

    public const EXCLUDE = ['storage', 'node_modules', '.git', '.update-old', '.env'];

    public const ABORTED_AFTER_SECONDS = 3600;

    private const STAGE_PREFIX = '.update-restore-';

    private DatabaseDumper $dumper;

    /** @var Closure(string): (float|int|false) */
    private Closure $freeSpace;

    /** @param  (Closure(string): (float|int|false))|null  $freeSpace  free bytes of a directory (tests inject it) */
    public function __construct(?DatabaseDumper $dumper = null, ?Closure $freeSpace = null, private ?UpdateLog $log = null)
    {
        $this->dumper = $dumper ?? DatabaseDumper::for();
        $this->dumper->log ??= $log;
        $this->freeSpace = $freeSpace ?? fn (string $dir) => @disk_free_space($dir);
    }

    public function create(string $fromVersion, string $toVersion): BackupSet
    {
        foreach ([$fromVersion, $toVersion] as $v) {
            if (! preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/D', $v)) {
                throw new UpdateException('bad_version');
            }
        }
        self::requireZip();
        if (($reason = $this->dumper->available()) !== null) {
            throw new UpdateException('dump_unavailable', ['reason' => $reason]);
        }
        $base = $this->basePath();
        if (! is_dir($base) && ! @mkdir($base, 0775, true) && ! is_dir($base)) {
            throw new UpdateException('backup_failed');
        }

        $app = $this->appPath();
        $tree = $this->tree($app);
        $estimate = $this->dumper->sizeBytes() + (int) @filesize("$app/.env") + array_sum(array_map(fn (string $f) => (int) @filesize($f), $tree['files']));
        $this->requireSpace($base, 2 * $estimate);

        $created = now()->utc();
        $name = $base.'/'.$created->format('Ymd-His').'-'.$fromVersion.'-to-'.$toVersion;
        for ($i = 1, $dir = $name; ! @mkdir($dir, 0700); $dir = $name.'-'.++$i) {
            if ($i >= 9) {
                throw new UpdateException('backup_failed');
            }
        }
        try {
            @chmod($dir, 0700);
            $db = $this->dumper->fileName();
            $this->dumper->dump("$dir/$db");
            $this->zip($tree, "$dir/code.zip");
            $hasEnv = is_file("$app/.env");
            if ($hasEnv && ! @copy("$app/.env", "$dir/env")) {
                throw new RuntimeException('.env could not be copied');
            }
            $files = [];
            $size = 0;
            foreach ([$db, 'code.zip', 'env'] as $name) {
                if (is_file("$dir/$name")) {
                    @chmod("$dir/$name", 0600);
                    $files[$name] = (string) hash_file('sha256', "$dir/$name");
                    $size += (int) filesize("$dir/$name");
                }
            }
            // No secrets here: names, versions, checksums and link targets only.
            $manifest = [
                'format' => BackupSet::FORMAT,
                'created_at' => $created->toIso8601ZuluString(),
                'from' => $fromVersion,
                'to' => $toVersion,
                'database' => $db,
                'env' => $hasEnv,
                'size_bytes' => $size,
                'files' => $files,
                'links' => $tree['links'],
            ];
            if (@file_put_contents("$dir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('manifest.json could not be written');
            }
            @chmod("$dir/manifest.json", 0600);
        } catch (Throwable $e) {
            (new Filesystem)->deleteDirectory($dir);
            $this->log?->write('backup failed: '.$e->getMessage());
            throw $e instanceof UpdateException ? $e : new UpdateException('backup_failed');
        }

        return BackupSet::fromDir($dir) ?? throw new UpdateException('backup_failed');
    }

    /**
     * Database, code and .env back as they were in the backup (phases: see the class comment).
     *
     * @param  bool  $code  false when the code is already back (the swapper reverted it): database and .env only
     */
    public function restore(BackupSet $set, bool $code = true): void
    {
        self::requireZip();
        $m = $this->verify($set);
        $db = $this->dumper->fileName();
        if (($reason = $this->dumper->available(forRestore: true)) !== null) {
            throw new UpdateException('dump_unavailable', ['reason' => $reason]);
        }
        $app = $this->appPath();
        $zip = new ZipArchive;
        if ($zip->open("$set->path/code.zip", ZipArchive::RDONLY) !== true) {
            throw new UpdateException('restore_prepare_failed');
        }
        $stage = $app.'/'.self::STAGE_PREFIX.bin2hex(random_bytes(4));
        try {
            // Step 1: nothing changes yet.
            $unpacked = 0;
            for ($i = 0; $code && $i < $zip->numFiles; $i++) {
                $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            $this->requireSpace($app, $unpacked + (int) filesize("$set->path/$db"));
            $entries = [];
            try {
                if ($code) {
                    $entries = $this->unpack($zip, $stage);
                }
            } catch (Throwable $e) {
                $this->log?->write('restore (prepare) failed: '.$e->getMessage());
                throw new UpdateException('restore_prepare_failed');
            }

            // Step 2: database.
            try {
                $this->dumper->restore("$set->path/$db");
            } catch (UpdateException $e) {
                $this->log?->write("restore (database) failed: {$e->reason}");
                throw $e->reason === 'restore_db_partial' ? $e : new UpdateException('restore_db_failed');
            } catch (Throwable $e) {
                $this->log?->write('restore (database) failed: '.$e->getMessage());
                throw new UpdateException('restore_db_failed');
            }

            // Step 3: code and .env.
            try {
                if ($code) {
                    $this->moveCode($app, $stage, $entries, $m['links']);
                }
                if ($m['env']) {
                    $env = is_link("$app/.env") ? (realpath("$app/.env") ?: "$app/.env") : "$app/.env";
                    $mode = @fileperms($env);
                    UpdateState::writeAtomic($env, (string) file_get_contents("$set->path/env"));
                    if ($mode !== false) {
                        @chmod($env, $mode & 0777);
                    }
                }
            } catch (Throwable $e) {
                $this->log?->write('restore (code) failed: '.$e->getMessage());
                throw new UpdateException('restore_code_failed', ['path' => $set->path]);
            }
        } finally {
            $zip->close();
            (new Filesystem)->deleteDirectory($stage);
        }
    }

    /** @return list<BackupSet> newest first */
    public function list(): array
    {
        $sets = [];
        foreach (glob($this->basePath().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (($set = BackupSet::fromDir($dir)) !== null) {
                $sets[] = $set;
            }
        }
        usort($sets, fn (BackupSet $a, BackupSet $b) => [$b->createdAt->getTimestamp(), $b->path] <=> [$a->createdAt->getTimestamp(), $a->path]);

        return $sets;
    }

    public function prune(?BackupSet $active = null): void
    {
        $keep = $active === null ? null : self::norm($active->path);
        $doomed = array_map(fn (BackupSet $s) => $s->path, array_slice($this->list(), max(1, (int) config('ignis.update.keep_backups', 3))));
        foreach (glob($this->basePath().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (BackupSet::readManifest($dir) === null && (int) @filemtime($dir) < time() - self::ABORTED_AFTER_SECONDS) {
                $doomed[] = $dir;
            }
        }
        foreach ($doomed as $dir) {
            if (self::norm($dir) !== $keep) {
                (new Filesystem)->deleteDirectory($dir);
            }
        }
    }

    public static function retry(Closure $op, int $attempts = 5): bool
    {
        for ($i = 1; ; $i++) {
            if ($op()) {
                return true;
            }
            if ($i >= $attempts) {
                return false;
            }
            usleep(500_000);
        }
    }

    private static function requireZip(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new UpdateException('zip_missing');
        }
    }

    private function requireSpace(string $dir, int $bytes): void
    {
        $need = $bytes + self::RESERVE_BYTES;
        $free = ($this->freeSpace)($dir);
        if ($free === false || $free < $need) {
            throw new UpdateException('disk_space', ['need' => (int) ceil($need / 1048576), 'free' => (int) floor(max(0, (float) $free) / 1048576)]);
        }
    }

    /**
     * Manifest complete, every checksum right, link entries harmless; before anything changes.
     *
     * @return array{env: bool, links: array<string, string>}
     */
    private function verify(BackupSet $set): array
    {
        $m = BackupSet::readManifest($set->path) ?? throw new UpdateException('backup_checksum');
        BackupSet::requireFormat($m);
        $db = $this->dumper->fileName();
        if (($m['database'] ?? null) !== $db) {
            throw new UpdateException('backup_driver');
        }
        $env = ($m['env'] ?? true) !== false;
        if (! isset($m['files'][$db], $m['files']['code.zip']) || isset($m['files']['env']) !== $env) {
            throw new UpdateException('backup_checksum');
        }
        foreach ($m['files'] as $name => $sha) {
            $file = "$set->path/$name";
            if (! in_array($name, [$db, 'code.zip', 'env'], true) || ! is_file($file) || ! hash_equals((string) $sha, (string) hash_file('sha256', $file))) {
                throw new UpdateException('backup_checksum');
            }
        }
        $links = [];
        foreach ((array) ($m['links'] ?? []) as $rel => $target) {
            if (! self::safeRelative((string) $rel)) {
                throw new UpdateException('backup_checksum');
            }
            $links[(string) $rel] = (string) $target;
        }

        return ['env' => $env, 'links' => $links];
    }

    /** No absolute paths, drive letters, backslashes or ".." segments. */
    private static function safeRelative(string $rel): bool
    {
        return $rel !== '' && ! str_starts_with($rel, '/') && ! str_contains($rel, '\\') && ! str_contains($rel, ':')
            && ! in_array('..', explode('/', $rel), true) && ! in_array('', explode('/', $rel), true);
    }

    private function basePath(): string
    {
        return rtrim((string) config('ignis.update.backup_path'), '/\\');
    }

    private function appPath(): string
    {
        return Installation::appPath();
    }

    /**
     * The code part of the app directory. Symlinks and junctions (public/storage) are recorded, never followed.
     *
     * @return array{files: array<string, string>, dirs: array<string, string>, links: array<string, string>} relative path => absolute path (links: => target)
     */
    private function tree(string $app): array
    {
        $real = realpath($app) ?: $app;
        $skip = [];
        foreach ([$this->basePath(), ...$this->dumper->localFiles()] as $p) {
            $abs = self::norm((realpath(dirname($p)) ?: dirname($p)).'/'.basename($p));
            if (str_starts_with($abs, self::norm($real).'/')) {
                $skip[] = substr($abs, strlen(self::norm($real)) + 1);
            }
        }
        $out = ['files' => [], 'dirs' => [], 'links' => []];
        $this->walk($app, $real, '', $skip, $out);

        return $out;
    }

    /**
     * @param  list<string>  $skip  normalised relative paths
     * @param  array{files: array<string, string>, dirs: array<string, string>, links: array<string, string>}  $out
     */
    private function walk(string $dir, string $realDir, string $prefix, array $skip, array &$out): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..'
                || ($prefix === '' && (in_array($name, self::EXCLUDE, true) || str_starts_with($name, self::STAGE_PREFIX)))) {
                continue;
            }
            $rel = $prefix.$name;
            $abs = "$dir/$name";
            if (in_array(self::norm($rel), $skip, true)) {
                continue;
            }
            if (self::isLink($abs, $realDir.'/'.$name)) {
                $out['links'][$rel] = (string) @readlink($abs);
            } elseif (is_dir($abs)) {
                $out['dirs'][$rel] = $abs;
                $this->walk($abs, (string) realpath($abs), "$rel/", $skip, $out);
            } else {
                $out['files'][$rel] = $abs;
            }
        }
    }

    /** A symlink, or a directory that resolves elsewhere (Windows junction). */
    private static function isLink(string $abs, string $expectedReal): bool
    {
        if (is_link($abs)) {
            return true;
        }
        if (! is_dir($abs)) {
            return false;
        }
        $real = realpath($abs);

        return $real === false || self::norm($real) !== self::norm($expectedReal);
    }

    private static function removeLink(string $abs): bool
    {
        return @unlink($abs) || @rmdir($abs);
    }

    /** @param  array{files: array<string, string>, dirs: array<string, string>, links: array<string, string>}  $tree */
    private function zip(array $tree, string $target): void
    {
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('code.zip could not be created');
        }
        foreach ($tree['dirs'] as $rel => $abs) {
            $zip->addEmptyDir($rel);
            $zip->setExternalAttributesName("$rel/", ZipArchive::OPSYS_UNIX, ((int) @fileperms($abs) & 0xFFFF) << 16);
        }
        foreach ($tree['files'] as $rel => $abs) {
            if (! $zip->addFile($abs, $rel)) {
                throw new RuntimeException("$rel could not be added to code.zip");
            }
            $zip->setExternalAttributesName($rel, ZipArchive::OPSYS_UNIX, ((int) @fileperms($abs) & 0xFFFF) << 16);
        }
        if (! $zip->close()) {
            throw new RuntimeException('code.zip could not be written');
        }
    }

    /**
     * Unpacks code.zip into the staging dir and checks every entry arrived with its size.
     *
     * @return list<array{rel: string, dir: bool, mode: int}>
     */
    private function unpack(ZipArchive $zip, string $stage): array
    {
        if (! @mkdir($stage, 0700) || ! $zip->extractTo($stage)) {
            throw new RuntimeException('code.zip could not be unpacked');
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            $dir = str_ends_with($name, '/');
            $rel = rtrim($name, '/');
            if (! self::safeRelative($rel)) {
                throw new RuntimeException('unsafe entry in code.zip');
            }
            $ok = $dir ? is_dir("$stage/$rel") : (is_file("$stage/$rel") && filesize("$stage/$rel") === (int) ($stat['size'] ?? -1));
            if (! $ok) {
                throw new RuntimeException("$rel missing after unpacking");
            }
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            $entries[] = ['rel' => $rel, 'dir' => $dir, 'mode' => $opsys === ZipArchive::OPSYS_UNIX ? ($attr >> 16) & 07777 : 0];
        }

        return $entries;
    }

    /**
     * Moves the staged code file by file over the current code (never a moment without a file). A link on the way
     * to any backed-up path is removed first, so nothing is written outside the app directory; afterwards whatever
     * the newer version added (files, dirs, links) goes and missing links from the backup come back.
     *
     * @param  list<array{rel: string, dir: bool, mode: int}>  $entries
     * @param  array<string, string>  $links
     */
    private function moveCode(string $app, string $stage, array $entries, array $links): void
    {
        $real = realpath($app) ?: $app;
        $checked = [];
        $keep = [];
        foreach ($entries as ['rel' => $rel, 'dir' => $dir, 'mode' => $mode]) {
            $keep[$rel] = true;
            $path = '';
            foreach (explode('/', $rel) as $part) {
                $path = ltrim("$path/$part", '/');
                if (! isset($checked[$path])) {
                    $checked[$path] = true;
                    if (self::isLink("$app/$path", "$real/$path") && ! self::removeLink("$app/$path")) {
                        throw new RuntimeException("link $path could not be removed");
                    }
                }
            }
            $target = "$app/$rel";
            if ($dir) {
                if (is_file($target)) {
                    @unlink($target);
                }
                if (! is_dir($target) && ! @mkdir($target, 0777, true)) {
                    throw new RuntimeException("$rel could not be created");
                }
            } else {
                if (is_dir($target)) {
                    (new Filesystem)->deleteDirectory($target);
                }
                if (! self::retry(fn () => @rename("$stage/$rel", $target))) {
                    throw new RuntimeException("$rel could not be replaced");
                }
            }
            if (PHP_OS_FAMILY !== 'Windows' && $mode !== 0) {
                @chmod($target, $mode);
            }
        }

        $now = $this->tree($app);
        foreach ($now['files'] as $rel => $abs) {
            if (! isset($keep[$rel]) && ! self::retry(fn () => @unlink($abs))) {
                throw new RuntimeException("$rel could not be removed");
            }
        }
        foreach ($now['links'] as $rel => $target) {
            if ((! isset($links[$rel]) || $links[$rel] !== $target) && ! self::removeLink("$app/$rel")) {
                throw new RuntimeException("link $rel could not be removed");
            }
        }
        foreach (array_reverse($now['dirs'], true) as $rel => $abs) {
            if (! isset($keep[$rel])) {
                @rmdir($abs);
            }
        }
        foreach ($links as $rel => $target) {
            if ($target !== '' && ! file_exists("$app/$rel") && ! is_link("$app/$rel")) {
                @symlink($target, "$app/$rel");
            }
        }
    }

    private static function norm(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}

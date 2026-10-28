<?php

namespace App\Update;

use App\Update\Backup\BackupManager;
use App\Update\Backup\DatabaseDumper;
use Closure;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Weg A, flat installation: the code entries (CODE) of the app directory move to .update-old/<old version>/, the ones
 * of the release take their place. .env, storage/, .git and everything else stay where they are; files that live inside
 * a code directory but belong to the installation (the SQLite database and its journals, public/storage) are carried
 * over into the new directory. Every rename is retried for Windows file locks (5 x 500 ms).
 *
 * Journal: .update-old/<version>/swap.json is written before the first rename and removed by commit(). revert() works
 * from the journal and the files it finds (not from the progress of this process), so it can finish or undo a swap
 * that crashed half-way, also in a new process, and running it twice is harmless.
 */
final class FlatSwapper implements Swapper
{
    public const CODE = ['app', 'bootstrap', 'config', 'database', 'docs', 'lang', 'modules', 'public', 'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock', 'VERSION'];

    public const OLD = '.update-old';

    public const KEEP = ['public/storage', 'public/.well-known', 'public/.user.ini', 'public/web.config'];

    public ?UpdateLog $log = null;

    /**
     * Where the database and .env backups are, for the standalone revert.php (keys db_type, db_file, db_backup,
     * database, env, env_backup); set by the Updater before swap().
     *
     * @var array<string, string>
     */
    public array $recovery = [];

    public ?Closure $rename = null;

    private string|false|null $journal = null;

    /** @param  list<string>|null  $keep  absolute paths carried over into the new code; null = SQLite files + KEEP */
    public function __construct(private ?string $app = null, private ?array $keep = null) {}

    public function swap(string $stagingDir): void
    {
        $app = $this->app();
        $this->journal = false;
        if ($this->pending() !== []) {
            throw $this->fail('an earlier swap is unfinished (run ignis:update:recover)');
        }
        $version = InstalledVersion::current($app);
        $old = "$app/".self::OLD.'/'.(preg_match('/^[0-9A-Za-z.+-]{1,64}$/D', $version) ? $version : 'previous');
        if (is_dir($old)) {
            (new Filesystem)->deleteDirectory($old);
        }
        if (! @mkdir($old, 0700, true)) {
            throw $this->fail("$old cannot be created");
        }

        $items = [];
        foreach (self::CODE as $c) {
            $items[$c] = self::exists("$app/$c");
        }
        $keep = $this->keepList($app);
        require_once __DIR__.'/revert.php';
        try {
            if (! @copy(__DIR__.'/revert.php', "$old/revert.php")) {
                throw new RuntimeException('revert.php');
            }
            $journal = ['app' => $app, 'items' => $items, 'keep' => $keep, 'recovery' => $this->recovery];
            UpdateState::writeAtomic("$old/swap.json", (string) json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            throw $this->fail('journal cannot be written');
        }
        $this->journal = "$old/swap.json";
        $this->log?->write('swap: old code goes to '.$old);

        foreach ($items as $c => $existed) {
            if ($existed) {
                $this->move("$app/$c", "$old/$c");
            }
        }
        foreach (self::CODE as $c) {
            if (self::exists("$stagingDir/$c")) {
                $this->move("$stagingDir/$c", "$app/$c");
            }
        }
        foreach ($keep as $rel) {
            if (self::exists("$old/$rel")) {
                if (self::exists("$app/$rel")) {
                    $this->move("$app/$rel", "$old/.shadow/$rel");
                }
                $this->move("$old/$rel", "$app/$rel");
            }
        }
    }

    public function revert(): void
    {
        $journals = $this->journal === null ? $this->pending() : ($this->journal === false ? [] : [$this->journal]);
        foreach ($journals as $journal) {
            if (is_file($journal)) {
                $this->revertOne($journal);
            }
        }
    }

    public function commit(): void
    {
        if (is_string($this->journal) && is_file($this->journal)) {
            if (! BackupManager::retry(fn () => @unlink((string) $this->journal))) {
                throw $this->fail('journal cannot be removed');
            }
            if (! (new Filesystem)->deleteDirectory(dirname($this->journal))) {
                $this->log?->write('old code could not be removed completely: '.dirname($this->journal));
            }
        }
        $this->journal = null;
    }

    public function discard(): void
    {
        foreach ($this->pending() as $journal) {
            if (! BackupManager::retry(fn () => @unlink($journal))) {
                throw $this->fail('journal cannot be removed');
            }
            if (! (new Filesystem)->deleteDirectory(dirname($journal))) {
                $this->log?->write('discard: '.dirname($journal).' could not be removed completely');
            }
        }
        $this->journal = null;
    }

    /** @return list<string> journals of swaps that were neither committed nor reverted */
    public function pending(): array
    {
        return glob($this->app().'/'.self::OLD.'/*/swap.json') ?: [];
    }

    private function revertOne(string $journal): void
    {
        if (! function_exists('ignis_update_revert')) {
            require is_file(dirname($journal).'/revert.php') ? dirname($journal).'/revert.php' : __DIR__.'/revert.php';
        }
        $rename = $this->rename ?? fn (string $a, string $b): bool => @rename($a, $b);
        try {
            ignis_update_revert($journal, $this->app(), $rename, fn (string $line) => $this->log?->write($line));
        } catch (RuntimeException $e) {
            throw $this->fail($e->getMessage());
        }
        if ($this->journal === $journal) {
            $this->journal = null;
        }
    }

    /** @return list<string> relative paths inside code directories that belong to the installation */
    private function keepList(string $app): array
    {
        $paths = $this->keep;
        if ($paths === null) {
            $paths = array_map(fn (string $rel) => "$app/$rel", self::KEEP);
            try {
                $paths = [...$paths, ...DatabaseDumper::for()->localFiles()];
            } catch (Throwable) {
            }
        }
        $base = self::norm(realpath($app) ?: $app).'/';
        $out = [];
        foreach ($paths as $p) {
            $abs = rtrim(str_replace('\\', '/', (realpath(dirname($p)) ?: dirname($p)).'/'.basename($p)), '/');
            $rel = str_starts_with(self::norm($abs), $base) ? substr($abs, strlen($base)) : '';
            if (str_contains($rel, '/') && in_array(explode('/', $rel)[0], self::CODE, true) && self::exists("$app/$rel")) {
                $out[] = $rel;
            }
        }

        return array_values(array_unique($out));
    }

    private function move(string $from, string $to): void
    {
        $dir = dirname($to);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw $this->fail("$dir cannot be created");
        }
        $rename = $this->rename ?? fn (string $a, string $b): bool => @rename($a, $b);
        if (! BackupManager::retry(fn () => $rename($from, $to))) {
            throw $this->fail("$from cannot be moved to $to (locked?)");
        }
    }

    private function fail(string $detail): UpdateException
    {
        $this->log?->write('swap failed: '.$detail);

        return new UpdateException('swap_failed');
    }

    private function app(): string
    {
        return $this->app ?? Installation::appPath();
    }

    private static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private static function norm(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}

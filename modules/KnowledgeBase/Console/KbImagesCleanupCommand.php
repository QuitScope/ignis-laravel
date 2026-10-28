<?php

namespace Modules\KnowledgeBase\Console;

use App\Modules\ModuleCommand;
use DirectoryIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Http\Controllers\KbImageController;
use Throwable;

/**
 * Daily (provider schedule()). Deletes uploaded editor images older than 24 h (grace for entries still being written)
 * whose file name occurs in no entry's content or sections; archived entries count as references. Only names the
 * upload generates are candidates, everything else and symlinks stay. A failing reference lookup deletes nothing.
 */
class KbImagesCleanupCommand extends ModuleCommand
{
    private const GRACE_SECONDS = 86400;

    private const NAME = '[a-f0-9]{32}\.(?:jpg|png|webp|gif)';

    protected $signature = 'kb:images:cleanup {--dry-run : List unreferenced images, delete nothing}';

    protected $description = 'Delete knowledge base images no entry uses (older than 24 h)';

    public function module(): string
    {
        return 'knowledgebase';
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $root = realpath(Storage::disk(KbImageController::DISK)->path(''));
        $candidates = $root === false ? [] : $this->candidates($root);
        $found = 0;
        $deleted = 0;

        // Read after listing: a file saved meanwhile is young anyway. A failing lookup must never look like
        // "nothing is referenced" (it also runs without candidates, so a broken table is reported).
        try {
            $used = $this->referenced();
        } catch (Throwable $e) {
            $this->error('Reference lookup failed, nothing deleted ('.$e->getMessage().')');

            return self::FAILURE;
        }
        foreach (array_diff_key($candidates, $used) as $name => $path) {
            $found++;
            $this->line((string) $name);
            clearstatcache(true, $path);
            if (! $dry && is_file($path) && ! is_link($path) && filemtime($path) <= time() - self::GRACE_SECONDS && @unlink($path)) {
                $deleted++;
            }
        }

        $this->info($dry ? "$found unreferenced image(s) found, none deleted (dry run)." : "$found unreferenced image(s) found, $deleted deleted.");

        return self::SUCCESS;
    }

    /** @return array<string, string> name => path of old regular files with a generated name */
    private function candidates(string $root): array
    {
        $out = [];
        foreach (new DirectoryIterator($root) as $file) {
            if ($file->isDot() || $file->isLink() || ! $file->isFile() || preg_match('/^'.self::NAME.'$/D', $file->getFilename()) !== 1
                || $file->getMTime() > time() - self::GRACE_SECONDS) {
                continue;
            }
            $out[$file->getFilename()] = $file->getPathname();
        }

        return $out;
    }

    /** @return array<string, true> every generated image name in any entry's content or sections, archived ones included */
    private function referenced(): array
    {
        $used = [];
        DB::table('kb_entries')->select(['id', 'content', 'sections'])->orderBy('id')->chunkById(100, function ($rows) use (&$used) {
            foreach ($rows as $row) {
                preg_match_all('/'.self::NAME.'/', $row->content.' '.$row->sections, $m);
                $used += array_fill_keys($m[0], true);
            }
        });

        return $used;
    }
}

<?php

namespace App\Console\Commands;

use App\Branding\LogoUpload;
use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Weekly (routes/console.php). Deletes files on the app's OWN disks that no database row points to and that are
 * older than 24 h (grace for uploads still being saved). Only files whose relative path matches the pattern of the
 * names the app generates are considered, so foreign files stay. No user-supplied paths; symlinks (files and
 * directories) are never followed or deleted.
 */
class StorageCleanupCommand extends Command
{
    private const GRACE_SECONDS = 86400;

    protected $signature = 'ignis:storage:cleanup {--dry-run : List orphaned files, delete nothing}';

    protected $description = 'Delete orphaned document PDFs, logos, profile pictures and temp files (older than 24 h)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $found = 0;
        $deleted = 0;
        $failed = false;

        foreach ($this->stores() as $disk => [$pattern, $referenced]) {
            $root = realpath(Storage::disk($disk)->path(''));
            $candidates = $root === false ? [] : $this->candidates($root, $pattern);
            if ($candidates === []) {
                continue;
            }
            // Read after listing: a file saved meanwhile is young anyway, and a row written meanwhile is seen.
            // A failing lookup must never look like "nothing is referenced": skip this disk, delete nothing.
            try {
                $keep = array_flip($referenced());
            } catch (Throwable $e) {
                $failed = true;
                $this->error("$disk: reference lookup failed, nothing deleted (".$e->getMessage().')');

                continue;
            }
            foreach (array_diff_key($candidates, $keep) as $rel => $path) {
                $found++;
                $this->line("$disk: $rel");
                clearstatcache(true, $path);
                if (! $dry && is_file($path) && ! is_link($path) && filemtime($path) <= time() - self::GRACE_SECONDS && @unlink($path)) {
                    $deleted++;
                }
            }
        }

        $this->info($dry ? "$found orphaned file(s) found, none deleted (dry run)." : "$found orphaned file(s) found, $deleted deleted.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, array{string, \Closure(): list<string>}> disk => [relative-path pattern, referenced paths] */
    private function stores(): array
    {
        $image = '#^[a-f0-9]{32}\.(png|jpg|webp)$#D';

        return [
            'documents' => [
                '#^(editor/(\d{7}\.pdf|\.\d{7}\.[a-f0-9]{12}\.tmp)|legacy/(\d{7}|[A-Z2-9]{4}(-[A-Z2-9]{4}){2})\.pdf)$#D',
                fn () => [
                    ...Document::whereNotNull('pdf_path')->pluck('pdf_path')->all(),
                    ...LegacyDocument::whereNotNull('pdf_path')->pluck('pdf_path')->map(fn ($n) => 'legacy/'.$n)->all(),
                ],
            ],
            LogoUpload::DISK => [$image, fn () => array_filter([$this->logoName()])],
            'profile-pictures' => [$image, fn () => Personnel::whereNotNull('profile_picture')->pluck('profile_picture')->all()],
        ];
    }

    /** Straight from the DB: Settings swallows errors and would report "no logo", i.e. everything orphaned. */
    private function logoName(): ?string
    {
        $value = json_decode((string) Setting::where('key', LogoUpload::KEY)->value('value'), true);

        return is_string($value) && preg_match(LogoUpload::PATTERN, $value) === 1 ? $value : null;
    }

    /** @return array<string, string> relative path => absolute path of matching regular files older than the grace period */
    private function candidates(string $root, string $pattern): array
    {
        $out = [];
        // No FOLLOW_SYMLINKS: symlinked directories are not entered.
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            if ($file->isLink() || ! $file->isFile() || preg_match($pattern, $rel) !== 1
                || $file->getMTime() > time() - self::GRACE_SECONDS) {
                continue;
            }
            $out[$rel] = $path;
        }

        return $out;
    }
}

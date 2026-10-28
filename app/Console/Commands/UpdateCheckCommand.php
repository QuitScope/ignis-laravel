<?php

namespace App\Console\Commands;

use App\Support\Settings;
use App\System\Redact;
use App\Update\InstalledVersion;
use App\Update\ReleaseSource;
use App\Update\UpdateException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily in the scheduler, only with updates.check_enabled (routes/console.php); by hand it always runs. Asks GitHub for
 * the latest release and verifies its signed manifest (nothing is downloaded beyond manifest and signature). The
 * result goes into updates.latest_version|release_url|checked_at|last_error; this command is their only writer.
 * On failure the last known release stays and last_error holds our own message, never the foreign answer.
 */
class UpdateCheckCommand extends Command
{
    protected $signature = 'ignis:update:check';

    protected $description = 'Check GitHub for a newer signed ignis release';

    public function handle(Settings $settings, ReleaseSource $source): int
    {
        $settings->set('updates.checked_at', now()->utc()->toIso8601ZuluString());
        try {
            $release = $source->latest();
            if ($release === null) {
                $this->line('No release published yet.');
            } else {
                try {
                    $source->fetchVerifiedManifest($release);
                    $this->info("Version {$release->version} is available.");
                } catch (UpdateException $e) {
                    if (! in_array($e->reason, ['not_newer', 'dev_version'], true)) {
                        throw $e;
                    }
                    $this->info($e->reason === 'not_newer' ? "Up to date (latest release {$release->version})."
                        : "Latest release {$release->version}; the installed version is a development build.");
                }
                $installed = InstalledVersion::current();
                if (InstalledVersion::isValid($installed) && InstalledVersion::compare($release->version, $installed) < 0) {
                    $settings->set('updates.latest_version', $installed);
                    $settings->set('updates.release_url', '');
                } else {
                    $settings->set('updates.latest_version', $release->version);
                    $settings->set('updates.release_url', $release->htmlUrl);
                }
            }
            $settings->set('updates.last_error', '');

            return self::SUCCESS;
        } catch (UpdateException $e) {
            $message = $e->getMessage();
        } catch (Throwable $e) {
            Log::warning('Update check failed: '.$e::class);
            $message = __('update.error.unexpected');
        }
        $message = Redact::summary($message);
        $settings->set('updates.last_error', $message);
        $this->error($message);

        return self::FAILURE;
    }
}

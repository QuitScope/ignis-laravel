<?php

namespace App\Import;

use App\Support\Settings;

final class ImportContext
{
    public function __construct(
        private readonly LegacyReader $legacy,
        private readonly bool $dryRun,
        private readonly IdMap $ids,
        private readonly ImportReport $report,
        private readonly Settings $settings,
        private readonly ?string $uploadsDir = null,
        private readonly bool $importApiKey = false,
        private readonly bool $overwriteSettings = false,
    ) {}

    public function importApiKey(): bool
    {
        return $this->importApiKey;
    }

    public function overwriteSettings(): bool
    {
        return $this->overwriteSettings;
    }

    /**
     * Writes a setting unless the new instance already stores another value ("the first one wins"; the settings form
     * never stores a default, so a stored value is the operator's) and --overwrite-settings is not given.
     *
     * @return 'written'|'unchanged'|'kept'
     */
    public function fillSetting(string $key, mixed $value, bool $encrypted = false): string
    {
        if ($this->settings->has($key)) {
            if ($this->settings->get($key) === $value) {
                return 'unchanged';
            }
            if (! $this->overwriteSettings) {
                return 'kept';
            }
        }
        $this->settings->set($key, $value, $encrypted);

        return 'written';
    }

    public function uploadsDir(): ?string
    {
        return $this->uploadsDir;
    }

    public function legacy(): LegacyReader
    {
        return $this->legacy;
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    public function ids(): IdMap
    {
        return $this->ids;
    }

    public function report(): ImportReport
    {
        return $this->report;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }
}

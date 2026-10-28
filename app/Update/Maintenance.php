<?php

namespace App\Update;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** Laravel's maintenance mode (file in storage/framework). The secret is never stored or returned. */
class Maintenance
{
    public function enable(): void
    {
        if (! $this->isActive()) {
            $code = Artisan::call('down', ['--retry' => 60, '--secret' => bin2hex(random_bytes(16))]);
            if ($code !== 0 || ! $this->isActive()) {
                throw new RuntimeException('maintenance mode could not be enabled');
            }
        }
    }

    public function disable(): void
    {
        if ($this->isActive()) {
            $code = Artisan::call('up');
            if ($code !== 0 || $this->isActive()) {
                throw new RuntimeException('maintenance mode could not be disabled');
            }
        }
    }

    /** @phpstan-impure */
    public function isActive(): bool
    {
        return app()->isDownForMaintenance();
    }
}

<?php

namespace App\Install;

use App\Models\Setting;
use App\Support\Settings;
use Throwable;

/** Not final: Feature tests replace it with a mock that reports "installed" (tests/Pest.php). */
class InstallState
{
    public const KEY = 'installed_at';

    public function __construct(private readonly Settings $settings) {}

    public function isInstalled(): bool
    {
        try {
            return $this->settings->has(self::KEY);
        } catch (Throwable) {
            return false;
        }
    }

    public function markInstalled(): void
    {
        $now = now();
        $claimed = Setting::query()->insertOrIgnore([
            'key' => self::KEY,
            'value' => json_encode($now->toIso8601String(), JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->settings->refresh();

        if ($claimed === 0) {
            throw new AlreadyInstalled;
        }
    }
}

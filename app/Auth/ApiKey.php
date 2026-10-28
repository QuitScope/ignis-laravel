<?php

namespace App\Auth;

use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * The machine key ef_bridge (FiveM server) uses against /api. Stored encrypted in Settings `api.key`.
 * Only current()/regenerate() create a key; reads (isConfigured/matches) never do, so an
 * unconfigured install stays closed (503) instead of silently minting a key.
 */
final class ApiKey
{
    private const SETTING = 'api.key';

    public function __construct(private readonly Settings $settings) {}

    public function current(): string
    {
        return $this->stored() ?? $this->regenerate();
    }

    public function regenerate(): string
    {
        $key = Str::random(48);
        $this->settings->set(self::SETTING, $key, encrypted: true);

        return $key;
    }

    public function isConfigured(): bool
    {
        return $this->stored() !== null;
    }

    public function matches(?string $provided): bool
    {
        $stored = $this->stored();

        return $stored !== null && $provided !== null && $provided !== '' && hash_equals($stored, $provided);
    }

    public function stored(): ?string
    {
        $value = $this->settings->get(self::SETTING);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

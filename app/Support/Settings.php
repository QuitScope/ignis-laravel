<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Settings
{
    private const CACHE_KEY = 'settings.all';

    private bool $warnedUnavailable = false;

    public function get(string $key, mixed $default = null): mixed
    {
        $default ??= app(SettingDefinitions::class)->definition($key)?->default;
        $all = $this->all();

        if ($all === null || ! array_key_exists($key, $all)) {
            return $default;
        }

        [$value, $encrypted] = $all[$key];

        if (! $encrypted) {
            return json_decode((string) $value, true) ?? $default;
        }

        try {
            return json_decode(Crypt::decryptString((string) $value), true) ?? $default;
        } catch (Throwable $e) {
            Log::warning("Setting [{$key}] could not be decrypted: ".$e->getMessage());

            return null;
        }
    }

    /** $value is #[SensitiveParameter]: a secret must not reach a logged stack trace. */
    public function set(string $key, #[\SensitiveParameter] mixed $value, bool $encrypted = false): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);

        Setting::updateOrCreate(['key' => $key], [
            'value' => $encrypted ? Crypt::encryptString($json) : $json,
            'is_encrypted' => $encrypted,
        ]);
        Cache::memo()->forget(self::CACHE_KEY);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all() ?? []);
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        Cache::memo()->forget(self::CACHE_KEY);
    }

    public function refresh(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{0: ?string, 1: bool}>|null null when storage is unavailable
     */
    private function all(): ?array
    {
        try {
            return Cache::memo()->rememberForever(self::CACHE_KEY, fn () => Setting::all()
                ->mapWithKeys(fn (Setting $s) => [$s->key => [$s->value, $s->is_encrypted]])
                ->all());
        } catch (Throwable $e) {
            if (! $this->warnedUnavailable) {
                $this->warnedUnavailable = true;
                Log::warning('Settings unavailable, using defaults: '.$e->getMessage());
            }

            return null;
        }
    }
}

<?php

namespace App\Import;

use App\Branding\LogoUpload;
use App\Support\ImageUpload;
use App\Support\SettingDefinitions;
use App\Support\UploadRejected;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * intra_config => settings by LegacyConfigMap (the keys of AuthSettingsImporter excepted). Fill-if-missing through
 * ImportContext::fillSetting; values pass the SettingDefinitions rules like a form would. Each row counts once as
 * written (also when the stored value already equals it), skipped or failed. Values never reach the report.
 */
final class SystemSettingsImporter implements Importer
{
    private const ENTITY = 'system_settings';

    private const LOGO = '~^/storage/branding/([0-9a-f]{32}\.(?:png|jpe?g|webp))$~D';

    private const DEFAULT_LOGOS = ['ignis-wordmark.svg', 'ignis-lockup.svg', 'ignis-mark.svg', 'defaultLogo.webp', 'defaultLogo.png'];

    private const DM_TYPES_SEED = 'mail,system';

    /** @var array<string, list<string>> outcome => legacy keys, for the summary lines */
    private array $seen = [];

    /** @var array<string, ?string> the legacy rows of this run */
    private array $config = [];

    public function __construct(private readonly SettingDefinitions $definitions, private readonly LogoUpload $logo) {}

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        if (! $ctx->legacy()->tableExists('intra_config')) {
            $report->note(self::ENTITY.': legacy table intra_config missing, skipped.');

            return;
        }
        $this->seen = [];

        /** @var array<string, ?string> $config */
        $config = $ctx->legacy()->table('intra_config')->orderBy('config_key')->pluck('config_value', 'config_key')->all();
        $config = array_filter($config, fn ($key) => (LegacyConfigMap::KEYS[$key][1] ?? null) !== 'auth', ARRAY_FILTER_USE_KEY);
        $report->read(self::ENTITY, count($config));
        $this->config = $config;
        foreach ($config as $key => $value) {
            $key = (string) $key;
            $entry = LegacyConfigMap::KEYS[$key] ?? null;
            try {
                $outcome = $entry === null ? 'unknown' : $this->row($ctx, $key, $entry, $value === null ? null : (string) $value);
            } catch (Throwable $e) {
                $report->failed(self::ENTITY, "{$key}: ".$e::class); // no message: it could carry the value

                continue;
            }
            $this->seen[$outcome][] = $key;
            in_array($outcome, ['written', 'unchanged'], true) ? $report->written(self::ENTITY) : $report->skippedCount(self::ENTITY, 1);
        }
        $this->summarise($ctx);
    }

    /**
     * @param  array{0: ?string, 1: string, 2: string}  $entry
     * @return string 'written'|'unchanged'|'kept' or why it was not written
     */
    private function row(ImportContext $ctx, string $key, array $entry, ?string $value): string
    {
        [$target, $transform, $note] = $entry;
        $report = $ctx->report();
        $set = ! LegacyConfigMap::isPlaceholder($value);

        switch ($transform) {
            case 'drop':
            case 'privacy':
                $report->note(self::ENTITY.": {$key} nicht übernommen ({$note}).");
                if ($key === 'SYSTEM_URL' && $set && self::site((string) $value) !== self::site((string) config('app.url'))) {
                    $report->warn('SYSTEM_URL des Altsystems weicht von APP_URL ab; Einladungs- und Discord-Links nutzen APP_URL.');
                }

                return 'dropped';
            case 'later':
                return 'later';
            case 'discord_bot':
                if ($key === 'DISCORD_BOT_DM_TYPES' && trim((string) $value) === self::DM_TYPES_SEED && ! $this->botActive()) {
                    return 'not_set';
                }

                return $set && LegacyConfigMap::bool($value) !== false ? $transform : 'not_set';
            case 'webhook':
                return $set ? $transform : 'not_set';
            case 'bool':
                $bool = LegacyConfigMap::bool($value);
                if ($bool === null) {
                    $report->warn("{$key}: kein Ja/Nein-Wert, nicht übernommen ({$target} bleibt).");

                    return 'invalid';
                }
                if ($bool && ! LegacyConfigMap::legacyBool($value)) {
                    $this->seen['bool_differs'][] = $key;
                }
                $outcome = $ctx->fillSetting((string) $target, $bool);
                if ($key === 'CHAR_ID' && $bool) {
                    $report->note(self::ENTITY.($outcome === 'kept'
                        ? ': CHAR_ID war im Altsystem an; Neusystem-Wert bleibt (mit --overwrite-settings übernehmen).'
                        : ': CHAR_ID war im Altsystem an und wird übernommen (Standard im Neusystem: aus).'));
                }

                return $outcome;
            case 'logo':
                return $this->logo($ctx, trim((string) $value));
        }

        $value = trim((string) $value);
        if ($transform === 'color') {
            $value = strtolower($value);
        }
        if (! $set || ($transform === 'color' && in_array($value, LegacyConfigMap::STOCK_ACCENTS, true))) {
            return 'not_set';
        }
        $definition = $this->definitions->definition((string) $target);
        try {
            $typed = $this->definitions->validate([(string) $target => $value], (string) $definition?->group)[(string) $target];
        } catch (ValidationException) {
            $report->warn(match ($transform) {
                'color' => "{$key}: keine Farbe #rrggbb, nicht übernommen (Standard bleibt).",
                'url' => "{$key}: nur https-Adressen werden übernommen, nicht übernommen.",
                default => "{$key}: ungültig für {$target}, nicht übernommen.",
            });

            return 'invalid';
        }

        $outcome = $ctx->fillSetting((string) $target, $typed);
        if ($outcome === 'kept' && $key === 'SYSTEM_NAME') {
            $report->note('SYSTEM_NAME: Neusystem-Wert bleibt; Altwert unterscheidet sich — mit --overwrite-settings übernehmen.');
        }

        return $outcome;
    }

    private function botActive(): bool
    {
        return LegacyConfigMap::bool($this->config['DISCORD_BOT_ENABLED'] ?? null) === true
            || ! LegacyConfigMap::isPlaceholder($this->config['DISCORD_BOT_TOKEN'] ?? null);
    }

    private static function site(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^https?://~i', $url) !== 1) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? null;
        $port = $port === null || $port === ($scheme === 'http' ? 80 : 443) ? '' : ':'.$port;

        return strtolower($parts['host']).$port.rtrim($parts['path'] ?? '', '/');
    }

    private function logo(ImportContext $ctx, string $value): string
    {
        $report = $ctx->report();
        if ($value === '' || in_array(basename($value), self::DEFAULT_LOGOS, true)) {
            return 'not_set';
        }
        if (preg_match(self::LOGO, $value, $m) !== 1) {
            $report->warn(preg_match('~^(https?:)?//~i', $value) === 1
                ? 'SYSTEM_LOGO ist eine externe Adresse; Logo nicht kopiert, bitte neu hochladen.'
                : 'SYSTEM_LOGO ist kein Upload des Altsystems (/storage/branding/...); Logo nicht kopiert.');

            return 'invalid';
        }
        if ($ctx->uploadsDir() === null) {
            $report->warn('SYSTEM_LOGO: Logo nicht kopiert (ohne --uploads).');

            return 'not_copied';
        }
        $base = realpath($ctx->uploadsDir().'/storage/branding');
        $path = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$m[1]);
        if ($base === false || $path === false || ! is_file($path) || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            $report->warn('SYSTEM_LOGO: Logo nicht kopiert (Datei fehlt unter storage/branding oder liegt außerhalb).');

            return 'not_copied';
        }

        $current = $this->logo->current();
        if ($current !== null && Storage::disk(LogoUpload::DISK)->exists($current)
            && hash('sha256', (string) Storage::disk(LogoUpload::DISK)->get($current)) === $this->cleanHash($path)) {
            return 'unchanged';
        }
        if ($ctx->settings()->has(LogoUpload::KEY) && ! $ctx->overwriteSettings()) {
            return 'kept';
        }
        try {
            if ($ctx->dryRun()) {
                ImageUpload::check($path, LogoUpload::MAX_BYTES, LogoUpload::TYPES);
            } else {
                $this->logo->storeFromPath($path);
            }
        } catch (UploadRejected $e) {
            $report->warn('SYSTEM_LOGO: Logo nicht kopiert ('.$e->getMessage().').');

            return 'not_copied';
        }

        return 'written';
    }

    private function cleanHash(string $path): ?string
    {
        try {
            ImageUpload::check($path, LogoUpload::MAX_BYTES, LogoUpload::TYPES);

            return hash('sha256', ImageUpload::clean($path, LogoUpload::MAX_BYTES));
        } catch (UploadRejected) {
            return null;
        }
    }

    private function summarise(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $list = fn (string $outcome) => ImportReport::idList($this->seen[$outcome] ?? []);
        $count = fn (string $outcome) => count($this->seen[$outcome] ?? []);

        $report->note(self::ENTITY.': '.$count('written').' gesetzt, '.$count('unchanged').' unverändert.');
        $report->warnRows(LegacyConfigMap::BOOL_DIFFERS, $this->seen['bool_differs'] ?? []);
        if ($count('kept')) {
            $report->note(self::ENTITY.': im Neusystem schon gesetzt, behalten (--overwrite-settings ersetzt sie): '.$list('kept'));
        }
        if ($count('not_set')) {
            $report->note(self::ENTITY.': leer, Standard- oder Beispielwert, nicht übernommen: '.$list('not_set'));
        }
        if ($count('later')) {
            $report->note(self::ENTITY.': '.$count('later').' Schlüssel übernimmt der Import des jeweiligen Moduls: '.$list('later'));
        }
        if ($count('discord_bot')) {
            $report->warn('Discord-Bot nicht übernommen (Token neu eintragen); im Altsystem gesetzt: '.$list('discord_bot'));
        }
        if ($count('webhook')) {
            $report->warn('Discord-Webhooks nicht übernommen (die URL enthält ein Token); unter Einstellungen > System > Discord neu eintragen; im Altsystem gesetzt: '.$list('webhook'));
        }
        $report->warnRows('unbekannte intra_config-Schlüssel nicht übernommen', $this->seen['unknown'] ?? []);
    }
}

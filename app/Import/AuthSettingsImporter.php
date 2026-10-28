<?php

namespace App\Import;

use App\Auth\ApiKey;
use Illuminate\Support\Facades\DB;

/**
 * intra_config (API_KEY, REGISTRATION_MODE, TABLET_LOGIN_ENABLED) => settings; the other keys are SystemSettingsImporter's
 * (LegacyConfigMap). API_KEY only with --import-api-key.
 */
final class AuthSettingsImporter implements Importer
{
    private const ENTITY = 'auth_settings';

    private const MODES = ['open', 'code', 'closed'];

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
        return ['intra_config'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $settings = $ctx->settings();
        /** @var array<string, ?string> $config */
        $config = $ctx->legacy()->table('intra_config')
            ->whereIn('config_key', ['API_KEY', 'REGISTRATION_MODE', 'TABLET_LOGIN_ENABLED'])
            ->pluck('config_value', 'config_key')->all();
        $report->read(self::ENTITY, count($config));

        // Re-runs must not undo what the operator changed in the new instance: only missing settings are filled.
        DB::transaction(function () use ($ctx, $report, $settings, $config) {
            $apiKey = new ApiKey($settings);
            if (array_key_exists('API_KEY', $config) && ! $ctx->importApiKey()) {
                if (! $apiKey->matches((string) $config['API_KEY'])) {
                    $report->note('API_KEY nicht übernommen (nur mit --import-api-key); ef_bridge und andere Clients brauchen den Schlüssel der neuen Instanz (ignis:api-key).');
                }
            } elseif (array_key_exists('API_KEY', $config)) {
                $key = (string) $config['API_KEY'];
                if (trim($key) === '' || $key === 'CHANGE_ME' || strlen($key) < 16) {
                    $report->warn('API_KEY unbrauchbar, bitte mit ignis:api-key neu erzeugen');
                } elseif (! $apiKey->isConfigured() || ($ctx->overwriteSettings() && ! $apiKey->matches($key))) {
                    $settings->set('api.key', $key, encrypted: true);
                    $report->written(self::ENTITY);
                } elseif (! $apiKey->matches($key)) {
                    $report->warn('API_KEY im Altsystem weicht vom aktuellen Schlüssel ab, nicht überschrieben (ignis:api-key --regenerate bzw. manuell setzen)');
                }
            }

            if (array_key_exists('REGISTRATION_MODE', $config)) {
                $mode = trim((string) $config['REGISTRATION_MODE']);
                if (in_array($mode, self::MODES, true)) {
                    $this->fill($ctx, 'auth.registration_mode', $mode);
                } else {
                    $report->warn("REGISTRATION_MODE \"{$mode}\" is not open|code|closed, discarded (auth.registration_mode unchanged)");
                }
            }

            if (array_key_exists('TABLET_LOGIN_ENABLED', $config)) {
                $on = LegacyConfigMap::bool($config['TABLET_LOGIN_ENABLED']);
                if ($on === null) {
                    $report->warn('TABLET_LOGIN_ENABLED: kein Ja/Nein-Wert, nicht übernommen (auth.tablet_login bleibt).');
                } else {
                    if ($on && ! LegacyConfigMap::legacyBool($config['TABLET_LOGIN_ENABLED'])) {
                        $report->warnRows(LegacyConfigMap::BOOL_DIFFERS, ['TABLET_LOGIN_ENABLED']);
                    }
                    $this->fill($ctx, 'auth.tablet_login', $on);
                }
            }

            if ($settings->has('discord.redirect_path') && $settings->get('discord.redirect_path') !== '/auth/discord/callback') {
                $report->note('discord.redirect_path kept (already set to another value).');
            } else {
                $settings->set('discord.redirect_path', '/auth/callback.php');
                $report->written(self::ENTITY);
                $report->warn('Die Altanwendung ist bei Discord mit /auth/callback.php registriert; nach Neuregistrierung der URI die Einstellung auf /auth/discord/callback stellen.');
            }
        });
    }

    private function fill(ImportContext $ctx, string $key, mixed $value): void
    {
        match ($ctx->fillSetting($key, $value)) {
            'kept' => $ctx->report()->note("{$key} kept (already set in the new instance)."),
            'written' => $ctx->report()->written(self::ENTITY),
            'unchanged' => null,
        };
    }
}

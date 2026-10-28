<?php

namespace App\Import;

/**
 * Every legacy `intra_config` key with its decision, and the legacy plugin ids
 * with their module names (docs/dev/modules.md "Altsystem-Zuordnung"). LegacyConfigMapTest fails for one of the keys
 * the legacy code writes at runtime without an entry here.
 *
 * Transforms: `string`, `url` (https only), `color` (#rrggbb), `bool`, `logo` (file copy) write the target through the
 * SettingDefinitions rules; `auth` is AuthSettingsImporter's; `drop`, `privacy`, `discord_bot`, `webhook` and `later`
 * (a module importer imports it) are not written, the note says why. Values are never reported, only key names.
 */
final class LegacyConfigMap
{
    /** @var array<string, array{0: ?string, 1: 'string'|'url'|'color'|'bool'|'logo'|'auth'|'drop'|'privacy'|'discord_bot'|'webhook'|'later', 2: string}> */
    public const KEYS = [
        'SYSTEM_NAME' => ['system.name', 'string', ''],
        'SYSTEM_COLOR' => ['system.accent_color', 'color', ''],
        'SYSTEM_LOGO' => ['system.logo', 'logo', ''],
        'META_IMAGE_URL' => ['system.meta_image_url', 'url', ''],
        'SERVER_NAME' => ['organisation.name', 'string', ''],
        'SERVER_CITY' => ['organisation.city', 'string', ''],
        'RP_ORGTYPE' => ['organisation.type', 'string', ''],
        'RP_STREET' => ['organisation.street', 'string', ''],
        'RP_ZIP' => ['organisation.zip', 'string', ''],
        'CHAR_ID' => ['personnel.require_character_id', 'bool', ''],
        'KB_PUBLIC_ACCESS' => ['knowledgebase.public_access', 'bool', ''],
        'LEGAL_IMPRESSUM_URL' => ['legal.imprint_url', 'url', ''],
        'LEGAL_DATENSCHUTZ_URL' => ['legal.privacy_url', 'url', ''],
        'API_KEY' => ['api.key', 'auth', 'Entität auth_settings, nur mit --import-api-key'],
        'REGISTRATION_MODE' => ['auth.registration_mode', 'auth', 'Entität auth_settings'],
        'TABLET_LOGIN_ENABLED' => ['auth.tablet_login', 'auth', 'Entität auth_settings'],

        'SYSTEM_URL' => [null, 'drop', 'die Adresse ist APP_URL (.env)'],
        'BASE_PATH' => [null, 'drop', 'der Neubau läuft unter APP_URL; lief das Altsystem unter einem Unterpfad, IGNIS_IMPORT_LEGACY_BASE_PATH setzen'],
        'CRON_ENDPOINT_TOKEN' => [null, 'drop', 'geplante Aufgaben laufen über den Laravel-Scheduler, kein Cron-Endpunkt'],
        'TELEMETRY_ENABLED' => [null, 'drop', 'Telemetrie entfällt'],
        'TELEMETRY_LAST_HEARTBEAT' => [null, 'drop', 'Telemetrie entfällt'],
        'TELEMETRY_RATE_LIMIT_UNTIL' => [null, 'drop', 'Telemetrie entfällt (Laufzeitwert)'],
        'INSTALLATION_ID' => [null, 'drop', 'Telemetrie entfällt'],
        'UI_NEW_NAVBAR_ENABLED' => [null, 'drop', 'der Neubau hat nur eine Navigation'],
        'SETUP_MODULES_DONE' => [null, 'drop', 'Module wählt der Installer'],
        'LANG' => [null, 'drop', 'im Altsystem entfernt; Sprache über app.locale'],

        'ANNOUNCEMENTS_ENABLED' => [null, 'privacy', 'Ankündigungen sind ein Opt-in (Standard aus), unter Einstellungen > Datenschutz einschalten'],
        'HUB_URL' => [null, 'privacy', 'Umgebungsvariable HUB_URL'],
        'FORUM_URL' => [null, 'privacy', 'Umgebungsvariable FORUM_URL'],

        'DISCORD_BOT_ENABLED' => [null, 'discord_bot', ''],
        'DISCORD_BOT_TOKEN' => [null, 'discord_bot', ''],
        'DISCORD_BOT_ID' => [null, 'discord_bot', ''],
        'DISCORD_BOT_NAME' => [null, 'discord_bot', ''],
        'DISCORD_BOT_AVATAR' => [null, 'discord_bot', ''],
        'DISCORD_BOT_DM_TYPES' => [null, 'discord_bot', ''],
        'DISCORD_WEBHOOK_ENOTF_PROTOCOL' => [null, 'webhook', ''],
        'DISCORD_WEBHOOK_ENOTF_PREREG' => [null, 'webhook', ''],
        'DISCORD_WEBHOOK_FIRE_PROTOCOL' => [null, 'webhook', ''],

        'ENOTF_PREREG' => ['enotf.prereg_enabled', 'bool', ''],
        'ENOTF_USE_PIN' => ['enotf.pin_enabled', 'bool', ''],
        'ENOTF_PIN' => [null, 'drop', 'die globale PIN entfällt, jede Besatzung setzt ihre eigene Sitzungs-PIN'],
        'ENOTF_PIN_TIMEOUT' => ['enotf.pin_timeout', 'string', ''],
        'ENOTF_REQUIRE_USER_AUTH' => [null, 'drop', 'ein Konto ist immer Pflicht, Tablets melden sich über den Tablet-Login an'],
        'ENOTF_BZ_UNIT' => ['enotf.bz_unit', 'string', ''],
        'ENOTF_CHAR_LOCK' => ['enotf.char_lock', 'bool', ''],
        'ENOTF_JOB_FILTER' => ['enotf.job_filter', 'bool', ''],
        'FIRE_INCIDENT_REQUIRE_USER_AUTH' => [null, 'drop', 'ein Konto ist immer Pflicht, Tablets melden sich über den Tablet-Login an'],
        'FIRE_ASU_CONSUMPTION' => ['firetab.asu_consumption', 'string', ''],
        'FIRE_ASU_START_BAR' => ['firetab.asu_start_bar', 'string', ''],
        'MAIL_DOMAIN' => [null, 'later', 'Entität mail (MailImporter) => mail.domain'],
        'MAIL_ADDRESS_PATTERN' => [null, 'later', 'Entität mail (MailImporter) => mail.address_pattern'],
        'MAIL_ALLOWED_DOMAINS' => [null, 'later', 'Entität mail (MailImporter) => mail.allowed_domains'],
        'MAIL_DEFAULT_SIGNATURE' => [null, 'later', 'Entität mail (MailImporter) => Standard-Signatur (mail_signatures)'],
        'MAIL_SEND_COOLDOWN' => [null, 'later', 'Entität mail (MailImporter) => mail.send_cooldown'],
        'FEDERATION_ENABLED' => [null, 'later', 'Entität federation (FederationImporter) => modules.federation.enabled'],
        'FEDERATION_INSTANCE_ID' => [null, 'later', 'Entität federation (FederationImporter) => federation.instance_id (unverändert)'],
        'FEDERATION_INSTANCE_NAME' => [null, 'later', 'Entität federation (FederationImporter) => federation.instance_name'],
    ];

    public const PLUGINS = [
        'enotf' => 'enotf',
        'firetab' => 'firetab',
        'manv-board' => 'manvboard',
        'logbook' => 'logbook',
        'forms' => 'forms',
        'calendar' => 'calendar',
        'mail' => 'mail',
        'knowledge-base' => 'knowledgebase',
    ];

    public const PLACEHOLDERS = ['', 'CHANGE_ME', 'Musterstadt', 'Musterweg 0815', '1337', 'intraRP', 'ıgnıs'];

    public const STOCK_ACCENTS = ['#d10000', '#f0500a', '#ff4d00'];

    private const TRUE = ['true', '1', 'yes', 'on', 'ja'];

    private const FALSE = ['false', '0', 'no', 'off', 'nein', ''];

    public static function bool(?string $value): ?bool
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, self::TRUE, true) ? true : (in_array($value, self::FALSE, true) ? false : null);
    }

    public static function legacyBool(?string $value): bool
    {
        return in_array($value, ['true', '1', 'yes'], true);
    }

    public const BOOL_DIFFERS = 'Ja/Nein-Wert(e) werden anders gelesen als im Altsystem (dort aus, jetzt an), bitte prüfen';

    public static function isPlaceholder(?string $value): bool
    {
        return in_array(trim((string) $value), self::PLACEHOLDERS, true);
    }

    private function __construct() {}
}

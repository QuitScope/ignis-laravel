<?php

use App\Import\AccountLinkImporter;
use App\Import\AnnouncementDismissalImporter;
use App\Import\AuditLogImporter;
use App\Import\AuthSettingsImporter;
use App\Import\CalendarImporter;
use App\Import\DashboardImporter;
use App\Import\DocumentImporter;
use App\Import\DocumentTemplateImporter;
use App\Import\EmsQualificationImporter;
use App\Import\EnotfImporter;
use App\Import\FederationImporter;
use App\Import\FireQualificationImporter;
use App\Import\FireTabImporter;
use App\Import\FormsImporter;
use App\Import\KnowledgeBaseImporter;
use App\Import\LegacyDocumentImporter;
use App\Import\LoadoutImporter;
use App\Import\LogbookImporter;
use App\Import\MailImporter;
use App\Import\ManvImporter;
use App\Import\ModuleSwitchImporter;
use App\Import\NotificationImporter;
use App\Import\PersonnelImporter;
use App\Import\PersonnelLogImporter;
use App\Import\PersonnelTitleImporter;
use App\Import\PoiImporter;
use App\Import\RankImporter;
use App\Import\RegistrationCodeImporter;
use App\Import\RoleImporter;
use App\Import\SpecialtyImporter;
use App\Import\SystemSettingsImporter;
use App\Import\TacticalSymbolTemplateImporter;
use App\Import\UserImporter;
use App\Import\VehicleAuditContextImporter;
use App\Import\VehicleDefectEventImporter;
use App\Import\VehicleDefectImporter;
use App\Import\VehicleImporter;
use App\Import\VehicleRegistryImporter;
use App\Update\InstalledVersion;

return [
    'version' => InstalledVersion::current(base_path()),
    'release_repo' => env('IGNIS_RELEASE_REPO', ''),
    'update' => [
        // Ed25519 public key (Base64) of the operator; ships with the code, never from settings or the network.
        // The placeholder text PLACEHOLDER keeps updates locked (App\Update\SignatureVerifier).
        'public_key' => is_file(resource_path('update/public.key')) ? trim((string) file_get_contents(resource_path('update/public.key'))) : '',
        'driver' => env('IGNIS_UPDATE_DRIVER'),
        'app_path' => base_path(),
        'state_path' => env('IGNIS_UPDATE_STATE_PATH') ?: storage_path('app/update/state.json'),
        'backup_path' => env('IGNIS_UPDATE_BACKUP_PATH') ?: storage_path('app/update/backups'),
        'log_dir' => env('IGNIS_UPDATE_LOG_DIR'),
        'updater_url' => env('UPDATER_URL'),
        'updater_secret' => env('UPDATER_SECRET'),
        'compose_files' => env('IGNIS_COMPOSE_FILES', '/project/docker-compose.yml'),
        'file_owner' => env('IGNIS_UPDATE_FILE_OWNER', '33:33'),
        'private_dir' => env('IGNIS_UPDATER_PRIVATE_DIR'),
        'keep_backups' => 3,
        'mariadb_dump' => env('IGNIS_UPDATE_MARIADB_DUMP'),
        'max_manifest_age_days' => 180,
        'archive_max_bytes' => 200 * 1024 * 1024,
    ],
    'locales' => ['de', 'en'],
    'timezone' => env('IGNIS_TIMEZONE') ?: 'Europe/Berlin',
    'modules_path' => base_path('modules'),
    // Secret required by /install and ignis:install when set; never persisted.
    'install_token' => env('IGNIS_INSTALL_TOKEN'),
    'tablet_token_ttl' => 60,
    'tablet_tokens_per_minute' => 10,
    'hub_url' => env('HUB_URL', 'https://emergencyforge.de'),
    'forum_url' => env('FORUM_URL', 'https://emergencyforge.de'),
    // Federation module (Modules\Federation\Support\PeerHttp): peers are https with public addresses only. A non-empty
    // host list allows only these hosts; allow_private lifts only the address check (LAN/test nets), never https or redirects.
    'federation' => [
        'allowed_hosts' => array_values(array_filter(array_map(fn (string $h) => strtolower(trim($h)), explode(',', (string) env('FEDERATION_ALLOWED_HOSTS', ''))))),
        'allow_private' => (bool) env('FEDERATION_ALLOW_PRIVATE', false),
    ],
    'import' => [
        // Legacy date-times are wall time in this zone (the legacy PHP date.timezone, Europe/Berlin).
        // TIMESTAMP columns are shown in the legacy MariaDB session time_zone, which should equal this
        // zone; if the legacy server runs in UTC set this to UTC.
        'legacy_timezone' => env('IGNIS_IMPORT_LEGACY_TIMEZONE', 'Europe/Berlin'),
        // Zone the legacy database server ran in when it filled DATETIME columns with NOW() (App\Import\LegacyDbClock); the vehicle,
        // notification and announcement dismissal importers use it. Empty: the zone the legacy server runs in now, with a report warning.
        // TIMESTAMP columns do not need it (read per value as instants).
        'legacy_db_timezone' => env('IGNIS_IMPORT_LEGACY_DB_TIMEZONE'),
        // The legacy BASE_PATH (e.g. /intra/) when the old installation ran below a sub-path: stripped from notification
        // links and quick link targets. Empty (default) or /: nothing is stripped; never guessed.
        'legacy_base_path' => env('IGNIS_IMPORT_LEGACY_BASE_PATH', ''),
    ],
    // Legacy importers run by `ignis:import-legacy` (App\Import\Importer); module importers append theirs.
    'importers' => [
        RoleImporter::class,
        UserImporter::class,
        RegistrationCodeImporter::class,
        AuditLogImporter::class,
        AuthSettingsImporter::class,
        RankImporter::class,
        FireQualificationImporter::class,
        EmsQualificationImporter::class,
        SpecialtyImporter::class,
        PersonnelTitleImporter::class,
        PersonnelImporter::class,
        PersonnelLogImporter::class,
        AccountLinkImporter::class,
        DocumentTemplateImporter::class,
        DocumentImporter::class,
        LegacyDocumentImporter::class,
        PoiImporter::class,
        VehicleImporter::class,
        VehicleDefectImporter::class,
        VehicleDefectEventImporter::class,
        LoadoutImporter::class,
        TacticalSymbolTemplateImporter::class,
        VehicleRegistryImporter::class,
        VehicleAuditContextImporter::class,
        // Module importers go here: after vehicle, user and personnel, before notification and dashboard,
        // whose links point at their records.
        LogbookImporter::class,
        // Before the knowledge base (needs only user and personnel), so KB links to applications resolve in one run.
        FormsImporter::class,
        // After forms (absence mirrors refer to applications), before the knowledge base and notification/dashboard
        // (their links to /calendar/view?id=N need the calendar_event map in the same run).
        CalendarImporter::class,
        // MANV board: after user, personnel, account_link, vehicle and poi; before kb, notification and dashboard (their
        // links to /mci/board?id=N and /mci/patient-view?id=N need the manv_incident/manv_patient map in the same run).
        ManvImporter::class,
        // FireTab: after user, personnel and vehicle; before notification and dashboard (their links /firetab/view?id=N need the
        // fire_incident map in the same run).
        FireTabImporter::class,
        // Federation: config, links with encrypted keys; caches are fetched again (no dependencies).
        FederationImporter::class,
        // eNOTF part 1 (protocols, crew, categories, quick links): after personnel, vehicle and poi; before kb, notification and
        // dashboard (their links /enotf/qm/actions/N need the enotf_protocol map in the same run).
        EnotfImporter::class,
        // Mail: after user, personnel, account_link and the lookups (list rules); before kb, notification and dashboard
        // (their links /mail/inbox/<id> need the mail_message map in the same run).
        MailImporter::class,
        KnowledgeBaseImporter::class,
        // After vehicles and personnel, so notification links find their mapped targets.
        NotificationImporter::class,
        DashboardImporter::class,
        AnnouncementDismissalImporter::class,
        SystemSettingsImporter::class,
        ModuleSwitchImporter::class,
    ],
];

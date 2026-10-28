<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;

/** Inserts rows into the legacy database (after LegacyDatabase::refresh()). */
final class LegacySeed
{
    public static function boot(): bool
    {
        if (! LegacyDatabase::configure()) {
            return false;
        }
        static $loaded = false;
        if (! $loaded) {
            if (LegacyDatabase::refresh() !== []) {
                throw new \RuntimeException('legacy schema failed to load');
            }
            $loaded = true;
        }
        LegacyDatabase::guard();
        self::withoutForeignKeys(function ($db) {
            foreach (['intra_audit_log', 'intra_registration_codes', 'intra_config', 'intra_users', 'intra_mitarbeiter_log', 'intra_mitarbeiter',
                'intra_documents', 'intra_document_templates', 'intra_mitarbeiter_dokumente', 'intra_edivi_pois', 'intra_fahrzeuge',
                'intra_fahrzeuge_defects', 'intra_fahrzeuge_defect_log', 'intra_fahrzeuge_beladung_categories', 'intra_fahrzeuge_beladung_tiles',
                'intra_fahrzeuge_tz_templates', 'intra_fahrzeuge_import_queue', 'intra_notifications', 'intra_dashboard_tiles',
                'intra_dashboard_categories', 'intra_global_announcements_dismissed', 'intra_plugins', 'intra_fahrtenbuch',
                'intra_kb_entry_relations', 'intra_kb_entry_tags', 'intra_kb_entries', 'intra_kb_tags', 'intra_kb_categories',
                'intra_antraege_daten', 'intra_antraege', 'intra_antrag_felder', 'intra_antrag_typen',
                'intra_calendar_event_roles', 'intra_calendar_attendees', 'intra_calendar_events',
                'intra_manv_log', 'intra_manv_patienten', 'intra_manv_ressourcen', 'intra_manv_lagen',
                'intra_mail_signatures', 'intra_mail_attachments', 'intra_mail_deliveries', 'intra_mail_messages', 'intra_mail_list_members',
                'intra_mail_lists', 'intra_mail_address_history', 'intra_mail_mailbox_members', 'intra_mail_mailboxes',
                'intra_fire_incident_map_markers', 'intra_fire_incident_map_zones', 'intra_fire_asu_events', 'intra_fire_asu_members', 'intra_fire_asu_trupps', 'intra_fire_incident_asu',
                'intra_fire_status_queue', 'intra_fire_incident_log', 'intra_fire_incident_sitreps', 'intra_fire_incident_vehicles', 'intra_fire_incidents',
                'intra_edivi', 'intra_enotf_quicklinks', 'intra_enotf_categories', 'intra_enotf_session_members', 'intra_enotf_sessions',
                'intra_edivi_share_requests', 'intra_edivi_qmlog', 'intra_edivi_hospital_availability', 'intra_edivi_hospital_departments',
                'intra_edivi_hospital_access_codes', 'intra_edivi_klinikcodes', 'intra_edivi_prereg'] as $table) {
                $db->table($table)->delete();
            }
            foreach (['intra_edivi_vitalparameter_einzelwerte', 'intra_edivi_medikamente'] as $table) {
                $db->table($table)->truncate();
            }
        });
        self::editorState();
        self::roles();
        self::lookups();

        return true;
    }

    /**
     * Runs `ignis:import-legacy --format=json` and returns the decoded report.
     *
     * @param  list<string>  $only
     * @param  array<string, bool>  $options  e.g. ['--import-api-key' => true]
     * @return array<string, mixed>
     */
    public static function run(array $only = [], bool $dry = false, ?string $uploads = null, array $options = []): array
    {
        $exit = Artisan::call('ignis:import-legacy', ['--only' => $only, '--format' => 'json'] + $options
            + ($dry ? ['--dry-run' => true] : []) + ($uploads !== null ? ['--uploads' => $uploads] : []));
        $json = json_decode(Artisan::output(), true);
        $json['exit'] = $exit;

        return $json;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function insertEach(string $table, array $rows): void
    {
        self::withoutForeignKeys(function ($db) use ($table, $rows) {
            foreach ($rows as $row) {
                $db->table($table)->insert($row);
            }
        });
    }

    private static function withoutForeignKeys(\Closure $fn): void
    {
        $db = LegacyDatabase::connection();
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $fn($db);
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function users(array $rows): void
    {
        self::insertEach('intra_users', array_map(fn (array $r) => $r + [
            'username' => 'user'.$r['id'], 'fullname' => 'Full '.$r['id'], 'discord_id' => (string) (100000 + $r['id']),
            'aktenid' => null, 'role' => 3, 'full_admin' => 0, 'is_active' => 1, 'created_at' => '2024-01-02 03:04:05',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function codes(array $rows): void
    {
        self::insertEach('intra_registration_codes', array_map(fn (array $r) => $r + [
            'code' => 'CODE'.$r['id'], 'label' => null, 'created_by' => null, 'created_at' => '2024-02-03 04:05:06', 'is_used' => 0,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function audit(array $rows): void
    {
        self::withoutForeignKeys(fn ($db) => $db->table('intra_audit_log')->insert($rows));
    }

    /** @param  array<string, string>  $values config_key => config_value */
    public static function config(array $values): void
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = ['config_key' => $key, 'config_value' => $value, 'config_type' => 'string', 'category' => 'basis'];
        }
        LegacyDatabase::connection()->table('intra_config')->insert($rows);
    }

    /** @param  array<string, bool>  $enabled  plugin_id => enabled */
    public static function plugins(array $enabled): void
    {
        foreach ($enabled as $id => $on) {
            LegacyDatabase::connection()->table('intra_plugins')->insert(['plugin_id' => $id, 'enabled' => $on, 'installed_version' => '1.0.0']);
        }
    }

    /**
     * Replaces the legacy default roles with Admin, SGL (unknown permission legacy.custom) and Gast (default).
     * Extra rows (e.g. a duplicate name) can be passed in and are inserted after the three.
     *
     * @param  list<array<string, mixed>>  $extra
     */
    public static function roles(array $extra = []): void
    {
        LegacyDatabase::guard();
        $db = LegacyDatabase::connection();
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        $db->table('intra_users_roles')->delete();
        $db->statement('SET FOREIGN_KEY_CHECKS=1');

        $db->table('intra_users_roles')->insert([
            ['id' => 1, 'priority' => 10, 'name' => 'Admin', 'color' => 'danger', 'permissions' => '["admin"]', 'default' => 0, 'admin' => 1],
            ['id' => 2, 'priority' => 100, 'name' => 'SGL', 'color' => 'primary', 'permissions' => '["users.view","edivi.view","legacy.custom"]', 'default' => 0, 'admin' => 0],
            ['id' => 3, 'priority' => 999, 'name' => 'Gast', 'color' => 'secondary', 'permissions' => '[]', 'default' => 1, 'admin' => 0],
            ...array_map(fn (array $r) => $r + ['color' => 'info', 'permissions' => '[]', 'default' => 0, 'admin' => 0, 'priority' => 500], $extra),
        ]);
    }

    public static function lookups(): void
    {
        $ts = '2023-03-04 05:06:07';
        $tables = [
            'intra_mitarbeiter_dienstgrade' => [
                ['id' => 10, 'priority' => 3, 'name' => 'brandmeister/-in', 'name_m' => 'Brandmeister', 'name_w' => 'Brandmeisterin', 'badge' => '/b.png', 'archive' => 0, 'created_at' => $ts],
                ['id' => 11, 'priority' => 0, 'name' => 'Entlassen/Archiv', 'name_m' => 'Entlassen', 'name_w' => 'Entlassen', 'badge' => null, 'archive' => 1, 'created_at' => $ts],
                ['id' => 12, 'priority' => 50, 'name' => 'Sonderrang', 'name_m' => 'Sonder', 'name_w' => 'Sonderin', 'badge' => null, 'archive' => 0, 'created_at' => $ts],
            ],
            'intra_mitarbeiter_fwquali' => [
                ['id' => 20, 'priority' => 0, 'shortname' => '-', 'name' => 'Keine', 'name_m' => 'Keine', 'name_w' => 'Keine', 'none' => 1, 'created_at' => $ts],
                ['id' => 21, 'priority' => 1, 'shortname' => 'B1', 'name' => 'Grundausbildung', 'name_m' => 'Grundausbildung', 'name_w' => 'Grundausbildung', 'none' => 0, 'created_at' => $ts],
            ],
            'intra_mitarbeiter_rdquali' => [
                ['id' => 30, 'priority' => 0, 'name' => 'Keine', 'name_m' => 'Keine', 'name_w' => 'Keine', 'abkuerzung' => null, 'none' => 1, 'trainable' => 0, 'created_at' => $ts],
                ['id' => 31, 'priority' => 4, 'name' => 'Notfallsanitäter/-in', 'name_m' => 'Notfallsanitäter', 'name_w' => 'Notfallsanitäterin', 'abkuerzung' => 'NFS', 'none' => 0, 'trainable' => 1, 'created_at' => $ts],
            ],
            'intra_mitarbeiter_fdquali' => [
                ['id' => 40, 'sgnr' => 211, 'sgname' => 'Integrierte Leitstelle', 'disabled' => 0, 'created_at' => $ts],
                ['id' => 41, 'sgnr' => 413, 'sgname' => 'Luftrettung', 'disabled' => 0, 'created_at' => $ts],
                ['id' => 42, 'sgnr' => 999, 'sgname' => 'Eigener FD', 'disabled' => 1, 'created_at' => $ts],
            ],
            'intra_mitarbeiter_titel' => [
                ['id' => 50, 'name' => 'Dr.', 'priority' => 10],
                ['id' => 51, 'name' => 'DR.', 'priority' => 15],
            ],
        ];
        self::withoutForeignKeys(function ($db) use ($tables) {
            foreach ($tables as $table => $rows) {
                $db->table($table)->delete();
                $db->table($table)->insert($rows);
            }
        });
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function personnel(array $rows): void
    {
        self::insertEach('intra_mitarbeiter', array_map(fn (array $r) => $r + [
            'fullname' => 'Person '.$r['id'], 'titel_id' => null, 'gebdatum' => '1990-01-01', 'charakterid' => null, 'geschlecht' => 0,
            'forumprofil' => null, 'discordtag' => null, 'telefonnr' => null, 'dienstnr' => (string) $r['id'], 'einstdatum' => '2020-01-01',
            'dienstgrad' => 10, 'qualifw2' => 21, 'qualird' => 31, 'zusatz' => null, 'fachdienste' => null, 'pfp' => null,
            'createdate' => '2024-01-02 03:04:05',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function personnelLog(array $rows): void
    {
        $rows = array_map(fn (array $r) => $r + ['type' => 0, 'content' => 'Eintrag '.$r['logid'], 'datetime' => '2024-05-06 07:08:09', 'paneluser' => 'Admin', 'metadata' => null], $rows);
        self::withoutForeignKeys(function ($db) use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table('intra_mitarbeiter_log')->insert($chunk);
            }
        });
    }

    public static function editorState(): void
    {
        $db = LegacyDatabase::connection();
        $db->statement('CREATE TABLE IF NOT EXISTS phinxlog (version BIGINT NOT NULL PRIMARY KEY, migration_name VARCHAR(100) NULL, start_time TIMESTAMP NULL, end_time TIMESTAMP NULL, breakpoint TINYINT(1) NOT NULL DEFAULT 0)');
        $db->statement('INSERT IGNORE INTO phinxlog (version) VALUES (20261016100001), (20250607000122)');
        $db->statement('DROP TABLE IF EXISTS intra_dokument_templates');
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function documentTemplates(array $rows): void
    {
        self::insertEach('intra_document_templates', array_map(fn (array $r) => $r + [
            'name' => 'Vorlage '.$r['id'], 'category' => null, 'is_active' => 1, 'created_by' => null,
            'created_at' => '2026-09-15 12:00:00', 'updated_at' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function documents(array $rows): void
    {
        self::insertEach('intra_documents', array_map(fn (array $r) => $r + [
            'docid' => sprintf('%07d', $r['id']), 'template_id' => null, 'title' => 'Dokument '.$r['id'], 'status' => 'entwurf',
            'frozen_values' => null, 'pdf_path' => null, 'created_by' => null, 'issued_by' => null, 'issued_at' => null,
            'created_at' => '2026-09-20 12:00:00', 'updated_at' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function legacyDocuments(array $rows): void
    {
        $rows = array_map(fn (array $r) => $r + [
            'docid' => sprintf('%07d', $r['id']), 'type' => 0, 'anrede' => 0, 'erhalter' => 'Empfänger '.$r['id'], 'inhalt' => null,
            'suspendtime' => null, 'erhalter_gebdat' => null, 'erhalter_rang' => null, 'erhalter_rang_rd' => null, 'erhalter_quali' => null,
            'ausstellungsdatum' => null, 'ausstellerid' => null, 'aussteller_user_id' => null, 'aussteller_name' => null, 'aussteller_rang' => null,
            'timestamp' => '2025-06-10 12:00:00', 'is_archived' => 0, 'profileid' => null, 'discordid' => null, 'template_id' => null,
            'custom_data' => null, 'pdf_path' => null, 'pdf_generated_at' => null,
        ], $rows);
        self::withoutForeignKeys(function ($db) use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table('intra_mitarbeiter_dokumente')->insert($chunk);
            }
        });
    }

    public static function vehicleQuirks(): void
    {
        $db = LegacyDatabase::connection();
        $db->statement('ALTER TABLE intra_fahrzeuge MODIFY `text` VARCHAR(255) NULL, MODIFY tz_name VARCHAR(255) NULL');
        $db->statement('ALTER TABLE intra_fahrzeuge_tz_templates DROP INDEX IF EXISTS uniq_template_name');
        $db->statement('ALTER TABLE intra_fahrzeuge_import_queue MODIFY raw_data LONGTEXT NULL');
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function pois(array $rows): void
    {
        self::insertEach('intra_edivi_pois', array_map(fn (array $r) => $r + [
            'name' => 'POI '.$r['id'], 'strasse' => null, 'hnr' => null, 'ort' => 'Musterstadt', 'ortsteil' => null, 'typ' => 'Rettungswache',
            'active' => 1, 'created_at' => '2024-03-01 10:00:00', 'updated_at' => '2024-03-02 10:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function vehicles(array $rows): void
    {
        self::insertEach('intra_fahrzeuge', array_map(fn (array $r) => $r + [
            'priority' => $r['id'], 'identifier' => 'veh'.$r['id'], 'name' => 'Fahrzeug '.$r['id'], 'veh_type' => 'RTW', 'rd_type' => 2,
            'active' => 1, 'created_at' => '2024-07-15 12:00:00', 'kennzeichen' => null, 'allowed_jobs' => null, 'stationierung_poi_id' => null,
            'current_status' => null, 'status_updated_at' => null, 'status_source' => null,
            'grundzeichen' => null, 'organisation' => null, 'fachaufgabe' => null, 'einheit' => null, 'symbol' => null, 'text' => null, 'tz_name' => null, 'typ' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function defects(array $rows): void
    {
        self::insertEach('intra_fahrzeuge_defects', array_map(fn (array $r) => $r + [
            'title' => 'Mangel '.$r['id'], 'description' => null, 'category' => 'sonstiges', 'vehicle_operable' => 1, 'status' => 'open',
            'reported_by' => 1, 'assigned_to' => null, 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null,
            'created_at' => '2024-07-15 12:00:00', 'updated_at' => '2024-07-15 13:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function defectLog(array $rows): void
    {
        $rows = array_map(fn (array $r) => $r + ['user_id' => 1, 'action' => 'updated', 'details' => 'Eintrag '.$r['id'], 'created_at' => '2024-07-15 12:00:00'], $rows);
        self::withoutForeignKeys(function ($db) use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table('intra_fahrzeuge_defect_log')->insert($chunk);
            }
        });
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function loadoutCategories(array $rows): void
    {
        self::insertEach('intra_fahrzeuge_beladung_categories', array_map(fn (array $r) => $r + [
            'title' => 'Kategorie '.$r['id'], 'type' => 0, 'priority' => 0, 'veh_type' => 'RTW', 'created_at' => '2024-07-15 12:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function loadoutTiles(array $rows): void
    {
        self::insertEach('intra_fahrzeuge_beladung_tiles', array_map(fn (array $r) => $r + [
            'title' => 'Gegenstand '.$r['id'], 'amount' => 1, 'sort_order' => 0, 'created_at' => '2024-07-15 12:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function logbook(array $rows): void
    {
        self::insertEach('intra_fahrtenbuch', array_map(fn (array $r) => $r + [
            'vehicle_id' => null, 'vehicle_identifier' => 'veh'.$r['id'], 'datum' => '2024-07-15', 'abfahrt' => '08:00:00', 'ankunft' => '09:30:00',
            'stationierungsort' => 'Wache Mitte', 'kilometer' => 12.5, 'grund' => null, 'fahrttyp' => 'dienstfahrt', 'fahrer_name' => 'Max Fahrer',
            'source' => 'admin', 'created_by' => null, 'created_at' => '2024-07-15 12:00:00', 'updated_at' => '2024-07-15 13:00:00',
        ], $rows));
    }

    public static function kbQuirks(): void
    {
        LegacyDatabase::connection()->statement('ALTER TABLE intra_kb_tags DROP INDEX IF EXISTS `name`');
    }

    public static function kbQuirksUndo(): void
    {
        self::withoutForeignKeys(function ($db) {
            $db->table('intra_kb_entry_tags')->delete();
            $db->table('intra_kb_tags')->delete();
        });
        LegacyDatabase::connection()->statement('ALTER TABLE intra_kb_tags ADD UNIQUE INDEX IF NOT EXISTS `name` (`name`)');
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function kbCategories(array $rows): void
    {
        self::insertEach('intra_kb_categories', array_map(fn (array $r) => $r + [
            'parent_id' => null, 'name' => 'Kategorie '.$r['id'], 'slug' => 'kategorie-'.$r['id'], 'icon' => null, 'sort_order' => 0,
            'created_at' => '2024-03-01 10:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function kbTags(array $rows): void
    {
        self::insertEach('intra_kb_tags', array_map(fn (array $r) => $r + [
            'name' => 'Tag '.$r['id'], 'color' => '#6c757d', 'created_at' => '2024-03-01 10:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function kbEntries(array $rows): void
    {
        self::insertEach('intra_kb_entries', array_map(fn (array $r) => $r + [
            'type' => 'general', 'category_id' => null, 'title' => 'Eintrag '.$r['id'], 'subtitle' => null, 'competency_level' => null,
            'content' => '<p>Inhalt '.$r['id'].'</p>', 'is_pinned' => 0, 'is_archived' => 0, 'hide_editor' => 0,
            'created_at' => '2024-03-02 10:00:00', 'created_by' => null, 'updated_at' => '2024-03-03 11:00:00', 'updated_by' => null,
        ], $rows));
    }

    /** @param  list<array{0: int, 1: int}>  $pairs  [entry_id, tag_id] */
    public static function kbEntryTags(array $pairs): void
    {
        self::insertEach('intra_kb_entry_tags', array_map(fn (array $p) => ['entry_id' => $p[0], 'tag_id' => $p[1]], $pairs));
    }

    /**
     * Relations without the legacy CHECK (entry_id < related_entry_id), so reverse and self pairs can be seeded.
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     */
    public static function kbRelations(array $pairs): void
    {
        $db = LegacyDatabase::connection();
        $db->statement('SET SESSION check_constraint_checks = 0');
        try {
            self::insertEach('intra_kb_entry_relations', array_map(
                fn (array $p) => ['entry_id' => $p[0], 'related_entry_id' => $p[1], 'created_at' => '2024-03-04 12:00:00'], $pairs));
        } finally {
            $db->statement('SET SESSION check_constraint_checks = 1');
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function symbolTemplates(array $rows): void
    {
        self::insertEach('intra_fahrzeuge_tz_templates', array_map(fn (array $r) => $r + [
            'name' => 'Vorlage '.$r['id'], 'grundzeichen' => null, 'organisation' => null, 'fachaufgabe' => null, 'einheit' => null,
            'symbol' => null, 'typ' => null, 'text' => null, 'created_by' => null, 'created_at' => '2024-07-15 12:00:00', 'updated_at' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function registryQueue(array $rows): void
    {
        self::insertEach('intra_fahrzeuge_import_queue', array_map(fn (array $r) => $r + [
            'emd_vehicle_id' => $r['id'], 'name' => 'EMD '.$r['id'], 'identifier' => 'emd'.$r['id'], 'veh_type' => 'RTW', 'rd_type' => 2,
            'department' => 'LSTE', 'valuelong' => null, 'job' => 'ambulance', 'image' => null, 'funkkanal' => null, 'raw_data' => null,
            'status' => 'pending', 'created_at' => '2024-07-15 12:00:00', 'processed_at' => null, 'processed_by' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function formTypes(array $rows): void
    {
        self::insertEach('intra_antrag_typen', array_map(fn (array $r) => $r + [
            'name' => 'Typ '.$r['id'], 'beschreibung' => null, 'icon' => 'fa-solid fa-file-alt', 'aktiv' => 1, 'sortierung' => 0,
            'tabelle_name' => null, 'erstellt_am' => '2024-03-01 10:00:00', 'erstellt_von' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function formFields(array $rows): void
    {
        self::insertEach('intra_antrag_felder', array_map(fn (array $r) => $r + [
            'label' => 'Feld '.$r['id'], 'feldtyp' => 'text', 'optionen' => null, 'pflichtfeld' => 0, 'platzhalter' => null,
            'sortierung' => $r['id'], 'breite' => 'full', 'standardwert' => null, 'hinweistext' => null, 'readonly' => 0, 'auto_fill' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function forms(array $rows): void
    {
        self::insertEach('intra_antraege', array_map(fn (array $r) => $r + [
            'uniqueid' => (string) (100000 + $r['id']), 'name_dn' => 'Person '.$r['id'].' ('.$r['id'].')', 'dienstgrad' => 'Brandmeister',
            'discordid' => null, 'mitarbeiter_id' => null, 'cirs_status' => 0, 'cirs_manager' => null, 'cirs_text' => null,
            'time_added' => '2024-07-15 12:00:00', 'cirs_time' => null,
        ], $rows));
    }

    /** @param  list<array{0: int, 1: string, 2: ?string}>  $rows  [antrag_id, feldname, wert], ids in order */
    public static function formData(array $rows): void
    {
        self::insertEach('intra_antraege_daten', array_map(fn (array $r) => ['antrag_id' => $r[0], 'feldname' => $r[1], 'wert' => $r[2]], $rows));
    }

    public static function calendarQuirks(): void
    {
        LegacyDatabase::connection()->statement("ALTER TABLE intra_calendar_events MODIFY title VARCHAR(400) NOT NULL, MODIFY visibility VARCHAR(20) NOT NULL DEFAULT 'attendees'");
    }

    public static function calendarQuirksUndo(): void
    {
        self::withoutForeignKeys(function ($db) {
            foreach (['intra_calendar_event_roles', 'intra_calendar_attendees', 'intra_calendar_events'] as $table) {
                $db->table($table)->delete();
            }
        });
        LegacyDatabase::connection()->statement("ALTER TABLE intra_calendar_events MODIFY title VARCHAR(160) NOT NULL, MODIFY visibility ENUM('private','attendees','role','all') NOT NULL DEFAULT 'attendees'");
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function calendarEvents(array $rows): void
    {
        self::insertEach('intra_calendar_events', array_map(fn (array $r) => $r + [
            'title' => 'Termin '.$r['id'], 'description' => null, 'location' => null, 'starts_at' => '2026-11-04 10:00:00',
            'ends_at' => '2026-11-04 11:00:00', 'all_day' => 0, 'color' => 'orange', 'category' => 'general', 'visibility' => 'all',
            'track_attendance' => 0, 'source' => 'manual', 'source_ref_id' => null, 'created_by' => 1, 'recurrence_rule' => null,
            'recurrence_until' => null, 'parent_event_id' => null, 'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-02 12:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function calendarAttendees(array $rows): void
    {
        self::insertEach('intra_calendar_attendees', array_map(fn (array $r) => $r + [
            'response' => 'pending', 'responded_at' => null, 'is_organizer' => 0, 'created_at' => '2026-10-01 12:00:00',
        ], $rows));
    }

    /** @param  list<array{0: int, 1: int}>  $pairs  [event_id, role_id] */
    public static function calendarRoles(array $pairs): void
    {
        self::insertEach('intra_calendar_event_roles', array_map(fn (array $p) => ['event_id' => $p[0], 'role_id' => $p[1]], $pairs));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function notifications(array $rows): void
    {
        $rows = array_map(fn (array $r) => $r + [
            'user_id' => 1, 'type' => 'system', 'title' => 'Hinweis '.$r['id'], 'message' => null, 'link' => null, 'is_read' => 0,
            'created_at' => '2024-07-15 12:00:00', 'read_at' => null,
        ], $rows);
        self::withoutForeignKeys(function ($db) use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table('intra_notifications')->insert($chunk);
            }
        });
    }

    public static function dashboardQuirks(): void
    {
        $db = LegacyDatabase::connection();
        $db->statement('ALTER TABLE intra_dashboard_categories MODIFY title VARCHAR(400) NOT NULL');
        $db->statement("ALTER TABLE intra_dashboard_tiles MODIFY title VARCHAR(400) NOT NULL, MODIFY url VARCHAR(400) NOT NULL DEFAULT '#'");
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function dashboardCategories(array $rows): void
    {
        self::insertEach('intra_dashboard_categories', array_map(fn (array $r) => $r + [
            'title' => 'Kategorie '.$r['id'], 'priority' => 0, 'created_at' => '2024-07-15 12:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function dashboardTiles(array $rows): void
    {
        self::insertEach('intra_dashboard_tiles', array_map(fn (array $r) => $r + [
            'category' => 1, 'title' => 'Kachel '.$r['id'], 'url' => '/inbox', 'icon' => 'fa-solid fa-up-right-from-square', 'priority' => 0,
            'created_at' => '2024-07-15 12:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function dismissals(array $rows): void
    {
        self::insertEach('intra_global_announcements_dismissed', array_map(fn (array $r) => $r + [
            'user_id' => 1, 'dismissed_at' => '2024-07-15 12:00:00',
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function manvIncidents(array $rows): void
    {
        self::insertEach('intra_manv_lagen', array_map(fn (array $r) => $r + [
            'einsatznummer' => 'E-'.$r['id'], 'einsatzort' => 'Ort '.$r['id'], 'einsatzanlass' => null, 'lna_name' => null, 'lna_mitarbeiter_id' => null,
            'orgl_name' => null, 'orgl_mitarbeiter_id' => null, 'status' => 'aktiv', 'einsatzbeginn' => '2026-07-15 10:00:00', 'einsatzende' => null,
            'erstellt_am' => '2026-07-15 10:05:00', 'erstellt_von' => null, 'geaendert_am' => null, 'geaendert_von' => null, 'notizen' => null,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function manvResources(array $rows): void
    {
        self::insertEach('intra_manv_ressourcen', array_map(fn (array $r) => $r + [
            'manv_lage_id' => 1, 'typ' => 'fahrzeug', 'bezeichnung' => 'Ressource '.$r['id'], 'rufname' => null, 'fahrzeugtyp' => null,
            'lokalisation' => null, 'status' => 'verfuegbar', 'besatzung' => null, 'notizen' => null, 'erstellt_am' => '2026-07-15 10:10:00', 'geaendert_am' => null,
        ], $rows));
    }

    /**
     * Inserted without strict mode, so an invalid ENUM value becomes '' as on a legacy server without STRICT_TRANS_TABLES.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function manvPatients(array $rows): void
    {
        $db = LegacyDatabase::connection();
        $mode = (string) $db->selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        $db->statement("SET SESSION sql_mode = ''");
        try {
            self::insertEach('intra_manv_patienten', array_map(fn (array $r) => $r + [
                'manv_lage_id' => 1, 'patienten_nummer' => sprintf('MANV-%03d', $r['id']), 'name' => null, 'vorname' => null, 'geburtsdatum' => null,
                'geschlecht' => 'unbekannt', 'sichtungskategorie' => null, 'sichtungskategorie_zeit' => null, 'sichtungskategorie_geaendert_von' => null,
                'transportmittel' => null, 'transportmittel_rufname' => null, 'fahrzeug_lokalisation' => null, 'transportziel' => null,
                'transport_abfahrt' => null, 'transport_ankunft' => null, 'verletzungen' => null, 'massnahmen' => null, 'notizen' => null,
                'erstellt_am' => '2026-07-15 10:20:00', 'erstellt_von' => null, 'geaendert_am' => null, 'geaendert_von' => null,
            ], $rows));
        } finally {
            $db->statement('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function manvLog(array $rows): void
    {
        self::insertEach('intra_manv_log', array_map(fn (array $r) => $r + [
            'manv_lage_id' => 1, 'timestamp' => '2026-07-15 10:30:00', 'aktion' => 'patient_erstellt', 'beschreibung' => 'Eintrag '.$r['id'],
            'benutzer_id' => null, 'benutzer_name' => null, 'referenz_typ' => null, 'referenz_id' => null,
        ], $rows));
    }

    /**
     * Rows of one intra_mail_* table (without the prefix: `mailboxes`, `messages`, ...) with defaults for the rest;
     * inserted without strict mode and foreign keys, so broken legacy rows (unknown role, dangling ids) can be seeded.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function mail(string $table, array $rows): void
    {
        $ts = '2026-07-15 10:00:00';
        $defaults = [
            'mailboxes' => fn (array $r) => ['kind' => 'personal', 'mitarbeiter_id' => null, 'user_id' => null, 'address' => 'box'.$r['id'].'@ignis.ef',
                'display_name' => 'Postfach '.$r['id'], 'domain' => 'ignis.ef', 'active' => 1, 'locked' => 0, 'created_at' => $ts, 'updated_at' => $ts],
            'mailbox_members' => fn (array $r) => ['created_at' => $ts],
            'address_history' => fn (array $r) => ['address' => 'alt'.$r['id'].'@ignis.ef', 'released_at' => $ts],
            'lists' => fn (array $r) => ['address' => 'liste'.$r['id'].'@ignis.ef', 'name' => 'Liste '.$r['id'], 'kind' => 'static', 'rule' => null,
                'senders' => 'all', 'created_at' => $ts, 'updated_at' => $ts],
            'list_members' => fn (array $r) => ['created_at' => $ts],
            'messages' => fn (array $r) => ['sent_by_user_id' => null, 'subject' => 'Betreff '.$r['id'],
                'body_json' => '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Text '.$r['id'].'"}]}]}',
                'body_html' => '<p>Text '.$r['id'].'</p>', 'header_json' => '{"to":[],"cc":[],"bcc":[]}', 'thread_id' => md5('t'.$r['id']),
                'in_reply_to' => null, 'status' => 'sent', 'sent_at' => $ts, 'created_at' => $ts, 'updated_at' => $ts],
            'deliveries' => fn (array $r) => ['role' => 'to', 'folder' => 'inbox', 'read_at' => null, 'flagged' => 0, 'deleted_at' => null, 'created_at' => $ts],
            'attachments' => fn (array $r) => ['original_name' => 'datei'.$r['id'].'.txt', 'mime' => 'text/plain', 'size' => 1, 'created_at' => $ts],
            'signatures' => fn (array $r) => ['body_json' => '{"type":"doc","content":[]}', 'created_at' => $ts, 'updated_at' => $ts],
        ][$table];
        $db = LegacyDatabase::connection();
        $mode = (string) $db->selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        $db->statement("SET SESSION sql_mode = ''");
        try {
            self::withoutForeignKeys(function ($db) use ($table, $rows, $defaults) {
                foreach (array_chunk(array_map(fn (array $r) => $r + $defaults($r), $rows), 200) as $chunk) {
                    $db->table('intra_mail_'.$table)->insert($chunk);
                }
            });
        } finally {
            $db->statement('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    /**
     * Rows of one intra_fire_* table (without the prefix: `incidents`, `incident_vehicles`, `incident_sitreps`,
     * `incident_log`, `status_queue`, `asu_trupps`, `asu_members`, `asu_events`, `incident_asu`, `incident_map_markers`, `incident_map_zones`) with defaults for the rest; inserted without foreign keys (orphans can be seeded).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fire(string $table, array $rows): void
    {
        $ts = '2026-07-15 10:00:00';
        $defaults = [
            'incidents' => fn (array $r) => ['incident_number' => 'F-'.$r['id'], 'location' => 'Ort '.$r['id'], 'location_x' => null, 'location_y' => null,
                'keyword' => 'B1', 'caller_name' => null, 'caller_contact' => null, 'started_at' => '2026-07-15 08:00:00', 'leader_id' => null,
                'owner_type' => null, 'owner_name' => null, 'owner_contact' => null, 'status' => 0, 'finalized' => 0, 'finalized_at' => null,
                'finalized_by' => null, 'archived' => 0, 'archived_at' => null, 'archived_by' => null, 'notes' => null, 'created_at' => $ts,
                'created_by' => null, 'updated_at' => null, 'updated_by' => null],
            'incident_vehicles' => fn (array $r) => ['incident_id' => 1, 'vehicle_id' => null, 'vehicle_name' => 'Fahrzeug', 'vehicle_identifier' => null,
                'from_other_org' => 0, 'radio_name' => null, 'current_status' => null, 'status_updated_at' => null, 'created_at' => $ts, 'created_by' => null],
            'incident_sitreps' => fn (array $r) => ['incident_id' => 1, 'report_time' => '2026-07-15 08:30:00', 'text' => 'Lage '.$r['id'],
                'vehicle_radio_name' => null, 'vehicle_id' => null, 'created_at' => $ts, 'created_by' => null, 'source' => null, 'synced' => 0],
            'incident_log' => fn (array $r) => ['incident_id' => 1, 'action_type' => 'data_updated', 'action_description' => 'Eintrag '.$r['id'],
                'vehicle_id' => null, 'operator_id' => null, 'created_by' => null, 'created_at' => $ts],
            'status_queue' => fn (array $r) => ['vehicle_id' => 1, 'vehicle_name' => 'Fahrzeug', 'incident_number' => null, 'new_status' => '3',
                'created_at' => $ts, 'delivered' => 0],
            'asu_trupps' => fn (array $r) => ['incident_id' => 1, 'name' => 'Trupp '.$r['id'], 'role' => 'angriff', 'task' => null,
                'expected_minutes' => 30, 'vehicle_id' => null, 'created_at' => $ts, 'updated_at' => $ts],
            'asu_members' => fn (array $r) => ['trupp_id' => 1, 'position' => 'tf', 'name' => 'Person '.$r['id'], 'mitarbeiter_id' => null],
            'asu_events' => fn (array $r) => ['incident_id' => 1, 'trupp_id' => 1, 'member_id' => null, 'type' => 'druck', 'bar' => null,
                'note' => null, 'client_uuid' => sprintf('00000000-0000-4000-8000-%012d', $r['id']), 'device' => null, 'created_at' => $ts, 'voided_at' => null],
            'incident_asu' => fn (array $r) => ['incident_id' => 1, 'supervisor' => 'Überwacher '.$r['id'], 'mission_location' => null,
                'mission_date' => null, 'timestamp' => null, 'data' => '{}', 'created_at' => $ts, 'updated_at' => $ts],
            'incident_map_markers' => fn (array $r) => ['incident_id' => 1, 'marker_type' => 'Einsatzort', 'pos_x' => 50, 'pos_y' => 50, 'description' => null,
                'grundzeichen' => null, 'organisation' => null, 'fachaufgabe' => null, 'einheit' => null, 'symbol' => null, 'text' => null, 'name' => null,
                'typ' => null, 'created_by' => null, 'vehicle_id' => null, 'operator_id' => null, 'created_at' => $ts],
            'incident_map_zones' => fn (array $r) => ['incident_id' => 1, 'name' => 'Zone '.$r['id'], 'description' => null,
                'points' => '[{"x":10,"y":10},{"x":20,"y":10},{"x":20,"y":20}]', 'color' => '#dc3545', 'created_by' => null, 'vehicle_id' => null,
                'operator_id' => null, 'created_at' => $ts],
        ][$table];
        self::insertEach('intra_fire_'.$table, array_map(fn (array $r) => $r + $defaults($r), $rows));
    }

    /**
     * Rows of intra_edivi (`edivi`), intra_enotf_categories (`categories`), intra_enotf_quicklinks (`quicklinks`),
     * intra_edivi_vitalparameter_einzelwerte (`vitals`), intra_edivi_medikamente (`medications`) or the 9d tables (`qmlog`,
     * `departments`, `availability`, `access_codes`, `klinikcodes`, `prereg`, without the prefix intra_edivi_); the database defaults
     * fill the rest (boot() empties the migration seeds).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function enotf(string $table, array $rows): void
    {
        [$name, $defaults] = [
            'edivi' => ['intra_edivi', fn (array $r) => ['enr' => 'E'.$r['id']]],
            'categories' => ['intra_enotf_categories', fn (array $r) => ['name' => 'Kategorie '.$r['id'], 'slug' => 'kat'.$r['id']]],
            'quicklinks' => ['intra_enotf_quicklinks', fn (array $r) => ['title' => 'Link '.$r['id'], 'url' => 'https://example.org/'.$r['id'], 'category_slug' => 'kat1']],
            'vitals' => ['intra_edivi_vitalparameter_einzelwerte', fn (array $r) => ['enr' => 'E'.$r['id'], 'zeitpunkt' => '2026-03-01 10:00:00',
                'parameter_name' => 'Herzfrequenz', 'parameter_wert' => '80', 'parameter_einheit' => '/min', 'erstellt_von' => 'Lea Leiter']],
            'medications' => ['intra_edivi_medikamente', fn (array $r) => ['wirkstoff' => 'Wirkstoff '.$r['id']]],
            'qmlog' => ['intra_edivi_qmlog', fn (array $r) => ['kommentar' => 'Kommentar '.$r['id'], 'log_aktion' => 0, 'timestamp' => '2026-03-01 10:00:00']],
            'departments' => ['intra_edivi_hospital_departments', fn (array $r) => ['name' => 'Abteilung '.$r['id']]],
            'availability' => ['intra_edivi_hospital_availability', fn (array $r) => []],
            'access_codes' => ['intra_edivi_hospital_access_codes', fn (array $r) => []],
            'klinikcodes' => ['intra_edivi_klinikcodes', fn (array $r) => ['enr' => 'E1', 'code' => 'C'.$r['id'], 'expires_at' => '2020-01-01 00:00:00']],
            'prereg' => ['intra_edivi_prereg', fn (array $r) => ['ziel' => 'poi_1']],
        ][$table];
        self::insertEach($name, array_map(fn (array $r) => $r + $defaults($r), $rows));
    }

    private function __construct() {}
}

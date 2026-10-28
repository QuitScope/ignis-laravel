<?php

use Tests\Support\LegacyDatabase;

const LEGACY_ACCEPTED_FAILURES = [];

it('plays the legacy migrations into the legacy database', function () {
    $failures = LegacyDatabase::refresh();

    expect(array_diff_key($failures, LEGACY_ACCEPTED_FAILURES))->toBe([]);

    $schema = LegacyDatabase::connection()->getSchemaBuilder();

    $columns = [
        'intra_users' => ['discord_id', 'role', 'full_admin', 'is_active'],
        'intra_users_roles' => ['permissions'],
        'intra_mitarbeiter' => ['fullname', 'dienstnr', 'dienstgrad'],
        'intra_fahrtenbuch' => ['fahrer_name'],
    ];
    foreach ($columns as $table => $names) {
        expect($schema->hasColumns($table, $names))->toBeTrue("{$table} lacks one of ".implode(', ', $names));
    }

    $tables = [
        'intra_edivi', 'intra_fahrzeuge', 'intra_document_templates', 'intra_calendar_events', 'intra_kb_entries',
        'intra_mail_messages', 'intra_antraege', 'intra_fire_incidents', 'intra_manv_lagen', 'intra_config',
        'intra_cron_jobs', 'intra_notifications',
    ];
    foreach ($tables as $table) {
        expect($schema->hasTable($table))->toBeTrue("{$table} missing");
    }

    $legacyTables = array_filter($schema->getTableListing(schemaQualified: false), fn (string $name) => str_starts_with($name, 'intra_'));
    expect(count($legacyTables))->toBeGreaterThanOrEqual(90);
})->group('legacy')->skip(fn () => ! LegacyDatabase::available(), 'LEGACY_TEST_DB_HOST not set');

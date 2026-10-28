<?php

use App\Import\NotificationImporter;
use App\Models\DashboardCategory;
use App\Models\DashboardLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const P4B_ENTITIES = ['notification', 'dashboard', 'announcement_dismissal'];

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    LegacySeed::dashboardQuirks();
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'Europe/Berlin', 'ignis.import.legacy_db_timezone' => 'UTC', 'ignis.import.legacy_base_path' => '/intra/']);
});

afterEach(function () {
    if (LegacyDatabase::available()) {
        LegacyDatabase::connection()->statement("SET GLOBAL time_zone = 'SYSTEM'");
    }
});

function seedP4bCases(): void
{
    LegacySeed::users([['id' => 1], ['id' => 2], ['id' => 7, 'discord_id' => null]]);
    DB::table('legacy_id_map')->insert([['entity' => 'vehicle', 'legacy_id' => '3', 'new_id' => 30], ['entity' => 'personnel', 'legacy_id' => '5', 'new_id' => 50]]);
    $rows = [
        ['id' => 1, 'type' => 'vehicle_defect', 'title' => 'Neuer Mangel', 'message' => 'RTW 1: Bremse', 'link' => '/intra/settings/vehicles/defects/index?vehicle=3',
            'is_read' => 1, 'read_at' => '2024-07-15 13:00:00'],
        ['id' => 2, 'type' => 'dokument', 'link' => '/intra/personnel/document-view?docid=1234567', 'is_read' => 1],
        ['id' => 3, 'user_id' => 2, 'type' => 'system', 'link' => '/intra/inbox'],
        ['id' => 4, 'type' => 'antrag', 'link' => '/intra/forms/admin/view?antrag=7'],
        ['id' => 5, 'type' => 'protokoll', 'link' => '/intra/enotf/admin/list'],
        ['id' => 6, 'type' => 'calendar', 'link' => '/intra/calendar/event?id=1'],
        ['id' => 7, 'type' => 'fire_protocol', 'link' => '/intra/firetab/view?id=4'],
        ['id' => 8, 'type' => 'mail', 'link' => '/intra/mail/inbox/12', 'is_read' => 1, 'read_at' => '2024-07-15 14:00:00'],
        ['id' => 9, 'type' => 'quatsch', 'link' => 'javascript:alert(1)'],
        ['id' => 10, 'user_id' => 7],
        ['id' => 11, 'user_id' => 99],
        ['id' => 12, 'link' => '//evil.example/x'],
        ['id' => 13, 'link' => '/intra/personnel/profile?id=5', 'is_read' => 0, 'read_at' => '2024-07-15 13:00:00'],
        ['id' => 14, 'link' => '/intra/settings/vehicles/defects/index?vehicle=9'],
        ['id' => 15, 'link' => '/\\evil.example'],
    ];
    for ($i = 16; $i <= 2500; $i++) {
        $rows[] = ['id' => $i, 'user_id' => $i > 2490 ? 7 : 2];
    }
    LegacySeed::notifications($rows);

    LegacySeed::dashboardCategories([
        ['id' => 1, 'title' => 'Fahrzeuge', 'priority' => 3],
        ['id' => 2, 'title' => str_repeat('k', 300), 'priority' => 20000],
        ['id' => 3, 'title' => 'Negativ', 'priority' => -5],
        ['id' => 4, 'title' => '  '],
    ]);
    LegacySeed::dashboardTiles([
        ['id' => 1, 'url' => '/intra/vehicles', 'icon' => ' FA-Solid FA-Truck ', 'priority' => 2],
        ['id' => 2, 'url' => 'https://example.org/x'],
        ['id' => 3, 'url' => 'javascript:alert(1)'],
        ['id' => 4, 'url' => '#'],
        ['id' => 5, 'url' => 'vehicles'],
        ['id' => 6, 'url' => '?x=1'],
        ['id' => 7, 'category' => 9],
        ['id' => 8, 'category' => 2, 'url' => 'mailto:leitung@example.org', 'icon' => 'fa<script>', 'title' => str_repeat('t', 300)],
        ['id' => 9, 'url' => '/intra//evil.example'],
        ['id' => 10, 'url' => '/'.str_repeat('a', 300)],
        ['id' => 11, 'icon' => ''],
        ['id' => 12, 'url' => '/intra/settings/vehicles/defects/index?vehicle=3'],
        ['id' => 13, 'title' => ' '],
        ['id' => 14, 'url' => '/intra/forms/admin/view?antrag=7'],
    ]);

    LegacySeed::dismissals([
        ['id' => 1, 'announcement_id' => 'hub-1', 'user_id' => 1],
        ['id' => 2, 'announcement_id' => 'hub:2026.1', 'user_id' => 2, 'dismissed_at' => '2024-01-15 12:00:00'],
        ['id' => 3, 'announcement_id' => 'bad id!', 'user_id' => 1],
        ['id' => 4, 'announcement_id' => 'hub-1', 'user_id' => 7],
    ]);
}

function p4bNewId(string $entity, int|string $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

/** @return object the imported notifications row of legacy id $legacyId */
function p4bNotification(int $legacyId): ?object
{
    return DB::table('notifications')->where('id', NotificationImporter::uuid($legacyId))->first();
}

/** @param array<string, mixed> $json */
function p4bWarnings(array $json): string
{
    return implode("\n", $json['warnings']);
}

/** @return array<string, int> */
function p4bCounts(): array
{
    return collect(['notifications', 'dashboard_categories', 'dashboard_links', 'announcement_dismissals', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

it('imports notifications with deterministic ids, cleaned links, read times and counted warnings', function () {
    seedP4bCases();

    $json = LegacySeed::run(['notification']);

    $user1 = p4bNewId('user', 1);
    $user2 = p4bNewId('user', 2);
    $one = p4bNotification(1);
    $data = fn (int $legacy) => json_decode((string) p4bNotification($legacy)->data, true);
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['notification'])->toBe(['read' => 2500, 'written' => 2488, 'skipped' => 12, 'failed' => 0])
        ->and(DB::table('notifications')->count())->toBe(2488)
        ->and($one->type)->toBe('vehicle_defect')->and((int) $one->notifiable_id)->toBe($user1)->and($one->notifiable_type)->toBe((new User)->getMorphClass())
        ->and($data(1))->toBe(['title' => 'Neuer Mangel', 'message' => 'RTW 1: Bremse', 'link' => '/vehicles/defects?vehicle=30'])
        ->and($one->created_at)->toBe('2024-07-15 12:00:00')->and($one->read_at)->toBe('2024-07-15 13:00:00')->and($one->updated_at)->toBe('2024-07-15 13:00:00')
        ->and(p4bNotification(2)->read_at)->toBe('2024-07-15 12:00:00')
        ->and($data(2)['link'])->toBe('/personnel/document-view?docid=1234567')
        ->and((int) p4bNotification(3)->notifiable_id)->toBe($user2)->and($data(3)['link'])->toBe('/inbox')->and(p4bNotification(3)->read_at)->toBeNull()
        ->and($data(4)['link'])->toBe('/forms/admin')->and($data(5)['link'])->toBe('/enotf/admin')
        ->and($data(6)['link'])->toBe('/calendar/event?id=1')->and($data(7)['link'])->toBe('/firetab/list')
        ->and($data(8)['link'])->toBe('/mail')->and(p4bNotification(8)->read_at)->toBe('2024-07-15 12:00:00')
        ->and(p4bNotification(9)->type)->toBe('quatsch')->and($data(9))->toBe(['title' => 'Hinweis 9', 'message' => null, 'link' => null])
        ->and(p4bNotification(10))->toBeNull()->and(p4bNotification(11))->toBeNull()
        ->and($data(12)['link'])->toBeNull()->and($data(15)['link'])->toBeNull()
        ->and($data(13)['link'])->toBe('/personnel/50')->and(p4bNotification(13)->read_at)->toBeNull()
        ->and($data(14)['link'])->toBe('/vehicles/defects')
        ->and(implode("\n", $json['notes']))->toContain('notification: 3 chunks of up to 1000 rows')
        ->toContain('notification: links 5 rewritten, 1 kept unchanged, 4 rewritten to a list page, 3 dropped');
    $warnings = p4bWarnings($json);
    expect($warnings)->toContain("1 notification(s) of the unregistered kind 'quatsch', imported with the raw key (every recipient sees them until the module registers the kind) (legacy ids: 9)");
    expect($warnings)->not->toContain("kind 'system'")->not->toContain("kind 'protokoll'")->not->toContain("kind 'antrag'")->not->toContain("kind 'calendar'")->not->toContain("kind 'dokument'")->not->toContain("kind 'mail'")->not->toContain("kind 'fire_protocol'")
        ->toContain('12 notification(s) of accounts that were not imported were skipped (legacy ids: 10, 11, 2491, 2492')
        ->toContain('1 notification(s) marked read without a read time, read_at set to created_at (legacy ids: 2)')
        ->toContain('3 notification link(s) were not an internal path and were dropped, imported without link (legacy ids: 9, 12, 15)')
        ->toContain('4 notification link(s) point at a record that was not imported (vehicle, personnel file, application), rewritten to the list page (legacy ids: 4, 7, 8, 14)')
        ->toContain('1 notification link(s) kept unchanged: pages LegacyLinkRewriter does not know, they may still hold LEGACY record ids (legacy ids: 6)');
});

it('reads notification times with the legacy database clock, measured when not configured', function () {
    LegacySeed::users([['id' => 1]]);
    LegacySeed::notifications([
        ['id' => 1, 'created_at' => '2024-01-15 12:00:00', 'is_read' => 1, 'read_at' => '2024-01-15 12:30:00'],
        ['id' => 2, 'created_at' => '2024-07-15 12:00:00'],
        ['id' => 3, 'type' => 'mail', 'created_at' => '2024-07-15 12:00:00', 'is_read' => 1, 'read_at' => '2024-07-15 14:00:00'],
    ]);
    LegacySeed::dismissals([['id' => 1, 'announcement_id' => 'a', 'dismissed_at' => '2024-07-15 12:00:00']]);
    LegacySeed::dashboardCategories([['id' => 1, 'created_at' => '2024-07-15 12:00:00']]);
    LegacyDatabase::connection()->statement("SET GLOBAL time_zone = 'Europe/Berlin'");
    config(['ignis.import.legacy_db_timezone' => null]);

    $json = LegacySeed::run(P4B_ENTITIES);

    expect($json['exit'])->toBe(0)
        ->and(p4bNotification(1)->created_at)->toBe('2024-01-15 11:00:00')->and(p4bNotification(1)->read_at)->toBe('2024-01-15 11:30:00')
        ->and(p4bNotification(2)->created_at)->toBe('2024-07-15 10:00:00')
        ->and(p4bNotification(3)->read_at)->toBe('2024-07-15 12:00:00')
        ->and(DB::table('announcement_dismissals')->value('dismissed_at'))->toBe('2024-07-15 10:00:00')
        ->and(DB::table('dashboard_categories')->value('created_at'))->toBe('2024-07-15 12:00:00')
        ->and(p4bWarnings($json))->toContain('IGNIS_IMPORT_LEGACY_DB_TIMEZONE is not set: NOW()-written DATETIME columns are read as Europe/Berlin');
});

it('imports quick links with cleaned targets and icons, refusing every target LinkTarget refuses', function () {
    seedP4bCases();

    $json = LegacySeed::run(['dashboard']);

    $vehicles = DashboardCategory::find(p4bNewId('dashboard_category', 1));
    $long = DashboardCategory::find(p4bNewId('dashboard_category', 2));
    $tile = DashboardLink::find(p4bNewId('dashboard_link', 1));
    $mail = DashboardLink::find(p4bNewId('dashboard_link', 8));
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['dashboard_category'])->toBe(['read' => 4, 'written' => 3, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['dashboard_link'])->toBe(['read' => 14, 'written' => 7, 'skipped' => 7, 'failed' => 0])
        ->and(p4bNewId('dashboard_category', 4))->toBeNull()->and(p4bNewId('dashboard_link', 13))->toBeNull()
        ->and(DashboardLink::find(p4bNewId('dashboard_link', 5))->url)->toBe('/vehicles')
        ->and(DashboardLink::find(p4bNewId('dashboard_link', 12))->url)->toBe('/vehicles/defects?vehicle=30')
        ->and(DashboardLink::find(p4bNewId('dashboard_link', 14))->url)->toBe('/forms/admin')
        ->and($vehicles->title)->toBe('Fahrzeuge')->and($vehicles->sort_order)->toBe(3)
        ->and($long->title)->toBe(str_repeat('k', 255))->and($long->sort_order)->toBe(9999)
        ->and(DashboardCategory::find(p4bNewId('dashboard_category', 3))->sort_order)->toBe(0)
        ->and($tile->dashboard_category_id)->toBe($vehicles->id)->and($tile->url)->toBe('/vehicles')->and($tile->icon)->toBe('fa-solid fa-truck')
        ->and($tile->sort_order)->toBe(2)
        ->and(DashboardLink::find(p4bNewId('dashboard_link', 2))->url)->toBe('https://example.org/x')
        ->and(DashboardLink::find(p4bNewId('dashboard_link', 2))->icon)->toBe('fa-solid fa-up-right-from-square')
        ->and($mail->dashboard_category_id)->toBe($long->id)->and($mail->url)->toBe('mailto:leitung@example.org')
        ->and($mail->icon)->toBeNull()->and($mail->title)->toBe(str_repeat('t', 255))
        ->and(DashboardLink::find(p4bNewId('dashboard_link', 11))->icon)->toBeNull()
        ->and(DashboardLink::where('url', 'like', '%javascript%')->orWhere('url', 'like', '//%')->count())->toBe(0);
    expect(p4bWarnings($json))
        ->toContain('1 quick link categor(y/ies) with a title over 255 characters, cut (legacy ids: 2)')
        ->toContain('2 quick link categor(y/ies) with a priority outside 0-9999, clamped (legacy ids: 2, 3)')
        ->toContain("3 quick link(s) with a target the new instance refuses (only /path, http(s)://, mailto:) were skipped (legacy id and target: 3 'javascript:alert(1)', 9 '/intra//evil.example', 10 '/aaaa")
        ->toContain("2 quick link(s) with a placeholder or anchor-only target (#, #…, ?…) were skipped: they pointed nowhere (legacy id and target: 4 '#', 6 '?x=1')")
        ->toContain('1 quick link(s) with a relative target (no leading /) were prefixed with / (legacy ids: 5)')
        ->toContain('1 quick link(s) with a legacy page target rewritten to the new page (legacy ids: 12)')
        ->toContain('1 quick link(s) point at a record that was not imported (vehicle, personnel file, application), rewritten to the list page (legacy ids: 14)')
        ->toContain('1 quick link categor(y/ies) with an empty title were skipped (legacy ids: 4)')
        ->toContain('1 quick link(s) with an empty title were skipped (legacy ids: 13)')
        ->toContain('1 quick link(s) of categories that were not imported were skipped (legacy ids: 7)')
        ->toContain('1 quick link(s) with an icon other than lower-case letters, digits, blanks and dashes (or over 100 characters), imported without icon (legacy ids: 8)')
        ->toContain('1 quick link(s) with a title over 255 characters, cut (legacy ids: 8)');
});

it('skips the quick links with a note when the legacy tables do not exist', function () {
    $db = LegacyDatabase::connection();
    $db->statement('RENAME TABLE intra_dashboard_tiles TO intra_dashboard_tiles_hidden');
    try {
        $json = LegacySeed::run(['dashboard']);
    } finally {
        $db->statement('RENAME TABLE intra_dashboard_tiles_hidden TO intra_dashboard_tiles');
    }

    expect($json['exit'])->toBe(0)->and(implode("\n", $json['notes']))->toContain('intra_dashboard_tiles does not exist')
        ->and(DashboardCategory::count())->toBe(0);
});

it('imports announcement dismissals of imported accounts with valid ids and names the tables it leaves out', function () {
    seedP4bCases();

    $json = LegacySeed::run(['announcement_dismissal']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['announcement_dismissal'])->toBe(['read' => 4, 'written' => 2, 'skipped' => 2, 'failed' => 0])
        ->and(DB::table('announcement_dismissals')->orderBy('announcement_id')->get(['announcement_id', 'user_id', 'dismissed_at'])->map(fn ($r) => (array) $r)->all())
        ->toBe([
            ['announcement_id' => 'hub-1', 'user_id' => p4bNewId('user', 1), 'dismissed_at' => '2024-07-15 12:00:00'],
            ['announcement_id' => 'hub:2026.1', 'user_id' => p4bNewId('user', 2), 'dismissed_at' => '2024-01-15 12:00:00'],
        ])
        ->and(p4bWarnings($json))->toContain('1 announcement dismissal(s) with an id outside [A-Za-z0-9._:-]{1,64} were skipped (legacy ids: 3)')
        ->toContain('1 announcement dismissal(s) of accounts that were not imported were skipped (legacy ids: 4)')
        ->and(implode("\n", $json['notes']))->toContain('not imported: intra_global_announcements_cache')
        ->toContain('intra_changelog_cache')->toContain('intra_blog_cache');
});

it('imports everything a second time with identical ids, counts and results', function () {
    seedP4bCases();

    $first = LegacySeed::run(P4B_ENTITIES);
    $after = p4bCounts();
    $rows = DB::table('notifications')->orderBy('id')->get()->map(fn ($n) => (array) $n)->all();
    $links = DB::table('dashboard_links')->orderBy('id')->get()->map(fn ($n) => (array) $n)->all();
    $second = LegacySeed::run(P4B_ENTITIES);

    expect($first['exit'])->toBe(0)->and($second['exit'])->toBe(0)
        ->and(p4bCounts())->toBe($after)
        ->and($second['entities'])->toBe($first['entities'])
        ->and($second['warnings'])->toBe(array_values(array_filter($first['warnings'], fn (string $w) => ! str_contains($w, 'permission(s) unknown'))))
        ->and(DB::table('notifications')->orderBy('id')->get()->map(fn ($n) => (array) $n)->all())->toBe($rows)
        ->and(DB::table('dashboard_links')->orderBy('id')->get()->map(fn ($n) => (array) $n)->all())->toBe($links)
        ->and(DB::table('notifications')->distinct()->count('id'))->toBe(2488);
});

it('leaves no trace on a dry run', function () {
    seedP4bCases();
    $before = p4bCounts();

    $json = LegacySeed::run(P4B_ENTITIES, dry: true);

    expect($json['exit'])->toBe(0)->and($json['dry_run'])->toBeTrue()
        ->and($json['entities']['notification']['written'])->toBe(2488)
        ->and($json['entities']['dashboard_link']['written'])->toBe(7)
        ->and($json['entities']['announcement_dismissal']['written'])->toBe(2)
        ->and(p4bCounts())->toBe($before);
});

it('isolates a failing quick link row', function () {
    seedP4bCases();
    DB::table('legacy_id_map')->insert(['entity' => 'dashboard_category', 'legacy_id' => '9', 'new_id' => 999999]);

    $json = LegacySeed::run(['dashboard']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['dashboard_link'])->toBe(['read' => 14, 'written' => 7, 'skipped' => 6, 'failed' => 1])
        ->and(implode("\n", $json['failures']))->toContain('legacy quick link 7');
});

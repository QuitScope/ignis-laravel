<?php

use App\Branding\LogoUpload;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const LOGO_HEX = '0123456789abcdef0123456789abcdef';

const SECRETS = [
    'API_KEY' => 'apikey-secret-0123456789abcdef',
    'DISCORD_BOT_TOKEN' => 'bot-token-secret-MTIzNDU2',
    'DISCORD_WEBHOOK_FIRE_PROTOCOL' => 'https://discord.com/api/webhooks/1/webhook-secret-token',
    'ENOTF_PIN' => '4711',
    'CRON_ENDPOINT_TOKEN' => 'cron-secret-abcdef0123456789',
    'MAIL_DEFAULT_SIGNATURE' => 'signature-secret-text',
];

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    Storage::fake(LogoUpload::DISK);
    $this->uploads = sys_get_temp_dir().'/ignis-branding-'.bin2hex(random_bytes(6));
    $this->branding = $this->uploads.'/storage/branding';
    File::ensureDirectoryExists($this->branding);
});

afterEach(fn () => isset($this->uploads) && File::deleteDirectory($this->uploads));

function brandingPng(string $path): void
{
    $image = imagecreatetruecolor(3, 2);
    imagepng($image, $path);
    imagedestroy($image);
}

/** @return array<string, string> the good values of every written key */
function goodConfig(): array
{
    return [
        'SYSTEM_NAME' => 'Leitstelle Nord', 'SYSTEM_COLOR' => '#1A2B3C', 'META_IMAGE_URL' => 'https://example.org/og.png',
        'SERVER_NAME' => 'BF Nordstadt', 'SERVER_CITY' => 'Nordstadt', 'RP_ORGTYPE' => 'Berufsfeuerwehr', 'RP_STREET' => 'Hauptstr. 1',
        'RP_ZIP' => '12345', 'CHAR_ID' => 'true', 'LEGAL_IMPRESSUM_URL' => 'https://example.org/impressum',
        'LEGAL_DATENSCHUTZ_URL' => 'https://example.org/datenschutz',
    ];
}

/** @param array<string, mixed> $json */
function counts(array $json, string $entity): array
{
    return $json['entities'][$entity];
}

function setting(string $key): mixed
{
    return app(Settings::class)->get($key);
}

it('compares SYSTEM_URL with APP_URL by host and path', function (string $legacy, string $appUrl, bool $warns) {
    config(['app.url' => $appUrl]);
    LegacySeed::config(['SYSTEM_URL' => $legacy]);

    $json = LegacySeed::run(['system_settings']);

    $warnings = implode(' ', $json['warnings']);
    expect(str_contains($warnings, 'SYSTEM_URL des Altsystems weicht von APP_URL ab'))->toBe($warns)
        ->and($warnings)->not->toContain("\x01")->not->toContain('<script>');
})->with([
    'bare domain (legacy form)' => ['intra.example.de', 'https://intra.example.de', false],
    'trailing slash' => ['intra.example.de/', 'https://intra.example.de/', false],
    'scheme, case and default port' => ['https://Intra.Example.DE:443/', 'https://intra.example.de', false],
    'http with own port' => ['http://intra.example.de:8080', 'http://intra.example.de:8080', false],
    'sub path' => ['example.de/intra', 'https://example.de/intra', false],
    'other host' => ['old.example.org', 'https://new.example.org', true],
    'other port' => ['intra.example.de:8443', 'https://intra.example.de', true],
    'other path' => ['example.de/intra', 'https://example.de', true],
    'garbage' => ["\x01<script>", 'https://intra.example.de', true],
]);

it('reports installer values it keeps and takes the legacy ones over with --overwrite-settings', function () {
    $settings = app(Settings::class);
    $settings->set('system.name', 'Vom Installer');
    $settings->set('modules.enotf.enabled', true);
    $settings->set('modules.mail.enabled', false);
    LegacySeed::config(['SYSTEM_NAME' => 'Leitstelle Nord']);
    LegacySeed::plugins(['enotf' => false, 'mail' => false]);

    $json = LegacySeed::run(['system_settings', 'module_switch']);

    $notes = implode(' ', $json['notes']);
    expect(setting('system.name'))->toBe('Vom Installer')
        ->and(setting('modules.enotf.enabled'))->toBeTrue()
        ->and($notes)->toContain('SYSTEM_NAME: Neusystem-Wert bleibt; Altwert unterscheidet sich — mit --overwrite-settings übernehmen')
        ->and($notes)->toContain('module_switch: Neusystem-Wert bleibt; Altwert unterscheidet sich — mit --overwrite-settings übernehmen: enotf')
        ->and($notes)->not->toContain('übernehmen: enotf, mail');

    LegacySeed::run(['system_settings', 'module_switch'], options: ['--overwrite-settings' => true]);

    expect(setting('system.name'))->toBe('Leitstelle Nord')
        ->and(setting('modules.enotf.enabled'))->toBeFalse();
});

it('warns when a boolean is now read differently than by the legacy ConfigManager', function () {
    LegacySeed::config(['CHAR_ID' => 'On', 'TABLET_LOGIN_ENABLED' => 'TRUE']);

    $json = LegacySeed::run(['system_settings', 'auth_settings']);

    $warnings = implode(' ', $json['warnings']);
    expect(setting('personnel.require_character_id'))->toBeTrue()
        ->and(setting('auth.tablet_login'))->toBeTrue()
        ->and($warnings)->toContain('anders gelesen als im Altsystem')->toContain('CHAR_ID')->toContain('TABLET_LOGIN_ENABLED')
        ->and($warnings)->not->toContain('On')->not->toContain('TRUE');
});

it('reads legacy-compatible booleans without that warning', function () {
    LegacySeed::config(['CHAR_ID' => 'yes', 'TABLET_LOGIN_ENABLED' => 'false']);

    $json = LegacySeed::run(['system_settings', 'auth_settings']);

    expect(implode(' ', $json['warnings']))->not->toContain('anders gelesen');
});

it('counts the seed DM types as set only for an active bot', function (array $config, bool $listed) {
    LegacySeed::config($config);

    $json = LegacySeed::run(['system_settings']);

    expect(str_contains(implode(' ', $json['warnings']), 'DISCORD_BOT_DM_TYPES'))->toBe($listed);
})->with([
    'seed default, bot off' => [['DISCORD_BOT_DM_TYPES' => 'mail,system', 'DISCORD_BOT_ENABLED' => 'false', 'DISCORD_BOT_TOKEN' => ''], false],
    'seed default, token set' => [['DISCORD_BOT_DM_TYPES' => 'mail,system', 'DISCORD_BOT_TOKEN' => 'tok-secret'], true],
    'seed default, bot on' => [['DISCORD_BOT_DM_TYPES' => 'mail,system', 'DISCORD_BOT_ENABLED' => 'true'], true],
    'changed, bot off' => [['DISCORD_BOT_DM_TYPES' => 'mail', 'DISCORD_BOT_ENABLED' => 'false'], true],
]);

it('words the CHAR_ID note by what happened', function () {
    app(Settings::class)->set('personnel.require_character_id', false);
    LegacySeed::config(['CHAR_ID' => 'true']);

    $json = LegacySeed::run(['system_settings']);

    expect(setting('personnel.require_character_id'))->toBeFalse()
        ->and(implode(' ', $json['notes']))->not->toContain('wird übernommen')->toContain('CHAR_ID war im Altsystem an; Neusystem-Wert bleibt');
});

it('imports every mapped key', function () {
    LegacySeed::config(goodConfig());

    $json = LegacySeed::run(['system_settings']);

    expect(setting('system.name'))->toBe('Leitstelle Nord')
        ->and(setting('system.accent_color'))->toBe('#1a2b3c')
        ->and(setting('system.meta_image_url'))->toBe('https://example.org/og.png')
        ->and(setting('organisation.name'))->toBe('BF Nordstadt')
        ->and(setting('organisation.city'))->toBe('Nordstadt')
        ->and(setting('organisation.type'))->toBe('Berufsfeuerwehr')
        ->and(setting('organisation.street'))->toBe('Hauptstr. 1')
        ->and(setting('organisation.zip'))->toBe('12345')
        ->and(setting('personnel.require_character_id'))->toBeTrue()
        ->and(setting('legal.imprint_url'))->toBe('https://example.org/impressum')
        ->and(setting('legal.privacy_url'))->toBe('https://example.org/datenschutz')
        ->and(counts($json, 'system_settings'))->toBe(['read' => 11, 'written' => 11, 'skipped' => 0, 'failed' => 0])
        ->and(implode(' ', $json['notes']))->toContain('CHAR_ID war im Altsystem an')
        ->and($json['exit'])->toBe(0);
});

it('treats seed and example values as not set', function () {
    LegacySeed::config(['SYSTEM_NAME' => 'ıgnıs', 'SERVER_NAME' => 'CHANGE_ME', 'SERVER_CITY' => 'Musterstadt', 'RP_STREET' => 'Musterweg 0815',
        'RP_ZIP' => '1337', 'RP_ORGTYPE' => '', 'META_IMAGE_URL' => '', 'SYSTEM_COLOR' => '#d10000', 'SYSTEM_LOGO' => '/assets/img/ignis-wordmark.svg']);
    LegacySeed::config(['SYSTEM_URL' => 'CHANGE_ME']);

    $json = LegacySeed::run(['system_settings']);

    expect(DB::table('settings')->where('key', 'like', 'system.%')->orWhere('key', 'like', 'organisation.%')->count())->toBe(0)
        ->and(counts($json, 'system_settings'))->toBe(['read' => 10, 'written' => 0, 'skipped' => 10, 'failed' => 0])
        ->and(implode(' ', $json['notes']))->toContain('nicht übernommen: META_IMAGE_URL, RP_ORGTYPE, RP_STREET, RP_ZIP, SERVER_CITY, SERVER_NAME, SYSTEM_COLOR, SYSTEM_LOGO, SYSTEM_NAME')
        ->and($json['warnings'])->toBe([]);
});

it('takes the old default name intraRP as not set', function () {
    LegacySeed::config(['SYSTEM_NAME' => 'intraRP']);

    LegacySeed::run(['system_settings']);

    expect(app(Settings::class)->has('system.name'))->toBeFalse();
});

it('accepts only #rrggbb colours', function (string $value, ?string $expected) {
    LegacySeed::config(['SYSTEM_COLOR' => $value]);

    $json = LegacySeed::run(['system_settings']);

    expect(app(Settings::class)->has('system.accent_color') ? setting('system.accent_color') : null)->toBe($expected);
    if ($expected === null) {
        expect(implode(' ', $json['warnings']))->toContain('SYSTEM_COLOR')->not->toContain($value);
    }
})->with([
    ['#ABCDEF', '#abcdef'], ['  #123abc ', '#123abc'], ['#12345', null], ['red', null], ['#fff;}</style>', null], ['#1234567', null],
]);

it('parses CHAR_ID in every boolean spelling and keeps an unparseable value out', function (string $value, ?bool $expected) {
    LegacySeed::config(['CHAR_ID' => $value]);

    $json = LegacySeed::run(['system_settings']);

    expect(app(Settings::class)->has('personnel.require_character_id') ? setting('personnel.require_character_id') : null)->toBe($expected);
    if ($expected === null) {
        expect(implode(' ', $json['warnings']))->toContain('CHAR_ID');
    }
})->with([
    ['true', true], ['1', true], ['YES', true], ['on', true], ['Ja', true],
    ['false', false], ['0', false], ['no', false], ['OFF', false], ['nein', false], ['', false],
    ['vielleicht', null],
]);

it('imports KB_PUBLIC_ACCESS as knowledgebase.public_access in every boolean spelling, fill-if-missing', function (string $value, ?bool $expected) {
    LegacySeed::config(['KB_PUBLIC_ACCESS' => $value]);

    $json = LegacySeed::run(['system_settings']);

    $settings = app(Settings::class);
    expect($settings->has('knowledgebase.public_access') ? $settings->get('knowledgebase.public_access') : null)->toBe($expected)
        ->and(implode(' ', $json['notes']))->not->toContain('KB_PUBLIC_ACCESS');
    if ($expected === null) {
        expect(implode(' ', $json['warnings']))->toContain('KB_PUBLIC_ACCESS: kein Ja/Nein-Wert');
    }
})->with([
    ['true', true], ['1', true], ['yes', true], ['on', true], ['TRUE', true], ['Ja', true],
    ['false', false], ['0', false], ['no', false], ['off', false], ['', false],
    ['vielleicht', null],
]);

it('keeps a public_access switch already set in the new instance unless --overwrite-settings', function () {
    app(Settings::class)->set('knowledgebase.public_access', false);
    LegacySeed::config(['KB_PUBLIC_ACCESS' => 'true']);

    $json = LegacySeed::run(['system_settings']);
    expect(app(Settings::class)->get('knowledgebase.public_access'))->toBeFalse()
        ->and(implode(' ', $json['notes']))->toContain('behalten (--overwrite-settings ersetzt sie): KB_PUBLIC_ACCESS');

    LegacySeed::run(['system_settings'], options: ['--overwrite-settings' => true]);
    expect(app(Settings::class)->get('knowledgebase.public_access'))->toBeTrue();
});

it('takes only https urls', function () {
    LegacySeed::config(['LEGAL_IMPRESSUM_URL' => 'http://example.org/impressum', 'LEGAL_DATENSCHUTZ_URL' => 'javascript:alert(1)',
        'META_IMAGE_URL' => 'https://example.org/og.png']);

    $json = LegacySeed::run(['system_settings']);

    expect(app(Settings::class)->has('legal.imprint_url'))->toBeFalse()
        ->and(app(Settings::class)->has('legal.privacy_url'))->toBeFalse()
        ->and(setting('system.meta_image_url'))->toBe('https://example.org/og.png')
        ->and(implode(' ', $json['warnings']))->toContain('LEGAL_IMPRESSUM_URL: nur https')->toContain('LEGAL_DATENSCHUTZ_URL: nur https')
        ->and(counts($json, 'system_settings'))->toBe(['read' => 3, 'written' => 1, 'skipped' => 2, 'failed' => 0]);
});

it('drops secrets, privacy opt-ins and keys of module importers without printing a value', function () {
    LegacySeed::config(SECRETS + ['DISCORD_BOT_ENABLED' => 'true', 'DISCORD_BOT_NAME' => '', 'DISCORD_WEBHOOK_ENOTF_PREREG' => '',
        'ANNOUNCEMENTS_ENABLED' => 'true', 'HUB_URL' => 'https://hub.example.org', 'TELEMETRY_ENABLED' => 'true', 'INSTALLATION_ID' => 'inst-1',
        'UI_NEW_NAVBAR_ENABLED' => 'true', 'SETUP_MODULES_DONE' => 'true', 'SYSTEM_URL' => 'https://old.example.org', 'BASE_PATH' => '/',
        'FIRE_ASU_START_BAR' => '300', 'KB_PUBLIC_ACCESS' => 'false', 'FEDERATION_ENABLED' => 'false', 'LANG' => 'de', 'SOMETHING_ELSE' => 'x']);

    $json = LegacySeed::run(['system_settings', 'auth_settings']);
    Artisan::call('ignis:import-legacy', ['--only' => ['system_settings', 'auth_settings'], '--dry-run' => true]);
    $text = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).Artisan::output();

    foreach (SECRETS as $secret) {
        expect($text)->not->toContain($secret);
    }
    $warnings = implode(' ', $json['warnings']);
    expect(DB::table('settings')->whereIn('key', ['api.key', 'announcements.hub_enabled', 'announcements.website_enabled'])->count())->toBe(0)
        ->and($warnings)->toContain('Discord-Bot nicht übernommen')->toContain('DISCORD_BOT_ENABLED, DISCORD_BOT_TOKEN')->not->toContain('DISCORD_BOT_NAME')
        ->and($warnings)->toContain('Discord-Webhooks nicht übernommen')->toContain('DISCORD_WEBHOOK_FIRE_PROTOCOL')->not->toContain('DISCORD_WEBHOOK_ENOTF_PREREG')
        ->and($warnings)->toContain('SYSTEM_URL des Altsystems weicht von APP_URL ab')
        ->and($warnings)->toContain('1 unbekannte intra_config-Schlüssel nicht übernommen (legacy ids: SOMETHING_ELSE)')
        ->and(implode(' ', $json['notes']))->toContain('2 Schlüssel übernimmt der Import des jeweiligen Moduls: FEDERATION_ENABLED, MAIL_DEFAULT_SIGNATURE')->toContain('ENOTF_PIN nicht übernommen (die globale PIN entfällt')
        ->toContain('ANNOUNCEMENTS_ENABLED nicht übernommen')->toContain('CRON_ENDPOINT_TOKEN nicht übernommen')
        ->toContain('API_KEY nicht übernommen (nur mit --import-api-key)')
        ->and(setting('knowledgebase.public_access'))->toBeFalse()
        ->and(counts($json, 'system_settings'))->toBe(['read' => 21, 'written' => 2, 'skipped' => 19, 'failed' => 0]);
});

it('reports set bot and webhook keys by name only and stores nothing of them', function () {
    $token = 'bot-token-secret-ZZZ';
    $url = 'https://discord.com/api/webhooks/9/webhook-secret-ZZZ';
    LegacySeed::config(['DISCORD_BOT_TOKEN' => $token, 'DISCORD_WEBHOOK_ENOTF_PROTOCOL' => $url, 'DISCORD_WEBHOOK_FIRE_PROTOCOL' => $url]);

    $json = LegacySeed::run(['system_settings']);
    $dry = LegacySeed::run(['system_settings'], dry: true);

    $warnings = implode(' ', $json['warnings']);
    $stored = json_encode([DB::table('settings')->get(), DB::table('activity_log')->get()]);
    foreach ([$json, $dry] as $report) {
        expect(json_encode($report))->not->toContain('secret-ZZZ');
    }
    expect($warnings)->toContain('DISCORD_BOT_TOKEN')->toContain('DISCORD_WEBHOOK_ENOTF_PROTOCOL, DISCORD_WEBHOOK_FIRE_PROTOCOL')
        ->toContain('Einstellungen > System > Discord')->not->toContain('Phase 5b')
        ->and($stored)->not->toContain('secret-ZZZ')
        ->and(DB::table('settings')->where('key', 'like', 'discord.bot%')->orWhere('key', 'like', 'discord.webhook%')->count())->toBe(0);
});

it('fills only missing settings unless --overwrite-settings is given', function () {
    app(Settings::class)->set('system.name', 'Neu gesetzt');
    LegacySeed::config(['SYSTEM_NAME' => 'Leitstelle Nord', 'SERVER_CITY' => 'Nordstadt']);

    $json = LegacySeed::run(['system_settings']);

    expect(setting('system.name'))->toBe('Neu gesetzt')
        ->and(setting('organisation.city'))->toBe('Nordstadt')
        ->and(implode(' ', $json['notes']))->toContain('behalten (--overwrite-settings ersetzt sie): SYSTEM_NAME')
        ->and(counts($json, 'system_settings'))->toBe(['read' => 2, 'written' => 1, 'skipped' => 1, 'failed' => 0]);

    $again = LegacySeed::run(['system_settings'], options: ['--overwrite-settings' => true]);

    expect(setting('system.name'))->toBe('Leitstelle Nord')
        ->and(counts($again, 'system_settings'))->toBe(['read' => 2, 'written' => 2, 'skipped' => 0, 'failed' => 0])
        ->and(implode(' ', $again['notes']))->toContain('1 gesetzt, 1 unverändert');
});

it('copies an uploaded legacy logo under a new name, once', function () {
    brandingPng($this->branding.'/'.LOGO_HEX.'.png');
    LegacySeed::config(['SYSTEM_LOGO' => '/storage/branding/'.LOGO_HEX.'.png']);

    $first = LegacySeed::run(['system_settings'], uploads: $this->uploads);
    $name = setting('system.logo');

    expect($name)->toMatch(LogoUpload::PATTERN)->not->toBe(LOGO_HEX.'.png')
        ->and(Storage::disk(LogoUpload::DISK)->allFiles())->toBe([$name])
        ->and(array_slice(getimagesizefromstring((string) Storage::disk(LogoUpload::DISK)->get($name)), 0, 2))->toBe([3, 2])
        ->and(counts($first, 'system_settings')['written'])->toBe(1);

    $second = LegacySeed::run(['system_settings'], uploads: $this->uploads);

    expect(setting('system.logo'))->toBe($name)
        ->and(Storage::disk(LogoUpload::DISK)->allFiles())->toBe([$name])
        ->and($second['entities'])->toBe($first['entities']);
});

it('keeps an existing logo unless --overwrite-settings, then replaces the old file', function () {
    Storage::disk(LogoUpload::DISK)->put('ffffffffffffffffffffffffffffffff.png', 'old');
    app(Settings::class)->set('system.logo', 'ffffffffffffffffffffffffffffffff.png');
    brandingPng($this->branding.'/'.LOGO_HEX.'.png');
    LegacySeed::config(['SYSTEM_LOGO' => '/storage/branding/'.LOGO_HEX.'.png']);

    LegacySeed::run(['system_settings'], uploads: $this->uploads);
    expect(setting('system.logo'))->toBe('ffffffffffffffffffffffffffffffff.png');

    LegacySeed::run(['system_settings'], uploads: $this->uploads, options: ['--overwrite-settings' => true]);
    expect(setting('system.logo'))->not->toBe('ffffffffffffffffffffffffffffffff.png')
        ->and(Storage::disk(LogoUpload::DISK)->allFiles())->toBe([setting('system.logo')]);
});

it('does not copy a logo that is unsafe, invalid or missing', function (string $value, ?Closure $prepare, string $warning) {
    brandingPng($this->uploads.'/'.LOGO_HEX.'.png');
    if ($prepare) {
        $prepare($this->branding, $this->uploads);
    }
    LegacySeed::config(['SYSTEM_LOGO' => $value]);

    $json = LegacySeed::run(['system_settings'], uploads: $this->uploads);

    expect(app(Settings::class)->has('system.logo'))->toBeFalse()
        ->and(Storage::disk(LogoUpload::DISK)->allFiles())->toBe([])
        ->and(implode(' ', $json['warnings']))->toContain($warning)
        ->and(counts($json, 'system_settings'))->toBe(['read' => 1, 'written' => 0, 'skipped' => 1, 'failed' => 0]);
})->with([
    'svg' => ['/storage/branding/'.LOGO_HEX.'.png', fn ($dir) => file_put_contents($dir.'/'.LOGO_HEX.'.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'Logo nicht kopiert ('],
    'oversize' => ['/storage/branding/'.LOGO_HEX.'.png', fn ($dir) => file_put_contents($dir.'/'.LOGO_HEX.'.png', str_repeat('x', LogoUpload::MAX_BYTES + 1)), 'Logo nicht kopiert ('],
    'traversal' => ['/storage/branding/../'.LOGO_HEX.'.png', null, 'kein Upload des Altsystems'],
    'traversal name' => ['../x', null, 'kein Upload des Altsystems'],
    'absolute path' => ['/etc/passwd', null, 'kein Upload des Altsystems'],
    'absolute windows path' => ['C:\\Windows\\'.LOGO_HEX.'.png', null, 'kein Upload des Altsystems'],
    'external url' => ['https://cdn.example.org/logo.png', null, 'externe Adresse'],
    'missing file' => ['/storage/branding/'.LOGO_HEX.'.webp', null, 'Datei fehlt'],
]);

it('does not follow a symlink out of the branding folder', function () {
    brandingPng($this->uploads.'/outside.png');
    if (! @symlink($this->uploads.'/outside.png', $this->branding.'/'.LOGO_HEX.'.png')) {
        $this->markTestSkipped('symlinks not available');
    }
    LegacySeed::config(['SYSTEM_LOGO' => '/storage/branding/'.LOGO_HEX.'.png']);

    $json = LegacySeed::run(['system_settings'], uploads: $this->uploads);

    expect(app(Settings::class)->has('system.logo'))->toBeFalse()
        ->and(implode(' ', $json['warnings']))->toContain('außerhalb');
});

it('reports a logo it cannot copy without --uploads', function () {
    LegacySeed::config(['SYSTEM_LOGO' => '/storage/branding/'.LOGO_HEX.'.png']);

    $json = LegacySeed::run(['system_settings']);

    expect(implode(' ', $json['warnings']))->toContain('Logo nicht kopiert (ohne --uploads)');
});

it('maps legacy plugin switches to module switches', function () {
    LegacySeed::plugins(['enotf' => true, 'manv-board' => false, 'knowledge-base' => true, 'mystery' => true]);

    $json = LegacySeed::run(['module_switch']);

    expect(setting('modules.enotf.enabled'))->toBeTrue()
        ->and(setting('modules.manvboard.enabled'))->toBeFalse()
        ->and(setting('modules.knowledgebase.enabled'))->toBeTrue()
        ->and(DB::table('settings')->where('key', 'like', 'modules.%')->count())->toBe(3)
        ->and(implode(' ', $json['warnings']))->toContain('1 unbekannte Plugins nicht übernommen (legacy ids: mystery)')
        ->and(counts($json, 'module_switch'))->toBe(['read' => 4, 'written' => 3, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities'])->not->toHaveKey('system_settings');
});

it('keeps module switches set in the new instance and never writes a core module', function () {
    $dir = sys_get_temp_dir().'/ignis_mod_'.bin2hex(random_bytes(4));
    mkdir("$dir/Mail", 0777, true);
    file_put_contents("$dir/Mail/MailServiceProvider.php", '<?php namespace Tmp\T7Mail; class MailServiceProvider extends \App\Modules\ModuleProvider { public function moduleName(): string { return "Mail"; } public function core(): bool { return true; } }');
    try {
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($dir, app(Settings::class)));
        app(Settings::class)->set('modules.forms.enabled', true);
        LegacySeed::plugins(['mail' => false, 'forms' => false]);

        $json = LegacySeed::run(['module_switch']);

        expect(app(Settings::class)->has('modules.mail.enabled'))->toBeFalse()
            ->and(setting('modules.forms.enabled'))->toBeTrue()
            ->and(implode(' ', $json['notes']))->toContain('Kernmodule sind nicht abschaltbar, Schalter nicht übernommen: mail')
            ->toContain('module_switch: Neusystem-Wert bleibt; Altwert unterscheidet sich — mit --overwrite-settings übernehmen: forms')
            ->and(counts($json, 'module_switch'))->toBe(['read' => 2, 'written' => 0, 'skipped' => 2, 'failed' => 0]);
    } finally {
        File::deleteDirectory($dir);
        ModuleRegistry::flushDiscovery();
    }
});

it('skips missing legacy tables with a note', function () {
    $db = LegacyDatabase::connection();
    $db->statement('RENAME TABLE intra_config TO t7_config_off, intra_plugins TO t7_plugins_off');
    try {
        $json = LegacySeed::run(['system_settings', 'module_switch']);
    } finally {
        $db->statement('RENAME TABLE t7_config_off TO intra_config, t7_plugins_off TO intra_plugins');
    }

    expect($json['exit'])->toBe(0)
        ->and(implode(' ', $json['notes']))->toContain('intra_config missing, skipped')->toContain('intra_plugins missing, skipped');
});

it('runs twice with identical counts and settings', function () {
    LegacySeed::config(goodConfig() + ['SYSTEM_URL' => 'CHANGE_ME', 'ENOTF_PIN' => '1234', 'SYSTEM_COLOR_X' => 'x']);
    LegacySeed::plugins(['enotf' => true, 'mail' => false, 'mystery' => true]);

    $first = LegacySeed::run(['system_settings', 'module_switch']);
    $settings = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();
    $second = LegacySeed::run(['system_settings', 'module_switch']);

    expect($second['entities'])->toBe($first['entities'])
        ->and(DB::table('settings')->orderBy('key')->pluck('value', 'key')->all())->toBe($settings)
        ->and(implode(' ', $second['notes']))->toContain('system_settings: 0 gesetzt, 11 unverändert');
    foreach (['system_settings', 'module_switch'] as $entity) {
        $c = counts($first, $entity);
        expect($c['read'])->toBe($c['written'] + $c['skipped'] + $c['failed']);
    }
});

it('dry run leaves no setting and copies no file', function () {
    brandingPng($this->branding.'/'.LOGO_HEX.'.png');
    LegacySeed::config(goodConfig() + ['SYSTEM_LOGO' => '/storage/branding/'.LOGO_HEX.'.png']);
    LegacySeed::plugins(['enotf' => false]);

    $json = LegacySeed::run(['system_settings', 'module_switch'], dry: true, uploads: $this->uploads);

    expect(DB::table('settings')->count())->toBe(0)
        ->and(app(Settings::class)->has('system.name'))->toBeFalse()
        ->and(Storage::disk(LogoUpload::DISK)->allFiles())->toBe([])
        ->and(counts($json, 'system_settings'))->toBe(['read' => 12, 'written' => 12, 'skipped' => 0, 'failed' => 0])
        ->and(counts($json, 'module_switch')['written'])->toBe(1);
});

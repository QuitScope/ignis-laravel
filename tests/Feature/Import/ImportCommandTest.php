<?php

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\LegacyReader;
use App\Install\InstallState;
use App\Models\Role;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class)->group('slow');

class FakeRoleImporter implements Importer
{
    public function entity(): string
    {
        return 'fake';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return array_values(array_filter([config('test.required_table')]));
    }

    public function import(ImportContext $ctx): void
    {
        $role = Role::create(['name' => 'Ghost', 'guard_name' => 'web', 'priority' => 5, 'color' => 'x', 'is_default' => false]);
        $role->givePermissionTo(Permission::create(['name' => 'ghost.perm', 'guard_name' => 'web']));
        $role->hasPermissionTo('ghost.perm');
        $ctx->ids()->put('fake', 1, $role->id);
        $ctx->settings()->set('ghost.setting', true);
        $ctx->settings()->get('ghost.setting');
        $ctx->report()->read('fake');
        $ctx->report()->written('fake');
    }
}

class DependentImporter extends FakeRoleImporter
{
    public function entity(): string
    {
        return 'dep';
    }

    public function dependsOn(): array
    {
        return ['fake'];
    }
}

class IndependentImporter extends FakeRoleImporter
{
    public static int $runs = 0;

    public function entity(): string
    {
        return 'indep';
    }

    public function import(ImportContext $ctx): void
    {
        self::$runs++;
        $ctx->report()->read('indep');
    }
}

class FailingImporter extends FakeRoleImporter
{
    public function import(ImportContext $ctx): void
    {
        throw new RuntimeException('kaputt');
    }
}

/** Saves a setting inside its own transaction, reads it back (fills the settings cache) and then fails or not. */
class CachingImporter extends FakeRoleImporter
{
    public static bool $fail = false;

    public static bool $sharedSawIt = false;

    public function entity(): string
    {
        return 'caching';
    }

    public function import(ImportContext $ctx): void
    {
        DB::transaction(function () use ($ctx) {
            $ctx->settings()->set('registration_mode', 'open');
            $ctx->settings()->get('registration_mode');
            self::$sharedSawIt = array_key_exists('registration_mode', (array) Cache::store('file')->get('settings.all'));
            if (self::$fail) {
                throw new RuntimeException('kaputt');
            }
        });
        $ctx->report()->written('caching');
    }
}

beforeEach(function () {
    config([
        'ignis.importers' => [FakeRoleImporter::class],
        'database.connections.legacy.host' => 'legacy.test',
        'database.connections.legacy.port' => '3306',
        'database.connections.legacy.database' => 'legacy',
    ]);
    $this->legacyConn = DB::connectUsing('t_legacy_cmd', ['driver' => 'sqlite', 'database' => ':memory:'], true);
    $this->instance(LegacyReader::class, new LegacyReader($this->legacyConn));
});

it('refuses when the app is not installed', function () {
    $this->mock(InstallState::class)->shouldReceive('isInstalled')->andReturnFalse();

    $this->artisan('ignis:import-legacy')->expectsOutputToContain('not installed')->assertExitCode(1);
});

it('refuses an invalid legacy timezone before writing anything', function () {
    config(['ignis.import.legacy_timezone' => 'Europa/Berlin']);
    $roles = Role::count();

    $this->artisan('ignis:import-legacy')->expectsOutputToContain("IGNIS_IMPORT_LEGACY_TIMEZONE 'Europa/Berlin' is not a valid timezone")->assertExitCode(1);

    expect(Role::count())->toBe($roles)->and(DB::table('legacy_id_map')->count())->toBe(0);
});

it('refuses when the legacy connection is not configured', function () {
    config(['database.connections.legacy.host' => null]);

    $this->artisan('ignis:import-legacy')->expectsOutputToContain('LEGACY_DB_HOST')->assertExitCode(1);
});

it('treats an empty LEGACY_DB_HOST (the compose default) as not configured', function () {
    config(['database.connections.legacy.host' => '']);

    $this->artisan('ignis:import-legacy')->expectsOutputToContain('The legacy connection is not configured')->assertExitCode(1);
});

it('refuses when the legacy database is the app database', function () {
    $default = 'database.connections.'.config('database.default');
    foreach (['host' => 'same', 'port' => '1', 'database' => 'same_db'] as $key => $value) {
        config(["database.connections.legacy.{$key}" => $value, "{$default}.{$key}" => $value]);
    }

    $this->artisan('ignis:import-legacy')->expectsOutputToContain('own database')->assertExitCode(1);
});

it('aborts before running when a required legacy table is missing', function () {
    config(['test.required_table' => 'intra_users_roles']);

    $this->artisan('ignis:import-legacy')->expectsOutputToContain('intra_users_roles')->assertExitCode(1);
    expect(Role::where('name', 'Ghost')->exists())->toBeFalse();
});

it('accepts an --uploads root that only has storage/kb-images', function () {
    $root = storage_path('framework/testing/uploads-kb-'.getmypid());
    File::ensureDirectoryExists($root.'/storage/kb-images');
    File::ensureDirectoryExists($root.'/empty');

    try {
        $this->artisan('ignis:import-legacy', ['--dry-run' => true, '--uploads' => $root.'/empty'])->expectsOutputToContain('storage/kb-images')->assertExitCode(1);
        $this->artisan('ignis:import-legacy', ['--dry-run' => true, '--uploads' => $root])->assertExitCode(0);
    } finally {
        File::deleteDirectory($root);
    }
});

it('accepts an --uploads root that only has storage/private/mail-attachments and names it when none is there', function () {
    $root = storage_path('framework/testing/uploads-mail-'.getmypid());
    File::ensureDirectoryExists($root.'/storage/private/mail-attachments');
    File::ensureDirectoryExists($root.'/empty');

    try {
        $this->artisan('ignis:import-legacy', ['--dry-run' => true, '--uploads' => $root.'/empty'])
            ->expectsOutputToContain('storage/private/mail-attachments')->assertExitCode(1);
        $this->artisan('ignis:import-legacy', ['--dry-run' => true, '--uploads' => $root])->assertExitCode(0);
    } finally {
        File::deleteDirectory($root);
    }
});

it('exits 1 with the known entities for an unknown --only', function () {
    $this->artisan('ignis:import-legacy', ['--only' => ['nope']])->expectsOutputToContain('Known entities: fake')->assertExitCode(1);
});

it('warns when phinxlog is older than the analysed version', function () {
    $this->legacyConn->getPdo()->exec('create table phinxlog (version integer); insert into phinxlog values (20250101000000)');

    $this->artisan('ignis:import-legacy', ['--dry-run' => true])->expectsOutputToContain('last analysed')->assertExitCode(0);
});

it('dry run writes nothing and resets the caches', function () {
    $this->artisan('ignis:import-legacy', ['--dry-run' => true])->assertExitCode(0);

    expect(Role::where('name', 'Ghost')->exists())->toBeFalse()
        ->and(Permission::where('name', 'ghost.perm')->exists())->toBeFalse()
        ->and(DB::table('legacy_id_map')->count())->toBe(0)
        ->and(app(Settings::class)->has('ghost.setting'))->toBeFalse()
        ->and(Cache::has(config('permission.cache.key')))->toBeFalse()
        ->and(DB::table('activity_log')->count())->toBe(0);
});

it('a real run persists and audits once', function () {
    $this->artisan('ignis:import-legacy', ['--format' => 'json'])->assertExitCode(0);

    expect(Role::where('name', 'Ghost')->exists())->toBeTrue()
        ->and(DB::table('legacy_id_map')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('description', 'Altdaten-Import ausgeführt')->where('is_global', true)->count())->toBe(1);
});

it('prints the json report', function () {
    Artisan::call('ignis:import-legacy', ['--dry-run' => true, '--format' => 'json']);
    $json = json_decode(Artisan::output(), true);

    expect($json['entities']['fake'])->toBe(['read' => 1, 'written' => 1, 'skipped' => 0, 'failed' => 0]);
});

it('exits 1 when an importer fails', function () {
    config(['ignis.importers' => [FailingImporter::class]]);

    $this->artisan('ignis:import-legacy')->expectsOutputToContain('kaputt')->assertExitCode(1);
});

it('accepts --only as comma list and as repeated option', function () {
    config(['ignis.importers' => [FakeRoleImporter::class, IndependentImporter::class]]);

    foreach ([['--only' => ['fake,indep']], ['--only' => ['fake', 'indep']], ['--only' => ['fake, indep']]] as $options) {
        Artisan::call('ignis:import-legacy', $options + ['--dry-run' => true, '--format' => 'json']);
        $json = json_decode(Artisan::output(), true);
        expect(array_keys($json['entities']))->toBe(['fake', 'indep']);
    }
});

it('reports dry_run in json and validates --format', function () {
    Artisan::call('ignis:import-legacy', ['--dry-run' => true, '--format' => 'json']);
    expect(json_decode(Artisan::output(), true)['dry_run'])->toBeTrue();

    Artisan::call('ignis:import-legacy', ['--format' => 'json']);
    expect(json_decode(Artisan::output(), true)['dry_run'])->toBeFalse();

    $this->artisan('ignis:import-legacy', ['--format' => 'xml'])->expectsOutputToContain('table or json')->assertExitCode(1);
});

it('skips dependants of a failed importer but still runs independent ones', function () {
    IndependentImporter::$runs = 0;
    config(['ignis.importers' => [FailingImporter::class, DependentImporter::class, IndependentImporter::class]]);

    Artisan::call('ignis:import-legacy', ['--format' => 'json']);
    $json = json_decode(Artisan::output(), true);

    expect(IndependentImporter::$runs)->toBe(1)
        ->and($json['entities']['fake']['failed'])->toBe(1)
        ->and($json['entities']['dep']['skipped'])->toBe(1)
        ->and(implode(' ', $json['skips']))->toContain('dependency failed')
        ->and($json['entities']['indep']['read'])->toBe(1);
});

it('leaves no uncommitted settings in a shared cache store', function (bool $dry, bool $fail) {
    config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/cache/import-test-'.uniqid()), 'ignis.importers' => [CachingImporter::class]]);
    CachingImporter::$fail = $fail;
    CachingImporter::$sharedSawIt = false;
    $shared = fn () => Cache::store('file')->get('settings.all');

    Artisan::call('ignis:import-legacy', $dry ? ['--dry-run' => true] : []);

    $stored = DB::table('settings')->where('key', 'registration_mode')->exists();
    expect($stored)->toBe(! $dry && ! $fail)
        ->and($shared() === null || array_key_exists('registration_mode', $shared()) === $stored)->toBeTrue()
        ->and(app(Settings::class)->get('registration_mode'))->toBe($stored ? 'open' : null);
    expect(config('cache.default'))->toBe('file')->and(CachingImporter::$sharedSawIt)->toBe(! $dry);
})->with([[true, false], [false, true], [false, false]]);

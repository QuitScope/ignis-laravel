<?php

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Db\Table;

require_once __DIR__.'/../../tools/legacy-schema/load.php';

function recordingAdapter(): MysqlAdapter
{
    return new class(new PDO('sqlite::memory:')) extends MysqlAdapter
    {
        /** @var list<string> */
        public array $statements = [];

        public function execute(string $sql, array $params = []): int
        {
            $this->statements[] = $sql;

            return 0;
        }
    };
}

it('only allows dropping databases named legacy or legacy_*', function (string $database, bool $allowed) {
    $guard = fn () => legacy_schema_guard(['host' => 'db', 'port' => '3306', 'database' => $database], null);

    $allowed ? expect($guard())->toBeNull() : expect($guard)->toThrow(RuntimeException::class);
})->with([
    ['legacy', true],
    ['legacy_test', true],
    ['ignis', false],
    ['legacyx', false],
    ['', false],
]);

it('refuses a legacy database that is the app database', function () {
    $target = ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'legacy'];

    expect(fn () => legacy_schema_guard($target, $target))->toThrow(RuntimeException::class, 'default connection');
    expect(legacy_schema_guard($target, ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'ignis']))->toBeNull();
});

it('derives migration class names from file names like Phinx', function () {
    expect(legacy_schema_migrations([
        '/l/plugins/enotf/migrations/20250607000064_alter_intra_edivi_pois_08122025_add_typ.php',
        '/l/database/migrations/20250607000003_create_intra_users_07062025.php',
        '/l/plugins/calendar/migrations/20260504000001_create_intra_calendar_events.php',
    ]))->toBe([
        '/l/database/migrations/20250607000003_create_intra_users_07062025.php' => 'CreateIntraUsers07062025',
        '/l/plugins/enotf/migrations/20250607000064_alter_intra_edivi_pois_08122025_add_typ.php' => 'AlterIntraEdiviPois08122025AddTyp',
        '/l/plugins/calendar/migrations/20260504000001_create_intra_calendar_events.php' => 'CreateIntraCalendarEvents',
    ]);
});

it('rejects duplicate versions and class names naming both files', function (array $files) {
    expect(fn () => legacy_schema_migrations($files))->toThrow(RuntimeException::class, basename($files[0]));
})->with([
    'version' => [['/a/20250607000003_create_x.php', '/b/20250607000003_create_y.php']],
    'class' => [['/a/20250607000003_create_x.php', '/b/20250607000004_create_x.php']],
]);

it('rejects option keys the shim does not understand', function (Closure $call) {
    expect(fn () => $call(new Table('t', [], recordingAdapter())))->toThrow(InvalidArgumentException::class, 'bogus');
})->with([
    'column' => [fn (Table $t) => $t->addColumn('a', 'string', ['bogus' => 1])],
    'index' => [fn (Table $t) => $t->addIndex(['a'], ['bogus' => 1])],
    'foreign key' => [fn (Table $t) => $t->addForeignKey('a', 'b', 'id', ['bogus' => 1])],
    'table' => [fn () => new Table('t', ['bogus' => 1], recordingAdapter())],
]);

it('honours index limit and order', function () {
    $adapter = recordingAdapter();
    (new Table('t', [], $adapter))
        ->addColumn('a', 'string')
        ->addColumn('b', 'string')
        ->addIndex(['a', 'b'], ['name' => 'ix', 'limit' => ['a' => 10], 'order' => ['b' => 'DESC']])
        ->create();

    expect($adapter->statements[0])->toContain('KEY `ix` (`a`(10), `b` DESC)');
});

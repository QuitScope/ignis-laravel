<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

it('leaves the legacy connection alone and skips when LEGACY_TEST_DB_HOST is unset', function () {
    config(['database.connections.legacy.host' => 'prod.example', 'database.connections.legacy.database' => 'legacy']);

    expect(LegacyDatabase::configure(['host' => null]))->toBeFalse()
        ->and(config('database.connections.legacy.host'))->toBe('prod.example')
        ->and(config('database.connections.legacy.database'))->toBe('legacy');

    expect(fn () => LegacyDatabase::refresh(['host' => null]))->toThrow(RuntimeException::class, 'LEGACY_TEST_DB_HOST');
});

it('configures the legacy connection from the test variables, default database legacy_test', function () {
    expect(LegacyDatabase::configure(['host' => 'h', 'port' => '1', 'username' => 'u', 'password' => 'p', 'database' => null]))->toBeTrue()
        ->and(config('database.connections.legacy'))->toMatchArray(['host' => 'h', 'port' => '1', 'username' => 'u', 'password' => 'p', 'database' => 'legacy_test']);
});

it('only accepts databases starting with legacy_test that are not the app database', function () {
    $app = ['host' => 'h', 'port' => '1', 'database' => 'legacy_test'];

    LegacyDatabase::assertTestDatabase(['host' => 'h', 'port' => '1', 'database' => 'legacy_test_2'], $app);

    foreach (['legacy', 'legacy_x', 'app'] as $name) {
        expect(fn () => LegacyDatabase::assertTestDatabase(['host' => 'h', 'port' => '1', 'database' => $name], $app))
            ->toThrow(RuntimeException::class, 'legacy_test');
    }
    expect(fn () => LegacyDatabase::assertTestDatabase($app, $app))->toThrow(RuntimeException::class, 'default connection');
});

it('refuses to seed a database that is not a test database', function () {
    config(['database.connections.legacy.host' => 'prod.example', 'database.connections.legacy.database' => 'legacy']);
    DB::purge('legacy');

    LegacySeed::roles();
})->throws(RuntimeException::class, 'legacy_test');

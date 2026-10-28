<?php

use App\Import\LegacyConnectionGuard;

it('accepts a different database', function () {
    LegacyConnectionGuard::assertSeparate(
        ['host' => 'a', 'port' => '3306', 'database' => 'legacy'],
        ['host' => 'a', 'port' => '3306', 'database' => 'app'],
    );
    expect(true)->toBeTrue();
});

it('rejects the app database itself', function () {
    LegacyConnectionGuard::assertSeparate(
        ['host' => 'a', 'port' => 3306, 'database' => 'app'],
        ['host' => 'a', 'port' => '3306', 'database' => 'app'],
    );
})->throws(RuntimeException::class, 'own database');

it('rejects an unconfigured legacy connection', function () {
    LegacyConnectionGuard::assertSeparate(['host' => null, 'database' => 'legacy'], []);
})->throws(RuntimeException::class, 'LEGACY_DB_HOST');

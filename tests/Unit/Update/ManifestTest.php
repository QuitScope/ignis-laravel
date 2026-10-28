<?php

use App\Update\Manifest;
use App\Update\UpdateException;
use Tests\Support\UpdateFixtures;
use Tests\TestCase;

uses(TestCase::class);

it('parses a valid manifest', function () {
    $m = Manifest::parse(UpdateFixtures::manifest(['image' => 'ghcr.io/ignis-test/ignis', 'image_digest' => 'sha256:'.str_repeat('a', 64)]));

    expect($m->version)->toBe('2.0.0')
        ->and($m->archive)->toBe('ignis-2.0.0.zip')
        ->and($m->sha256)->toBe(hash('sha256', 'zip-bytes'))
        ->and($m->minVersion)->toBe('1.0.0')
        ->and($m->createdAt->isToday())->toBeTrue()
        ->and($m->image)->toBe('ghcr.io/ignis-test/ignis')
        ->and($m->imageDigest)->toBe('sha256:'.str_repeat('a', 64));
    expect(Manifest::parse(UpdateFixtures::manifest(['version' => '2.0.0-rc.1']))->image)->toBeNull();
});

it('manifest rejects bad fields', function (string $json) {
    expect(fn () => Manifest::parse($json))->toThrow(UpdateException::class);
})->with([
    'version 1.0' => fn () => UpdateFixtures::manifest(['version' => '1.0']),
    'version with v' => fn () => UpdateFixtures::manifest(['version' => 'v2.0.0']),
    'sha256 too short' => fn () => UpdateFixtures::manifest(['sha256' => str_repeat('a', 63)]),
    'sha256 not hex' => fn () => UpdateFixtures::manifest(['sha256' => str_repeat('g', 64)]),
    'archive traversal' => fn () => UpdateFixtures::manifest(['archive' => '../x.zip']),
    'archive with slash' => fn () => UpdateFixtures::manifest(['archive' => 'a/x.zip']),
    'archive with backslash' => fn () => UpdateFixtures::manifest(['archive' => 'a\\x.zip']),
    'min_version bad' => fn () => UpdateFixtures::manifest(['min_version' => 'dev']),
    'created_at not utc' => fn () => UpdateFixtures::manifest(['created_at' => '2026-10-20T10:00:00+02:00']),
    'created_at garbage' => fn () => UpdateFixtures::manifest(['created_at' => 'yesterday']),
    'missing field' => fn () => json_encode(array_diff_key(json_decode(UpdateFixtures::manifest(), true), ['sha256' => 1])),
    'image digest bad' => fn () => UpdateFixtures::manifest(['image_digest' => 'sha256:abc']),
    'version not a string' => fn () => UpdateFixtures::manifest(['version' => 2]),
    'not json' => fn () => 'nope',
    'json list' => fn () => '[1,2]',
    'created_at not a real date' => fn () => UpdateFixtures::manifest(['created_at' => '2026-02-30T10:00:00Z']),
    'created_at not a real time' => fn () => UpdateFixtures::manifest(['created_at' => '2026-02-10T24:30:00Z']),
    'version with newline' => fn () => UpdateFixtures::manifest(['version' => "2.0.0\n"]),
    'min_version with newline' => fn () => UpdateFixtures::manifest(['min_version' => "1.0.0\n"]),
    'archive with newline' => fn () => UpdateFixtures::manifest(['archive' => "ignis-2.0.0.zip\n"]),
    'sha256 with newline' => fn () => UpdateFixtures::manifest(['sha256' => hash('sha256', 'zip-bytes')."\n"]),
    'created_at with newline' => fn () => UpdateFixtures::manifest(['created_at' => "2026-10-20T10:00:00Z\n"]),
    'image with newline' => fn () => UpdateFixtures::manifest(['image' => "ghcr.io/ignis-test/ignis\n"]),
    'image digest with newline' => fn () => UpdateFixtures::manifest(['image_digest' => 'sha256:'.str_repeat('a', 64)."\n"]),
]);

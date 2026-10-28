<?php

use App\Import\NotificationImporter;
use Ramsey\Uuid\Uuid;

it('derives notification ids as RFC 4122 version 5 UUIDs from a fixed namespace', function () {
    expect(Uuid::uuid5(Uuid::NAMESPACE_DNS, 'python.org')->toString())->toBe('886313e1-3b8a-5372-9b90-0c9aee199e5d');

    $hash = sha1(hex2bin(str_replace('-', '', NotificationImporter::UUID_NAMESPACE)).'intra_notifications:42');
    $hash[12] = '5';
    $hash[16] = dechex(hexdec($hash[16]) & 0x3 | 0x8);
    $manual = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);

    expect(NotificationImporter::uuid(42))->toBe($manual)->toBe(NotificationImporter::uuid('42'))
        ->and(NotificationImporter::uuid(1))->toBe('4292813a-970e-5dda-ba71-eb3e5e7629ba')
        ->and(NotificationImporter::uuid(43))->not->toBe($manual);
});

it('cuts the message until the encoded data fits a TEXT column, escapes included', function () {
    [$json, $cut] = NotificationImporter::data('Titel', str_repeat("\"\x01", 30000).'Ende', '/inbox');
    $data = json_decode($json, true);

    expect($cut)->toBeTrue()->and(strlen($json))->toBeLessThanOrEqual(65535)->and(strlen($json))->toBeGreaterThan(65000)
        ->and($data['title'])->toBe('Titel')->and($data['link'])->toBe('/inbox')
        ->and(str_starts_with(str_repeat("\"\x01", 30000), $data['message']))->toBeTrue();

    [$json, $cut] = NotificationImporter::data('T', str_repeat('ä"', 40000), null);
    expect($cut)->toBeTrue()->and(strlen($json))->toBeLessThanOrEqual(65535)
        ->and(mb_check_encoding(json_decode($json, true)['message'], 'UTF-8'))->toBeTrue();

    expect(NotificationImporter::data('T', 'kurz', null))->toBe(['{"title":"T","message":"kurz","link":null}', false]);
});

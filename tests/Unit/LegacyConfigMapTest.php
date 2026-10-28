<?php

use App\Import\LegacyConfigMap;
use App\Support\SettingDefinitions;
use Tests\TestCase;

uses(TestCase::class);

const RUNTIME_KEYS = ['INSTALLATION_ID', 'TELEMETRY_LAST_HEARTBEAT', 'TELEMETRY_RATE_LIMIT_UNTIL', 'FORUM_URL'];

it('has a decision for every runtime key', function () {
    expect(count(LegacyConfigMap::KEYS))->toBeGreaterThanOrEqual(54)
        ->and(array_values(array_diff(RUNTIME_KEYS, array_keys(LegacyConfigMap::KEYS))))->toBe([])
        ->and(LegacyConfigMap::KEYS['TELEMETRY_RATE_LIMIT_UNTIL'][1])->toBe('drop');
});

it('reads booleans like the legacy ConfigManager for the comparison', function () {
    foreach ([['true', true], ['1', true], ['yes', true], ['TRUE', false], ['On', false], ['ja', false], ['', false], [null, false]] as [$value, $legacy]) {
        expect(LegacyConfigMap::legacyBool($value))->toBe($legacy);
    }
});

it('writes only registered settings and gives every key that is not written a reason', function () {
    $definitions = app(SettingDefinitions::class);
    foreach (LegacyConfigMap::KEYS as $key => [$target, $transform, $note]) {
        if (in_array($transform, ['string', 'url', 'color', 'bool', 'logo', 'auth'], true)) {
            expect($definitions->definition((string) $target))->not->toBeNull("{$key} => {$target}");
        } else {
            expect($target)->toBeNull($key);
        }
        if (in_array($transform, ['drop', 'privacy', 'later'], true)) {
            expect($note)->not->toBe('', $key);
        }
    }
});

it('parses booleans in every spelling', function () {
    foreach ([
        ['true', true], ['1', true], ['yes', true], ['on', true], ['ja', true], ['TRUE', true], [' Yes ', true],
        ['false', false], ['0', false], ['no', false], ['off', false], ['nein', false], ['', false], [null, false], ['OFF', false],
        ['maybe', null], ['2', null],
    ] as [$value, $expected]) {
        expect(LegacyConfigMap::bool($value))->toBe($expected);
    }
});

it('treats seed and example values as not set', function () {
    foreach ([
        ['CHANGE_ME', true], ['Musterstadt', true], ['Musterweg 0815', true], ['1337', true], ['intraRP', true], ['ıgnıs', true],
        ['', true], ['  ', true], ['ignis', false], ['Berlin', false],
    ] as [$value, $placeholder]) {
        expect(LegacyConfigMap::isPlaceholder($value))->toBe($placeholder);
    }
});

it('maps every legacy plugin id of docs/dev/modules.md', function () {
    preg_match_all('/^\| `([a-z-]+)` \| `\w+` \| `(\w+)` \|/m', (string) file_get_contents(base_path('docs/dev/modules.md')), $m);

    expect(LegacyConfigMap::PLUGINS)->toEqual(array_combine($m[1], $m[2]))->toHaveCount(8);
});

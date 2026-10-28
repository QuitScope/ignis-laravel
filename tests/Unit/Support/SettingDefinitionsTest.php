<?php

use App\Support\SettingDefinition;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function sd_registry(): SettingDefinitions
{
    $defs = new SettingDefinitions;
    $defs->register('t.string', 'g', 'string', '', ['max:20']);
    $defs->register('t.secret', 'g', 'string', '', ['max:50'], secret: true);
    $defs->register('t.bool', 'g', 'bool', false);
    $defs->register('t.int', 'g', 'int', 5, ['min:1', 'max:10']);
    $defs->register('t.enum', 'g', 'enum', 'b', options: ['a', 'b', 'c']);
    $defs->register('t.color', 'g', 'color', '#f0500a');
    $defs->register('t.url', 'g', 'url', '');
    $defs->register('t.list', 'g', 'list', []);
    $defs->register('t.internal', 'g', 'string', '', editable: false);
    $defs->register('t.admin', 'g', 'string', '', deletableOnly: true);
    $defs->register('o.other', 'other', 'string', '');

    return $defs;
}

/** @return array<string, list<string>> the validation errors of one call, [] when it passed */
function sd_errors(array $input, string $group = 'g'): array
{
    try {
        sd_registry()->validate($input, $group);

        return [];
    } catch (ValidationException $e) {
        return $e->errors();
    }
}

it('registers definitions and lists them by key, group and group order', function () {
    $defs = sd_registry();

    expect($defs->definition('t.int'))->toBeInstanceOf(SettingDefinition::class)
        ->and($defs->definition('t.int')->default)->toBe(5)
        ->and($defs->definition('t.int')->group)->toBe('g')
        ->and($defs->definition('t.int')->label())->toBe('settings.t.int.label')
        ->and($defs->definition('t.int')->hint())->toBe('settings.t.int.hint')
        ->and($defs->definition('missing'))->toBeNull()
        ->and(array_keys($defs->all()))->toContain('t.string', 'o.other')
        ->and(array_map(fn (SettingDefinition $d) => $d->key, $defs->group('other')))->toBe(['o.other'])
        ->and($defs->group('nope'))->toBe([])
        ->and($defs->groups())->toBe(['g', 'other']);
});

it('ignores a duplicate key with a warning, the first definition stays', function () {
    Log::spy();
    $defs = new SettingDefinitions;
    $defs->register('a.b', 'g', 'string', 'first');
    $defs->register('a.b', 'h', 'int', 2);

    expect($defs->definition('a.b')->default)->toBe('first')->and($defs->groups())->toBe(['g']);
    Log::shouldHaveReceived('warning')->once();
});

it('refuses an unknown type and an enum without options', function (string $type, array $options) {
    (new SettingDefinitions)->register('a.b', 'g', $type, null, options: $options);
})->with([
    'unknown type' => ['float', []],
    'enum without options' => ['enum', []],
])->throws(LogicException::class);

it('flags secrets', function () {
    $defs = sd_registry();

    expect($defs->definition('t.secret')->secret)->toBeTrue()
        ->and($defs->definition('t.string')->secret)->toBeFalse();
});

it('returns typed values for the known keys of the group only', function () {
    expect(sd_registry()->validate([
        't.string' => '  Hallo ', 't.bool' => 'on', 't.int' => '7', 't.enum' => 'c', 't.color' => '#ABCDEF',
        't.url' => 'https://example.org/x', 't.list' => [' a ', '', 'b'], 't.admin' => '',
    ], 'g'))->toBe([
        't.string' => 'Hallo', 't.bool' => true, 't.int' => 7, 't.enum' => 'c', 't.color' => '#abcdef',
        't.url' => 'https://example.org/x', 't.list' => ['a', 'b'], 't.admin' => null,
    ])->and(sd_registry()->validate([], 'g'))->toBe([]);
});

it('rejects unknown, foreign and non-editable keys', function (string $key) {
    expect(sd_errors([$key => 'x']))->toHaveKey($key)
        ->and(sd_errors([$key => 'x'])[$key][0])->toBe(__('settings.unknown'));
})->with(['nope', 'o.other', 't.internal', 'system.name', '']);

it('accepts every boolean spelling', function () {
    foreach ([
        [true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false], ['true', true], ['false', false],
        ['on', true], ['off', false], ['yes', true], ['no', false], ['TRUE', true], [' Off ', false],
    ] as [$in, $out]) {
        expect(sd_registry()->validate(['t.bool' => $in], 'g'))->toBe(['t.bool' => $out]);
    }
});

it('refuses everything else as a boolean', function () {
    foreach ([[''], [null], [2], [-1], ['2'], ['ja'], ['y'], [[]], [['1']], [1.5]] as [$in]) {
        expect(sd_errors(['t.bool' => $in]))->toHaveKey('t.bool');
    }
});

it('reads integers within the rule bounds', function (mixed $in, int $out) {
    expect(sd_registry()->validate(['t.int' => $in], 'g'))->toBe(['t.int' => $out]);
})->with([[1, 1], [10, 10], ['3', 3], [' 4 ', 4]]);

it('refuses integers out of bounds and non-integers', function () {
    foreach ([[0], [11], ['-1'], ['1.5'], [1.5], ['1e1'], ['0x5'], ['abc'], [''], [null], [true], [[]], ['99999999999999999999']] as [$in]) {
        expect(sd_errors(['t.int' => $in]))->toHaveKey('t.int');
    }
});

it('accepts enum options only, case-sensitive', function (mixed $in) {
    expect(sd_errors(['t.enum' => $in]))->toHaveKey('t.enum');
})->with([['d'], ['A'], [''], [null], [1], [['a']]]);

it('accepts colours as #rrggbb only, normalised to lower case', function () {
    foreach ([['#fff'], ['#12345'], ['#1234567'], ['red'], ['f0500a'], ['#gggggg'], ['#fff;}</style>'], ["#abcdef\n"], [''], [null], [123456]] as [$in]) {
        expect(sd_errors(['t.color' => $in]))->toHaveKey('t.color');
    }
});

it('accepts https urls with a host only', function () {
    foreach ([
        ['javascript:alert(1)'], ['http://example.org'], ['HTTPS://example.org'], ['https://'], ['https://user:pw@example.org'],
        ['//example.org'], ['/relative'], ['mailto:a@b.org'], ["https://exa\tmple.org"], ["https://example.org/\0"], ['https://exa mple.org'],
        ['https://example.org/'.str_repeat('a', 300)], ['data:text/html,x'], [['https://example.org']], [5],
    ] as [$in]) {
        expect(sd_errors(['t.url' => $in]))->toHaveKey('t.url');
    }
});

it('treats an empty url as unset', function (mixed $in) {
    expect(sd_registry()->validate(['t.url' => $in], 'g'))->toBe(['t.url' => '']);
})->with([[''], [null], ['  ']]);

it('bounds lists: strings only, at most 100 items of at most 100 characters', function () {
    foreach ([
        ['a,b'], [['a' => 'x']], [[1]], [[['x']]], [[str_repeat('a', 101)]], [array_fill(0, 101, 'x')], [["a\nb"]], [null],
    ] as [$in]) {
        expect(sd_errors(['t.list' => $in]))->toHaveKey('t.list');
    }
});

it('applies the rules of the definition after the type check', function () {
    expect(sd_errors(['t.string' => str_repeat('a', 21)]))->toHaveKey('t.string')
        ->and(sd_registry()->validate(['t.string' => str_repeat('a', 20)], 'g'))->toBe(['t.string' => str_repeat('a', 20)])
        ->and(sd_errors(['t.string' => ['x']]))->toHaveKey('t.string')
        ->and(sd_errors(['t.string' => "a\x07b"]))->toHaveKey('t.string')
        ->and(sd_registry()->validate(['t.string' => null], 'g'))->toBe(['t.string' => '']);
});

it('only lets deletable-only keys be emptied', function () {
    expect(sd_errors(['t.admin' => '123456789012345678']))->toHaveKey('t.admin')
        ->and(sd_registry()->validate(['t.admin' => null], 'g'))->toBe(['t.admin' => null]);
});

it('reports every failing key at once', function () {
    expect(array_keys(sd_errors(['t.int' => 0, 't.color' => 'red', 'nope' => 1])))->toEqualCanonicalizing(['t.int', 't.color', 'nope']);
});

it('never echoes the input in error messages, secrets included', function () {
    $hostile = 'TOPSECRET-'.str_repeat('z', 60);
    $errors = sd_errors(['t.secret' => $hostile, 't.string' => 'LEAKY-'.str_repeat('y', 30), 't.color' => 'LEAKCOLOR', 't.url' => 'https://LEAKURL@x']);

    expect($errors)->toHaveKeys(['t.secret', 't.string', 't.color', 't.url'])
        ->and(json_encode($errors))->not->toContain('TOPSECRET')->not->toContain('LEAK');
});

it('falls back to the definition default when a setting is missing', function () {
    $settings = app(Settings::class);

    expect($settings->get('system.accent_color'))->toBe('#f0500a')
        ->and($settings->get('auth.registration_mode'))->toBe('code')
        ->and($settings->get('updates.check_enabled'))->toBeFalse()
        ->and($settings->get('system.accent_color', '#000000'))->toBe('#000000')
        ->and($settings->get('unregistered.key'))->toBeNull()
        ->and($settings->get('unregistered.key', 'x'))->toBe('x');

    $settings->set('system.accent_color', '#123456');
    expect($settings->get('system.accent_color'))->toBe('#123456');
});

it('registers the core definitions in their groups, module groups after them', function () {
    $defs = app(SettingDefinitions::class);

    expect($defs->groups())->toBe(['general', 'organisation', 'auth', 'interfaces', 'features', 'legal', 'privacy', 'discord', 'enotf', 'firetab', 'mail'])
        ->and($defs->definition('discord.client_secret')->secret)->toBeTrue()
        ->and($defs->definition('discord.bot.token')->secret)->toBeTrue()
        ->and(array_filter($defs->group('discord'), fn ($d) => $d->editable))->toBe([])
        ->and($defs->definition('discord.dm_kinds')->default)->toBe(['mail', 'system'])
        ->and($defs->definition('api.key')->secret)->toBeTrue()
        ->and($defs->definition('api.key')->editable)->toBeFalse()
        ->and($defs->definition('system.logo')->editable)->toBeFalse()
        ->and($defs->definition('install.admin_discord_id')->deletableOnly)->toBeTrue()
        ->and($defs->definition('auth.registration_mode')->options)->toBe(['open', 'code', 'closed'])
        ->and($defs->definition('updates.check_enabled')->default)->toBeFalse()
        ->and($defs->definition('announcements.website_etag'))->toBeNull()
        ->and($defs->definition('org.name'))->toBeNull();
});

it('has a de and en label and hint for every core definition', function () {
    foreach (array_keys(app(SettingDefinitions::class)->all()) as $key) {
        foreach (['de', 'en'] as $locale) {
            foreach (['label', 'hint'] as $part) {
                expect(trans("settings.{$key}.{$part}", locale: $locale))->not->toBe("settings.{$key}.{$part}");
            }
        }
    }
});

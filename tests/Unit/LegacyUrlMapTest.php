<?php

use App\Http\LegacyUrlMap;

it('translates German legacy paths like the legacy UrlMap', function () {
    foreach ([
        ['/benutzer', '/users'],
        ['/benutzer/', '/users/'],
        ['/benutzer/list', '/users/list'],
        ['/kalender/view', '/calendar/view'],
        ['/fahrtenbuch/index', '/logbook/index'],
        ['/antraege/x/y', '/forms/x/y'],
        ['/antrag/create', '/forms/create'],
        ['/benachrichtigungen', '/notifications'],
        ['/mitarbeiter/profile', '/personnel/profile'],
        ['/manv/board/', '/mci/board/'],
        ['/einsatz/list', '/firetab/list'],
        ['/wissensdb/entry', '/lexicon/entry'],
        ['/dokumente/view', '/documents/view'],
        ['/settings/fahrzeuge/defekte/index', '/settings/vehicles/defects/index'],
        ['/settings/personal/qualifw/index', '/settings/personnel/fdskills/index'],
        ['/settings/medikamente', '/settings/medications'],
        ['/api/kalender/events', '/api/calendar/events'],
        ['/api/v1/manv/x', '/api/v1/mci/x'],
        ['/api/v1/mitarbeiter/', '/api/v1/personnel/'],
        ['/benutzer/%C3%A4%2F', '/users/%C3%A4%2F'],
    ] as [$path, $expected]) {
        expect(LegacyUrlMap::translate($path))->toBe($expected);
    }
});

it('returns null for paths that need no translation', function () {
    foreach (['', '/', '/users', '/users/list', '/calendar/', '/api/calendar/x', '/api/v1', '/settings', '/settings/vehicles', '/api/benutzer', '/x/benutzer', '/index.php'] as $path) {
        expect(LegacyUrlMap::translate($path))->toBeNull();
    }
});

it('exposes the top segments of the legacy map', function () {
    expect(LegacyUrlMap::topSegments())->toHaveCount(11)
        ->toMatchArray(['benutzer' => 'users', 'einsatz' => 'firetab', 'manv' => 'mci']);
});

it('only redirects to internal absolute paths', function () {
    foreach (LegacyUrlMap::redirects() as $from => [$to, $status]) {
        expect($from)->toStartWith('/')
            ->and($to)->toMatch('#^/(?![/\\\\])#')
            ->and($status)->toBeIn([301, 302, 308]);
    }
});

it('answers /logout with 302 and the other legacy pages with 301', function () {
    $redirects = LegacyUrlMap::redirects();
    expect($redirects['/logout'])->toBe(['/login', 302])->and($redirects['/index'])->toBe(['/', 301]);
});

it('only redirects id paths to internal absolute paths', function () {
    expect(LegacyUrlMap::patternRedirects())->not->toBeEmpty();
    foreach (LegacyUrlMap::patternRedirects() as [, $status, $sample]) {
        [$to] = LegacyUrlMap::redirectFor($sample) ?? ['(no match)'];
        expect($sample)->toStartWith('/')
            ->and($to)->toMatch('#^/(?![/\\\\])#')
            ->and($status)->toBeIn([301, 302, 308]);
    }
});

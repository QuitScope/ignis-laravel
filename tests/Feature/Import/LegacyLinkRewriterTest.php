<?php

use App\Import\LegacyLinkRewriter;

function rewriter(): LegacyLinkRewriter
{
    return new LegacyLinkRewriter(['3' => 30], ['5' => 50], ['8' => 80], ['123456' => '123456', 'ABC' => '777777'], ['4' => 40], mailMessages: ['12' => 120]);
}

it('strips the configured legacy base path only as a whole leading segment', function () {
    config(['ignis.import.legacy_base_path' => '/intra/']);
    expect(LegacyLinkRewriter::withoutBasePath('/intra/inbox'))->toBe('/inbox')
        ->and(LegacyLinkRewriter::withoutBasePath('/intra'))->toBe('/')
        ->and(LegacyLinkRewriter::withoutBasePath('/intra?x=1'))->toBe('/?x=1')
        ->and(LegacyLinkRewriter::withoutBasePath('/intranet/x'))->toBe('/intranet/x')
        ->and(LegacyLinkRewriter::withoutBasePath('/intra//evil'))->toBe('//evil')
        ->and(LegacyLinkRewriter::withoutBasePath('/other/intra/x'))->toBe('/other/intra/x');

    config(['ignis.import.legacy_base_path' => 'intra']);
    expect(LegacyLinkRewriter::withoutBasePath('/intra/inbox'))->toBe('/inbox');

    config(['ignis.import.legacy_base_path' => '']);
    expect(LegacyLinkRewriter::withoutBasePath('/intra/inbox'))->toBe('/intra/inbox');
    config(['ignis.import.legacy_base_path' => '/']);
    expect(LegacyLinkRewriter::withoutBasePath('/intra/inbox'))->toBe('/intra/inbox');
});

it('rewrites known legacy notification links and drops everything that is not an internal path', function () {
    foreach ([
        'none' => [null, null, 'none'],
        'empty' => ['  ', null, 'none'],
        'defects with base path' => ['/intra/settings/vehicles/defects/index?vehicle=3', '/vehicles/defects?vehicle=30', 'rewritten'],
        'defects without base path' => ['/settings/vehicles/defects/index?vehicle=3&status=open', '/vehicles/defects?vehicle=30', 'rewritten'],
        'defects german' => ['/intra/settings/fahrzeuge/defekte/index.php?vehicle=3', '/vehicles/defects?vehicle=30', 'rewritten'],
        'defects unmapped vehicle' => ['/intra/settings/vehicles/defects/index?vehicle=9', '/vehicles/defects', 'unmapped'],
        'defects no vehicle' => ['/intra/settings/vehicles/defects/index', '/vehicles/defects', 'rewritten'],
        'vehicle edit' => ['/settings/vehicles/vehicles/3/edit', '/vehicles/30/edit', 'rewritten'],
        'vehicle unmapped' => ['/settings/vehicles/vehicles/9', '/vehicles', 'unmapped'],
        'vehicle list' => ['/settings/fahrzeuge/fahrzeuge/index', '/vehicles', 'rewritten'],
        'profile' => ['/intra/personnel/profile?id=5', '/personnel/50', 'rewritten'],
        'profile german' => ['/mitarbeiter/profile.php?id=5', '/personnel/50', 'rewritten'],
        'profile unmapped' => ['/intra/personnel/profile?id=6', '/personnel', 'unmapped'],
        'profile array id' => ['/intra/personnel/profile?id[]=5', '/personnel', 'unmapped'],
        'document view' => ['/intra/personnel/document-view?docid=1234567', '/personnel/document-view?docid=1234567', 'rewritten'],
        'german document view' => ['/mitarbeiter/dokument-view?docid=ABCD-EFGH-JKLM', '/personnel/document-view?docid=ABCD-EFGH-JKLM', 'rewritten'],
        'docredir' => ['/intra/assets/functions/docredir.php?docid=1234567', '/personnel/document-view?docid=1234567', 'rewritten'],
        'document view bad docid' => ['/intra/personnel/document-view?docid=%3Cscript%3E', null, 'dropped'],
        'inbox' => ['/intra/inbox', '/inbox', 'rewritten'],
        'notifications index' => ['/intra/notifications/index.php', '/inbox', 'rewritten'],
        'benachrichtigungen' => ['/benachrichtigungen/index.php', '/inbox', 'rewritten'],
        'form view' => ['/intra/forms/view?antrag=123456', '/forms/123456', 'rewritten'],
        'form admin view php' => ['/forms/admin/view.php?antrag=123456', '/forms/admin/123456', 'rewritten'],
        'form new public id' => ['/antrag/view?antrag=ABC', '/forms/777777', 'rewritten'],
        'form admin german' => ['/antraege/admin/view?antrag=ABC#x', '/forms/admin/777777', 'rewritten'],
        'form unmapped' => ['/intra/forms/admin/view?antrag=7', '/forms/admin', 'unmapped'],
        'form unmapped view' => ['/forms/view?antrag[]=123456', '/forms', 'unmapped'],
        'form select' => ['/forms/select', '/forms', 'rewritten'],
        'form create' => ['/antrag/create?typ=2', '/forms', 'rewritten'],
        'form admin list' => ['/forms/admin/list.php', '/forms/admin', 'rewritten'],
        'own applications kept' => ['/me/applications', '/me/applications', 'kept'],
        'firetab unmapped' => ['/intra/firetab/view?id=4', '/firetab/list', 'unmapped'],
        'mail message (legacy notification key)' => ['/intra/mail/inbox/12', '/mail/inbox/120', 'rewritten'],
        'mail message without base path' => ['/mail/inbox/12', '/mail/inbox/120', 'rewritten'],
        'mail message other folder, query dropped' => ['/intra/mail/archive/12?postfach=3#x', '/mail/archive/120', 'rewritten'],
        'mail message trailing slash' => ['/mail/sent/12/', '/mail/sent/120', 'rewritten'],
        'mail message unmapped' => ['/intra/mail/inbox/13', '/mail', 'unmapped'],
        'mail message bad id' => ['/mail/inbox/12x', '/mail/inbox/12x', 'kept'],
        'mail message unknown folder' => ['/mail/spam/12', '/mail/spam/12', 'kept'],
        'mail preview fragment' => ['/intra/mail/drafts/12/preview.php', '/mail/drafts/120', 'rewritten'],
        'mail compose kept' => ['/mail/compose/reply/12', '/mail/compose/reply/12', 'kept'],
        'enotf admin list' => ['/enotf/admin/list?x=1', '/enotf/admin', 'rewritten'],
        'enotf page kept' => ['/enotf/fahrzeuginfo?x=1', '/enotf/fahrzeuginfo?x=1', 'kept'],
        'kb entry' => ['/intra/lexicon/view?id=8', '/lexicon/80', 'rewritten'],
        'kb entry php' => ['/lexicon/view.php?id=8#teil', '/lexicon/80', 'rewritten'],
        'kb entry german' => ['/wissensdb/view?id=8', '/lexicon/80', 'rewritten'],
        'kb edit' => ['/lexicon/edit?id=8', '/lexicon/80/edit', 'rewritten'],
        'kb entry unmapped' => ['/lexicon/view?id=9', '/lexicon', 'unmapped'],
        'kb entry bad id' => ['/lexicon/view?id=8x', '/lexicon', 'unmapped'],
        'kb list' => ['/intra/lexicon/index.php', '/lexicon', 'rewritten'],
        'kb taxonomy' => ['/lexicon/manage-taxonomy', '/lexicon/taxonomy', 'rewritten'],
        'calendar kept' => ['/calendar/event?id=1', '/calendar/event?id=1', 'kept'],
        'calendar event' => ['/intra/calendar/view?id=4', '/calendar?event=40', 'rewritten'],
        'calendar event german php' => ['/kalender/view.php?id=4#x', '/calendar?event=40', 'rewritten'],
        'calendar event unmapped' => ['/calendar/view?id=5', '/calendar', 'unmapped'],
        'calendar event bad id' => ['/calendar/view?id[]=4', '/calendar', 'unmapped'],
        'calendar page' => ['/intra/calendar/', '/calendar', 'rewritten'],
        'calendar create' => ['/kalender/create', '/calendar?new=1', 'rewritten'],
        'javascript' => ['javascript:alert(1)', null, 'dropped'],
        'protocol relative' => ['//evil.example/x', null, 'dropped'],
        'protocol relative after base' => ['/intra//evil.example/x', null, 'dropped'],
        'backslash' => ['/\\evil.example', null, 'dropped'],
        'absolute url' => ['https://evil.example/', null, 'dropped'],
        'relative' => ['forms/admin/view?antrag=7', null, 'dropped'],
        'whitespace inside' => ['/inbox x', null, 'dropped'],
    ] as [$raw, $link, $outcome]) {
        config(['ignis.import.legacy_base_path' => '/intra/']);

        expect(rewriter()->notificationLink($raw))->toBe([$link, $outcome]);
    }
});

it('rewrites quick link targets like notification links, keeps external ones and prefixes relative paths', function () {
    foreach ([
        'path' => ['/intra/vehicles', '/vehicles', 'kept', false],
        'relative path' => ['vehicles', '/vehicles', 'kept', true],
        'relative module path' => ['enotf/admin/list', '/enotf/admin', 'rewritten', true],
        'relative legacy defects' => ['settings/vehicles/defects/index?vehicle=3', '/vehicles/defects?vehicle=30', 'rewritten', true],
        'legacy profile' => ['/intra/personnel/profile?id=5', '/personnel/50', 'rewritten', false],
        'legacy profile unmapped' => ['/personnel/profile?id=6', '/personnel', 'unmapped', false],
        'legacy calendar event' => ['calendar/view?id=4', '/calendar?event=40', 'rewritten', true],
        'legacy mail message' => ['mail/inbox/12', '/mail/inbox/120', 'rewritten', true],
        'https' => ['https://example.org/x', 'https://example.org/x', 'external', false],
        'mailto' => ['mailto:leitung@example.org', 'mailto:leitung@example.org', 'external', false],
        'placeholder' => ['#', null, 'placeholder', false],
        'anchor' => ['#top', null, 'placeholder', false],
        'query only' => ['?x=1', null, 'placeholder', false],
        'javascript' => ['javascript:alert(1)', null, 'dropped', false],
        'data' => ['data:text/html,x', null, 'dropped', false],
        'dot segment' => ['../etc', null, 'dropped', false],
        'inner dot segment' => ['a/../../x', null, 'dropped', false],
        'backslash' => ['a\\b', null, 'dropped', false],
        'protocol relative after base' => ['/intra//evil.example', null, 'dropped', false],
        'too long' => ['/'.str_repeat('a', 300), null, 'dropped', false],
    ] as [$raw, $url, $outcome, $prefixed]) {
        config(['ignis.import.legacy_base_path' => '/intra/']);

        expect(rewriter()->tileTarget($raw))->toBe([$url, $outcome, $prefixed]);
    }
});

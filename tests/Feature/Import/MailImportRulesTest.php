<?php

use App\Import\MailImporter;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$maps = ['role' => ['1' => 11], 'rank' => ['10' => 20, '12' => 21], 'ems_qualification' => ['31' => 41], 'fire_qualification' => [], 'specialty' => ['40' => 50]];

it('rewrites a dynamic rule to the new ids and drops what was not imported', function () use ($maps) {
    [$rule, $problems] = MailImporter::rewriteRule('{"role_ids":[1,"1",99],"rank_ids":[12,10],"rd_quali_ids":[31],"fw_quali_ids":[21],"fachdienst_ids":["40",0]}', $maps);

    expect($rule)->toBe(['role' => [11], 'rank' => [20, 21], 'ems_qualification' => [41], 'fire_qualification' => [], 'specialty' => [50]])
        ->and($problems)->toBe(['rule ids that were not imported (or invalid) dropped']);
});

it('keeps an empty dynamic rule and says so', function () use ($maps) {
    expect(MailImporter::rewriteRule('{"role_ids":[99]}', $maps)[1])
        ->toBe(['rule ids that were not imported (or invalid) dropped', 'dynamic list with an empty rule kept, it delivers to nobody'])
        ->and(MailImporter::rewriteRule(null, $maps))->toBe([
            ['role' => [], 'rank' => [], 'ems_qualification' => [], 'fire_qualification' => [], 'specialty' => []],
            ['dynamic list with an empty rule kept, it delivers to nobody'],
        ])
        ->and(MailImporter::rewriteRule('kaputt', $maps)[1][0])->toBe('rule unreadable, imported empty');
});

function mir_body(mixed $json, ?string $html = null): array
{
    return MailImporter::body($json, $html,
        fn (string $href) => str_starts_with($href, 'https://alt.example/') ? 'https://neu.example/'.substr($href, 20) : ($href === 'https://weg.example' ? null : $href),
        fn (string $src) => $src === '/alt/badge.png?v=3' ? '/storage/ranks/badge.png' : null);
}

it('takes a valid body with the badge and link hooks applied', function () {
    Rank::factory()->create(['badge' => '/storage/ranks/badge.png']);
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => '/alt/badge.png?v=3', 'alt' => '']],
        ['type' => 'image', 'attrs' => ['src' => '/fremd.png', 'alt' => '']],
        ['type' => 'text', 'text' => 'Wache', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://alt.example/mail/inbox/5', 'target' => '_blank']]]],
        ['type' => 'text', 'text' => ' weg', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://weg.example']]]],
    ]]]];

    [$body, $problems] = mir_body(json_encode($doc), '<p>alt</p>');

    expect($body['content'][0]['content'])->toBe([
        ['type' => 'image', 'attrs' => ['src' => '/storage/ranks/badge.png', 'alt' => '']],
        ['type' => 'text', 'text' => 'Wache', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://neu.example/mail/inbox/5']]]],
        ['type' => 'text', 'text' => ' weg', 'marks' => [['type' => 'bold']]],
    ])->and($problems)->toBe(['image that is no rank badge of this instance removed', 'link without a usable target removed, text kept']);
});

it('falls back to paragraphs of the legacy html when the validator refuses the body', function () {
    $refused = ['type' => 'doc', 'content' => [['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'X']]]]];

    [$body, $problems] = mir_body(json_encode($refused), '<h2>Lage&nbsp;&amp; Plan</h2><p>Zeile 1<br>Zeile 2</p><script>x</script><p>  </p>');

    expect($body)->toBe(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lage & Plan']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Zeile 1']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Zeile 2']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]],
    ]])->and($problems)->toBe(['content rejected by the mail validator, imported as plain text paragraphs']);
});

it('uses the text of the json for a refused draft without html and never stores invalid json', function () {
    [$body] = mir_body('{"type":"doc","content":[{"type":"table","content":[{"type":"text","text":"Zelle"}]},{"type":"paragraph","attrs":{"x":1},"content":[{"type":"text","text":"Absatz"}]}]}');
    expect($body['content'])->toBe([
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Zelle']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Absatz']]],
    ]);

    expect(mir_body('kein json', null))->toBe([['type' => 'doc', 'content' => []], ['content rejected by the mail validator, imported as plain text paragraphs']])
        ->and(mir_body(null, str_repeat('<p>'.str_repeat('a', 1000).'</p>', 150))[1])->toContain('plain text over 100000 bytes, cut');
});

it('normalizes the legacy header to to/cc/bcc lists of strings and says when something was dropped', function () {
    expect(MailImporter::header('{"to":["a@ignis.ef"],"cc":[],"bcc":["b@ignis.ef"]}'))
        ->toBe([['to' => ['a@ignis.ef'], 'cc' => [], 'bcc' => ['b@ignis.ef']], []])
        ->and(MailImporter::header('{"to":"a@ignis.ef"}'))->toBe([['to' => ['a@ignis.ef'], 'cc' => [], 'bcc' => []], []])
        ->and(MailImporter::header(null))->toBe([['to' => [], 'cc' => [], 'bcc' => []], []]);

    $dropped = ['header entries that were not addresses dropped'];
    expect(MailImporter::header('{"to":["a@ignis.ef",5,null,["x"]],"cc":{"k":"c@ignis.ef"},"bcc":7,"extra":["x"]}'))
        ->toBe([['to' => ['a@ignis.ef'], 'cc' => ['c@ignis.ef'], 'bcc' => []], $dropped])
        ->and(MailImporter::header('kaputt'))->toBe([['to' => [], 'cc' => [], 'bcc' => []], ['header unreadable, imported empty']])
        ->and(MailImporter::header('"nur text"'))->toBe([['to' => [], 'cc' => [], 'bcc' => []], ['header unreadable, imported empty']]);
});

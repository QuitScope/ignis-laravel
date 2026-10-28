<?php

use App\Models\Personnel;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\Specialty;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\SignatureTemplate;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function st_p(array $inline = []): array
{
    return $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];
}

function st_var(string $name, bool $bold = false): array
{
    return ['type' => 'docVariable', 'attrs' => ['name' => $name]] + ($bold ? ['marks' => [['type' => 'bold']]] : []);
}

function st_doc(array ...$inline): string
{
    return (string) json_encode(['type' => 'doc', 'content' => [st_p($inline)]]);
}

function st_parseError(mixed $raw): string
{
    try {
        SignatureTemplate::parse($raw);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('accepted');
}

it('turns a legacy plain text signature into a template', function () {
    expect(SignatureTemplate::decode(''))->toBeNull()
        ->and(SignatureTemplate::decode("  \n "))->toBeNull()
        ->and(SignatureTemplate::decode("Mit Gruß\r\n\r\n  Wache 1 "))->toBe(['type' => 'doc', 'content' => [
            st_p([['type' => 'text', 'text' => 'Mit Gruß']]), st_p(), st_p([['type' => 'text', 'text' => 'Wache 1']]),
        ]]);
    $doc = ['type' => 'doc', 'content' => [st_p([st_var('absender.name')])]];
    expect(SignatureTemplate::decode((string) json_encode($doc)))->toBe($doc);
});

it('checks nodes, placeholders, marks and size when saving a template', function () {
    $fromEditor = ['type' => 'doc', 'content' => array_map(fn (array $p) => ['type' => 'paragraph', 'attrs' => ['textAlign' => null]] + $p, SignatureTemplate::builtIn()['content'])];
    expect(SignatureTemplate::parse((string) json_encode($fromEditor)))->toBe(SignatureTemplate::builtIn());

    $own = SignatureTemplate::parse(st_doc(
        ['type' => 'text', 'text' => 'Gruß, ', 'marks' => [['type' => 'italic']]], st_var('absender.name', true), ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'Wache', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org', 'class' => 'x']]]],
    ));
    expect(json_encode($own, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->toBe(
        '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Gruß, ","marks":[{"type":"italic"}]},{"type":"docVariable","attrs":{"name":"absender.name"},"marks":[{"type":"bold"}]},{"type":"hardBreak"},{"type":"text","text":"Wache","marks":[{"type":"link","attrs":{"href":"https://example.org"}}]}]}]}',
    );

    expect(st_parseError(st_doc(st_var('absender.passwort'))))->toBe(__('mail.signature.unknown_variable', ['name' => 'absender.passwort']))
        ->and(st_parseError(st_doc(['type' => 'image', 'attrs' => ['src' => '/a.png']])))->toBe(__('mail.signature.not_allowed'))
        ->and(st_parseError(st_doc(['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'code']]])))->toBe(__('mail.signature.marks'))
        ->and(st_parseError(st_doc(['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]])))->toBe(__('mail.content.link'))
        ->and(st_parseError((string) json_encode(['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => []]]])))->toBe(__('mail.signature.paragraphs_only'))
        ->and(st_parseError('{kaputt'))->toBe(__('mail.signature.invalid'))
        ->and(st_parseError("{\"type\":\"doc\",\"content\":[{\"type\":\"paragraph\",\"content\":[{\"type\":\"text\",\"text\":\"Gru\xC3\x28\"}]}]}"))->toBe(__('mail.signature.invalid'))
        ->and(st_parseError(['type' => 'doc']))->toBe(__('mail.signature.invalid'))
        ->and(st_parseError(st_doc(['type' => 'text', 'text' => str_repeat('a', SignatureTemplate::MAX_BYTES)])))->toBe(__('mail.signature.too_large', ['max' => 16]));
});

it('stores exactly one default template, none when it is empty or the built-in one', function () {
    $own = ['type' => 'doc', 'content' => [st_p([st_var('absender.name')])]];

    SignatureTemplate::saveDefault($own);
    SignatureTemplate::saveDefault($own);
    expect(MailSignature::query()->whereNull('mailbox_id')->count())->toBe(1)->and(SignatureTemplate::default())->toBe($own);

    SignatureTemplate::saveDefault(SignatureTemplate::builtIn());
    expect(MailSignature::query()->whereNull('mailbox_id')->count())->toBe(0)->and(SignatureTemplate::default())->toBe(SignatureTemplate::builtIn());

    SignatureTemplate::saveDefault($own);
    SignatureTemplate::saveDefault(['type' => 'doc', 'content' => [st_p()]]);
    expect(MailSignature::query()->whereNull('mailbox_id')->count())->toBe(0);
});

it('fills in placeholders and drops empty lines', function () {
    $rank = Rank::factory()->create(['name' => 'Branddirektor*in', 'name_male' => 'Branddirektor', 'name_female' => 'Branddirektorin', 'badge' => '/assets/img/dienstgrade/bf/1.png']);
    $person = Personnel::factory()->create(['full_name' => 'Anna Muster', 'gender' => 1, 'extra_qualification' => null, 'service_number' => 'BF-17', 'rank_id' => $rank->id]);
    app(Settings::class)->set('organisation.type', 'Berufsfeuerwehr');
    app(Settings::class)->set('organisation.city', 'Musterstadt');

    $content = SignatureTemplate::resolve(['type' => 'doc', 'content' => [
        st_p([st_var('absender.name', true)]),
        st_p([st_var('absender.dienstgrad', true)]),
        st_p([st_var('absender.position')]),
        st_p([st_var('absender.fachdienste'), ['type' => 'text', 'text' => ' ']]),
        st_p([['type' => 'text', 'text' => 'Position: '], st_var('absender.position')]),
        st_p(),
        st_p([st_var('absender.dienstnummer'), ['type' => 'text', 'text' => ' · '], st_var('absender.mailadresse')]),
        st_p([st_var('organisation')]),
    ]], $person, 'a.muster@ignis.ef')['content'];

    expect($content[0]['content'])->toBe([['type' => 'text', 'text' => 'Anna Muster', 'marks' => [['type' => 'bold']]]])
        ->and($content[1]['content'][0])->toBe(['type' => 'image', 'attrs' => ['src' => '/assets/img/dienstgrade/bf/1.png', 'alt' => '']])
        ->and($content[1]['content'][1])->toBe(['type' => 'text', 'text' => 'Branddirektorin', 'marks' => [['type' => 'bold']]])
        ->and($content[2]['content'])->toBe([['type' => 'text', 'text' => 'Position: ']])
        ->and($content[3])->toBe(['type' => 'paragraph'])
        ->and(implode('', array_column($content[4]['content'], 'text')))->toBe('BF-17 · a.muster@ignis.ef')
        ->and($content[5]['content'][0]['text'])->toBe('Berufsfeuerwehr Musterstadt')
        ->and($content)->toHaveCount(6);

    $rank->forceFill(['badge' => 'https://example.org/abzeichen.png'])->save();
    $person->refresh();
    expect(SignatureTemplate::resolve(['type' => 'doc', 'content' => [st_p([st_var('absender.dienstgrad')])]], $person, '')['content'][0]['content'])
        ->toBe([['type' => 'text', 'text' => 'Branddirektorin']]);

    $person->specialties()->attach([
        Specialty::query()->create(['number' => 20, 'name' => 'Taucher', 'is_disabled' => false])->id,
        Specialty::query()->create(['number' => 10, 'name' => 'Höhenrettung', 'is_disabled' => false])->id,
        Specialty::query()->create(['number' => 30, 'name' => 'Alt', 'is_disabled' => true])->id,
    ]);
    expect(SignatureTemplate::values($person, '')['absender.fachdienste'][0]['text'])->toBe('Höhenrettung, Taucher');

    expect(SignatureTemplate::resolve(['type' => 'doc', 'content' => [st_p([st_var('absender.name')]), st_p([st_var('absender.mailadresse')])]], null, 'leitstelle@ignis.ef')['content'])
        ->toBe([st_p([['type' => 'text', 'text' => 'leitstelle@ignis.ef']])]);
});

it('drops an empty line inside a paragraph together with its break', function () {
    $break = ['type' => 'hardBreak'];
    expect(SignatureTemplate::resolve(['type' => 'doc', 'content' => [st_p([
        st_var('absender.name'), $break, st_var('postfach.name'), $break, st_var('absender.position'), $break, st_var('absender.mailadresse'),
    ])]], null, 'leitstelle@ignis.ef', 'Leitstelle')['content'])
        ->toBe([st_p([['type' => 'text', 'text' => 'Leitstelle'], $break, ['type' => 'text', 'text' => 'leitstelle@ignis.ef']])]);

    expect(SignatureTemplate::resolve(['type' => 'doc', 'content' => [st_p([['type' => 'text', 'text' => 'Gruß'], $break, $break, st_var('absender.mailadresse')])]], null, 'leitstelle@ignis.ef')['content'])
        ->toBe([st_p([['type' => 'text', 'text' => 'Gruß'], $break, $break, ['type' => 'text', 'text' => 'leitstelle@ignis.ef']])]);

    expect(SignatureTemplate::resolve(['type' => 'doc', 'content' => [st_p([st_var('absender.name'), $break, st_var('absender.position')])]], null, '')['content'])->toBe([]);
});

it('fills the name with the title and the title alone', function () {
    $template = ['type' => 'doc', 'content' => [st_p([st_var('absender.name')]), st_p([st_var('absender.titel')])]];
    $dr = PersonnelTitle::query()->firstOrCreate(['name' => 'Dr.']);
    $doc = SignatureTemplate::resolve($template, Personnel::factory()->create(['full_name' => 'Max Muster', 'title_id' => $dr->id]), 'm.muster@ignis.ef');

    expect($doc['content'][0]['content'][0]['text'])->toBe('Dr. Max Muster')
        ->and($doc['content'][1]['content'][0]['text'])->toBe('Dr.')
        ->and(SignatureTemplate::catalog())->toHaveKey('absender.titel');
});

it('drops the title line without a title', function () {
    $template = ['type' => 'doc', 'content' => [st_p([st_var('absender.name')]), st_p([st_var('absender.titel')])]];
    $doc = SignatureTemplate::resolve($template, Personnel::factory()->create(['full_name' => 'Erika Muster', 'title_id' => null]), 'e.muster@ignis.ef');

    expect($doc['content'])->toHaveCount(1)->and($doc['content'][0]['content'][0]['text'])->toBe('Erika Muster');
});

<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\SignatureTemplate;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ms_doc(array ...$paragraphs): array
{
    return ['type' => 'doc', 'content' => $paragraphs];
}

function ms_var(string $name): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'docVariable', 'attrs' => ['name' => $name]]]];
}

it('shows the placeholders and a preview with the own details', function () {
    $ada = ml_member('Ada Admin');
    $ada['person']->forceFill(['extra_qualification' => 'Wachabteilungsleiterin'])->save();

    $this->actingAs($ada['user'])->get('/mail/signature')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page->component('Mail/Signature')
            ->where('variables', collect(SignatureTemplate::catalog())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all())
            ->where('values', fn ($v) => $v['absender.position'] === 'Wachabteilungsleiterin' && $v['absender.mailadresse'] === $ada['mailbox']->address)
            ->where('hasOwn', false)->where('body', SignatureTemplate::builtIn()));
});

it('passes the preview values as text, never as markup', function () {
    $ada = ml_member('Ada Admin');
    $ada['person']->forceFill(['extra_qualification' => '<img src=x onerror=alert(1)>'])->save();

    $html = $this->actingAs($ada['user'])->get('/mail/signature')->assertOk()->getContent();

    expect($html)->not->toContain('<img src=x onerror=alert(1)>')->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('fills an own signature with placeholders from the profile when composing', function () {
    $alice = ml_member('Alice Absender');
    MailSignature::query()->forceCreate(['mailbox_id' => $alice['mailbox']->id, 'body' => ms_doc(
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gruß, '], ['type' => 'docVariable', 'attrs' => ['name' => 'absender.name']]]],
        ms_var('absender.position'),
        ms_var('gibt.es.nicht'),
    )]);
    $this->actingAs($alice['user']);

    $html = $this->get('/mail/compose')->assertOk()->getContent();
    expect($html)->toContain('Alice Absender')->not->toContain('docVariable')->not->toContain('gibt.es.nicht');

    $alice['person']->forceFill(['extra_qualification' => 'Zugführerin'])->save();
    $this->get('/mail/compose')->assertInertia(fn ($page) => $page->where('body.content.3.content.0.text', 'Zugführerin'));
});

it('shows the default template with its placeholders in the editor without an own one', function () {
    $alice = ml_member('Alice Absender');
    SignatureTemplate::saveDefault(ms_doc(ms_var('absender.name')));

    $this->actingAs($alice['user'])->get('/mail/signature')->assertOk()
        ->assertInertia(fn ($page) => $page->where('body', ms_doc(ms_var('absender.name')))->where('values', fn ($v) => $v['absender.name'] === 'Alice Absender'));
});

it('saves per mailbox, empty means none, and checks the template', function () {
    $alice = ml_member('Alice Absender');
    $this->actingAs($alice['user']);
    $own = ms_doc(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gruß', 'marks' => [['type' => 'bold']]]]], ms_var('absender.name'));

    $this->put('/mail/signature', ['mailbox_id' => $alice['mailbox']->id, 'body_json' => json_encode($own)])
        ->assertRedirect("/mail/signature?mailbox={$alice['mailbox']->id}")->assertSessionHas('status', 'mail.signature.saved');
    expect(MailSignature::query()->where('mailbox_id', $alice['mailbox']->id)->sole()->body)->toBe($own);

    $this->put('/mail/signature', ['mailbox_id' => $alice['mailbox']->id, 'body_json' => json_encode(ms_doc(['type' => 'paragraph']))])->assertRedirect();
    expect(MailSignature::query()->where('mailbox_id', $alice['mailbox']->id)->sole()->body)->toBe(ms_doc());
    $this->get('/mail/compose')->assertInertia(fn ($page) => $page->where('body.content', [['type' => 'paragraph']]));
    $this->get('/mail/signature')->assertInertia(fn ($page) => $page->where('hasOwn', true));

    foreach ([
        json_encode(ms_doc(ms_var('absender.passwort'))),
        json_encode(ms_doc(['type' => 'bulletList', 'content' => []])),
        json_encode(ms_doc(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'underline']]]]])),
        json_encode(ms_doc(['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'https://evil.example/a.png']]]])),
        json_encode(ms_doc(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]])),
        json_encode(ms_doc(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('a', 17000)]]])),
        ['nope'],
    ] as $bad) {
        $this->put('/mail/signature', ['mailbox_id' => $alice['mailbox']->id, 'body_json' => $bad])->assertSessionHasErrors('body_json');
    }
    expect(MailSignature::query()->where('mailbox_id', $alice['mailbox']->id)->sole()->body)->toBe(ms_doc());
});

it('never writes the signature of a mailbox the account may not open', function () {
    $alice = ml_member('Alice Absender', ['mail.use', 'mail.admin']);
    $bob = ml_member('Bob Fremd');
    $group = ml_group('Leitstelle', 'leitstelle');

    foreach ([$bob['mailbox']->id, $group->id] as $foreign) {
        $this->actingAs($alice['user'])->put('/mail/signature', ['mailbox_id' => $foreign, 'body_json' => json_encode(ms_doc())])->assertForbidden();
        $this->get("/mail/signature?mailbox=$foreign")->assertRedirect('/mail');
    }
    expect(MailSignature::query()->count())->toBe(0);
});

it('never falls back to the remembered mailbox when mailbox_id is missing', function () {
    $mia = ml_member('Mia Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user']]);
    $this->actingAs($mia['user'])->get("/mail/signature?mailbox={$group->id}")->assertOk();

    foreach ([[], ['mailbox_id' => ''], ['mailbox_id' => 'x'], ['mailbox_id' => [$group->id]]] as $fields) {
        $this->put('/mail/signature', $fields + ['body_json' => json_encode(ms_doc(ms_var('postfach.name')))])->assertSessionHasErrors('mailbox_id');
    }
    expect(MailSignature::query()->count())->toBe(0);
});

it('edits the signature of a group for its members', function () {
    $mia = ml_member('Mia Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user']]);

    $this->actingAs($mia['user'])->put('/mail/signature', ['mailbox_id' => $group->id, 'body_json' => json_encode(ms_doc(ms_var('postfach.name')))])->assertRedirect();
    expect(MailSignature::query()->where('mailbox_id', $group->id)->sole()->body)->toBe(ms_doc(ms_var('postfach.name')))
        ->and(MailSignature::query()->where('mailbox_id', $mia['mailbox']->id)->exists())->toBeFalse();
});

<?php

use App\Models\Rank;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Models\MailSignature;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    MailSignature::query()->forceCreate(['mailbox_id' => null, 'body' => ['type' => 'doc', 'content' => []]]);
});

function mc_mail(array $test): MailMessage
{
    $carla = ml_member('Carla Kopie');
    $dora = ml_member('Dora Blind');
    $message = ml_message($test['bob']['mailbox'], [[$test['alice']['mailbox'], 'to', 'inbox'], [$carla['mailbox'], 'cc', 'inbox'], [$dora['mailbox'], 'bcc', 'inbox']], 'Dienstplan');
    $message->forceFill([
        'header' => ['to' => [$test['alice']['mailbox']->address], 'cc' => [$carla['mailbox']->address], 'bcc' => [$dora['mailbox']->address]],
        'body' => json_decode(ml_doc('Bitte lesen'), true),
    ])->save();

    return $message;
}

it('opens a new mail without writing anything, with an optional to', function () {
    $this->actingAs($this->alice['user'])->get('/mail/compose?to=Bob@Ignis.EF')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page->component('Mail/Compose')->where('mode', 'new')
            ->where('mailbox.id', $this->alice['mailbox']->id)->where('recipients.to', ['bob@ignis.ef'])
            ->where('draftId', null)->where('inReplyTo', null));

    expect(MailMessage::query()->count())->toBe(0);
});

it('builds reply, reply all and forward from a sent mail with an own copy', function () {
    $message = mc_mail(['alice' => $this->alice, 'bob' => $this->bob]);
    $this->actingAs($this->alice['user']);

    $this->get("/mail/compose/reply/{$message->id}")->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'reply')
        ->where('subject', 'Re: Dienstplan')->where('recipients.to', [$this->bob['mailbox']->address])->where('recipients.cc', [])
        ->where('inReplyTo', $message->id)->where('forwardFrom', null)
        ->where('body.content.1.type', 'blockquote')->where('body.content.1.content.1.content.0.text', 'Bitte lesen'));

    $this->get("/mail/compose/reply-all/{$message->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('recipients.to', [$this->bob['mailbox']->address])->where('recipients.cc', $message->header['cc'])
        ->where('recipients.bcc', []));
    expect($this->get("/mail/compose/reply-all/{$message->id}")->getContent())->not->toContain('dora');

    $this->get("/mail/compose/forward/{$message->id}")->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'forward')
        ->where('subject', 'Fwd: Dienstplan')->where('recipients.to', [])->where('inReplyTo', null)->where('forwardFrom', $message->id));

    expect(MailMessage::query()->count())->toBe(1);
});

it('lets the draft store take the thread from the original, never from the client', function () {
    $message = mc_mail(['alice' => $this->alice, 'bob' => $this->bob]);
    $this->actingAs($this->alice['user']);

    $id = ml_draft($this, ['in_reply_to' => $message->id, 'thread_id' => str_repeat('f', 32)]);

    expect(MailMessage::query()->findOrFail($id)->only('thread_id', 'in_reply_to'))->toBe(['thread_id' => $message->thread_id, 'in_reply_to' => $message->id]);
});

it('answers 404 for drafts, foreign mail and other mailboxes, also for admins and super admins', function (array $perms, bool $super) {
    $message = mc_mail(['alice' => $this->alice, 'bob' => $this->bob]);
    $outsider = ml_member('Otto Aussen', $perms);
    if ($super) {
        $outsider['user']->forceFill(['is_super_admin' => true])->save();
    }
    $this->actingAs($outsider['user']);
    foreach (['reply', 'reply-all', 'forward', 'draft'] as $mode) {
        $this->get("/mail/compose/$mode/{$message->id}")->assertNotFound();
    }

    $this->actingAs($this->alice['user']);
    $draft = ml_draft($this);
    $this->get("/mail/compose/reply/$draft")->assertNotFound();
    $this->get("/mail/compose/draft/{$message->id}")->assertNotFound();
    $this->actingAs($this->bob['user'])->get("/mail/compose/draft/$draft")->assertNotFound();
})->with([
    'mail.use' => [['mail.use'], false],
    'admin' => [['admin'], false],
    'mail.admin' => [['mail.use', 'mail.admin'], false],
    'super admin' => [['mail.use'], true],
]);

it('needs the copy in the chosen mailbox', function () {
    $mia = ml_member('Mia Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user']]);
    $message = ml_message($this->bob['mailbox'], [[$group, 'to', 'inbox']]);

    $this->actingAs($mia['user'])->get("/mail/compose/reply/{$message->id}")->assertNotFound();
    $this->get("/mail/compose/reply/{$message->id}?mailbox={$group->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('mailbox.id', $group->id));
});

it('opens an own draft as saved, with bcc and without adding the signature again', function () {
    MailSignature::query()->forceCreate(['mailbox_id' => $this->alice['mailbox']->id, 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gruß']]]]]]);
    $this->actingAs($this->alice['user']);
    $id = ml_draft($this, ['subject' => 'Entwurf', 'bcc' => ['geheim@ignis.ef']]);

    $this->get("/mail/compose/draft/$id")->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'draft')->where('draftId', $id)
        ->where('subject', 'Entwurf')->where('recipients.bcc', ['geheim@ignis.ef'])->where('body', json_decode(ml_doc('Hallo'), true)));
});

it('sends to the compose page only with a mailbox and the mail permission', function () {
    $this->actingAs(ml_user())->get('/mail/compose')->assertRedirect('/mail');
    $this->actingAs(ml_member('Ohne Recht', [])['user'])->get('/mail/compose')->assertForbidden();
    $this->actingAs($this->alice['user'])->get('/mail/compose?mailbox=999999')->assertRedirect('/mail');
});

it('puts the signature below the first line and before the quote', function () {
    Rank::query()->update(['badge' => null]);
    MailSignature::query()->forceCreate(['mailbox_id' => $this->alice['mailbox']->id, 'body' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gruß, '], ['type' => 'docVariable', 'attrs' => ['name' => 'absender.name']]]],
    ]]]);
    $message = mc_mail(['alice' => $this->alice, 'bob' => $this->bob]);
    $this->actingAs($this->alice['user']);

    $this->get('/mail/compose')->assertInertia(fn ($page) => $page->where('body.content', [
        ['type' => 'paragraph'], ['type' => 'paragraph'],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gruß, '], ['type' => 'text', 'text' => 'Alice Absender']]],
    ]));
    $this->get("/mail/compose/reply/{$message->id}")->assertInertia(fn ($page) => $page->has('body.content', 5)
        ->where('body.content.2.content.1.text', 'Alice Absender')->where('body.content.3', ['type' => 'paragraph'])->where('body.content.4.type', 'blockquote'));
});

it('caps quote chains in replies so long chains stay storable', function () {
    RateLimiter::for('mail-drafts', fn () => Limit::none());
    $this->actingAs($this->alice['user']);
    $id = ml_send($this, ['subject' => 'Kette', 'to' => [$this->bob['mailbox']->address], 'body_json' => ml_doc('Anfang')])->json('messageId');

    for ($i = 1; $i <= 60; $i++) {
        $doc = $this->get("/mail/compose/reply/$id")->assertOk()->viewData('page')['props']['body'];
        $doc['content'][0]['content'] = [['type' => 'text', 'text' => "Antwort $i"]];
        $draft = ml_draft($this, ['subject' => 'Re: Kette', 'body_json' => json_encode($doc), 'in_reply_to' => $id, 'to' => [$this->bob['mailbox']->address]]);
        $this->postJson("/mail/drafts/$draft/send", [])->assertOk();
        $id = $draft;
    }

    $last = MailMessage::query()->findOrFail($id);
    $depth = function (array $node) use (&$depth): int {
        $inner = 0;
        foreach ($node['content'] ?? [] as $child) {
            $inner = max($inner, $depth($child));
        }

        return $inner + ($node['type'] === 'blockquote' ? 1 : 0);
    };
    $text = json_encode($last->body, JSON_UNESCAPED_UNICODE);
    expect($depth($last->body))->toBe(10)
        ->and($text)->toContain(__('mail.quote.capped'))->toContain('Antwort 51')->not->toContain('Antwort 49')
        ->and($last->search_text)->toContain('Antwort 60');
});

<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailMessage;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    $this->alice = ml_member('Alice Absender');
    $this->actingAs($this->alice['user']);
});

it('delivers per role, files the sender copy and notifies every recipient account once', function () {
    $bob = ml_member('Bob Empfang');
    $carla = ml_member('Carla Kopie');
    $dora = ml_member('Dora Blind');

    $id = ml_draft($this, ['to' => [$bob['mailbox']->address]]);
    expect(ml_deliveries($id))->toBe([[$this->alice['mailbox']->id, 'sender', 'drafts']]);

    $response = $this->postJson("/mail/drafts/$id/send", [
        'subject' => 'Dienstplan', 'body_json' => ml_doc('Bitte lesen'),
        'to' => [$bob['mailbox']->address, 'niemand@ignis.ef'], 'cc' => [$carla['mailbox']->address], 'bcc' => [$dora['mailbox']->address],
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('messageId'))->toBe($id)
        ->and($response->json('unresolvedAddresses'))->toBe(['niemand@ignis.ef'])
        ->and(ml_deliveries($id))->toBe(collect([
            [$this->alice['mailbox']->id, 'sender', 'sent'], [$bob['mailbox']->id, 'to', 'inbox'],
            [$carla['mailbox']->id, 'cc', 'inbox'], [$dora['mailbox']->id, 'bcc', 'inbox'],
        ])->sortBy(fn ($r) => $r[0])->values()->all());

    $message = MailMessage::query()->findOrFail($id);
    expect($message->status)->toBe('sent')->and($message->sent_at)->not->toBeNull()
        ->and($message->sent_by_user_id)->toBe($this->alice['user']->id)
        ->and($message->subject)->toBe('Dienstplan')
        ->and($message->body)->toBe(json_decode(ml_doc('Bitte lesen'), true))
        ->and($message->search_text)->toContain('Dienstplan')
        ->and($message->header)->toBe(['to' => [$bob['mailbox']->address, 'niemand@ignis.ef'], 'cc' => [$carla['mailbox']->address], 'bcc' => [$dora['mailbox']->address]])
        ->and($message->deliveries()->whereNull('sorted_at')->count())->toBe(0);

    expect(ml_notified($id))->toBe(collect([$bob, $carla, $dora])->pluck('user.id')->sort()->values()->all());

    $before = ml_deliveries($id);
    $this->postJson("/mail/drafts/$id/send", ['to' => [$bob['mailbox']->address]])->assertStatus(409);
    expect(ml_deliveries($id))->toBe($before)->and(ml_notified($id))->toHaveCount(3);
});

it('files mail to oneself in the inbox and in sent', function () {
    $id = ml_send($this, ['to' => [$this->alice['mailbox']->address]])->json('messageId');

    expect(ml_deliveries($id))->toBe([[$this->alice['mailbox']->id, 'sender', 'sent'], [$this->alice['mailbox']->id, 'to', 'inbox']])
        ->and(ml_notified($id))->toBe([]);
});

it('sends the saved draft when no fields are posted', function () {
    $bob = ml_member('Bob Empfang');
    $id = ml_draft($this, ['subject' => 'Gespeichert', 'to' => [$bob['mailbox']->address]]);

    $this->postJson("/mail/drafts/$id/send", [])->assertOk();

    expect(MailMessage::query()->findOrFail($id)->subject)->toBe('Gespeichert')
        ->and(ml_deliveries($id))->toContain([$bob['mailbox']->id, 'to', 'inbox']);
});

it('saves a draft in place and only for its owner', function () {
    $id = ml_draft($this);
    $this->putJson("/mail/drafts/$id", ['subject' => 'Neu', 'body_json' => ml_doc('Zwei')])->assertOk()->assertJson(['messageId' => $id]);
    expect(MailMessage::query()->findOrFail($id)->subject)->toBe('Neu')
        ->and(MailMessage::query()->findOrFail($id)->search_text)->toBe("Neu\nZwei")
        ->and(MailMessage::query()->where('sender_mailbox_id', $this->alice['mailbox']->id)->count())->toBe(1);

    $mallory = ml_member('Mallory Fremd');
    $this->actingAs($mallory['user']);
    $this->putJson("/mail/drafts/$id", ['subject' => 'Gekapert'])->assertNotFound();
    $this->postJson("/mail/drafts/$id/send", ['to' => [$mallory['mailbox']->address]])->assertNotFound();
    expect(MailMessage::query()->findOrFail($id)->subject)->toBe('Neu');
});

it('refuses an autosave that arrives after the send, also with a stale bound draft', function () {
    $bob = ml_member('Bob Empfang');
    $id = ml_draft($this, ['to' => [$bob['mailbox']->address]]);
    $stale = MailMessage::query()->findOrFail($id);
    $this->postJson("/mail/drafts/$id/send", ['subject' => 'Gesendet', 'body_json' => ml_doc('Text')])->assertOk();
    $sent = MailMessage::query()->findOrFail($id);
    $sortedAt = fn () => $sent->deliveries()->orderBy('id')->pluck('sorted_at')->map(fn ($t) => (string) $t)->all();
    $before = [$sortedAt(), $sent->only(['subject', 'body', 'header', 'search_text', 'sent_by_user_id', 'status'])];

    $this->travel(5)->minutes();
    app('router')->bind('message', fn () => $stale);
    $this->putJson("/mail/drafts/$id", [
        'subject' => 'Autosave', 'body_json' => ml_doc('Alt'), 'to' => ['x@ignis.ef'],
    ])->assertStatus(409)->assertJsonPath('message', __('mail.send.already_sent'));

    $after = MailMessage::query()->findOrFail($id);
    expect([$sortedAt(), $after->only(['subject', 'body', 'header', 'search_text', 'sent_by_user_id', 'status'])])->toBe($before);
});

it('enforces the limits for subject, body and recipients', function () {
    $id = ml_draft($this);

    $this->putJson("/mail/drafts/$id", ['subject' => str_repeat('ä', 256)])->assertStatus(422);
    $this->putJson("/mail/drafts/$id", ['subject' => str_repeat('ä', 255)])->assertOk();
    $this->putJson("/mail/drafts/$id", ['body_json' => str_repeat('x', 200_001)])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '200'));
    $this->putJson("/mail/drafts/$id", ['body_json' => '{"type":"doc"'])->assertStatus(422);
    $this->putJson("/mail/drafts/$id", ['body_json' => '{"type":"script"}'])->assertStatus(422);

    $many = array_map(fn (int $i) => "x$i@ignis.ef", range(1, 101));
    $this->postJson("/mail/drafts/$id/send", ['to' => $many])->assertStatus(422);

    $this->postJson("/mail/drafts/$id/send", ['subject' => '', 'to' => [$this->alice['mailbox']->address]])->assertStatus(422);
    expect(MailMessage::query()->findOrFail($id)->status)->toBe('draft');
});

it('refuses arrays where text belongs', function () {
    $id = ml_draft($this);

    $this->putJson("/mail/drafts/$id", ['subject' => ['x']])->assertStatus(422);
    $this->putJson("/mail/drafts/$id", ['body_json' => ['type' => 'doc']])->assertStatus(422);
    $this->putJson("/mail/drafts/$id", ['to' => 'a@ignis.ef'])->assertStatus(422);
    $this->putJson("/mail/drafts/$id", ['to' => [['a@ignis.ef']]])->assertStatus(422);
    $this->postJson('/mail/drafts', ['in_reply_to' => ['1']])->assertStatus(422);
});

it('sends nothing without a deliverable recipient and names the unknown addresses', function () {
    $id = ml_draft($this);

    $this->postJson("/mail/drafts/$id/send", ['subject' => 'Leer', 'to' => [], 'cc' => [], 'bcc' => []])
        ->assertStatus(422)->assertJsonPath('message', __('mail.send.no_recipients'));

    $bob = ml_member('Bob Gesperrt');
    $bob['mailbox']->forceFill(['is_locked' => true])->save();
    $message = $this->postJson("/mail/drafts/$id/send", ['subject' => 'Leer', 'to' => [$bob['mailbox']->address, 'niemand@ignis.ef']])
        ->assertStatus(422)->json('message');
    expect($message)->toContain('nicht zustellbar')->toContain($bob['mailbox']->address)->toContain('niemand@ignis.ef');

    MailList::query()->forceCreate(['address' => 'leer@ignis.ef', 'name' => 'Leer']);
    $this->postJson("/mail/drafts/$id/send", ['subject' => 'Leer', 'to' => ['leer@ignis.ef']])
        ->assertStatus(422)->assertJsonPath('message', __('mail.send.empty_lists'));

    expect(MailMessage::query()->findOrFail($id)->status)->toBe('draft')->and(ml_deliveries($id))->toHaveCount(1);
});

it('refuses too deeply nested text', function () {
    $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'tief']]];
    for ($i = 0; $i < 15; $i++) {
        $node = ['type' => 'blockquote', 'content' => [$node]];
    }

    $this->postJson('/mail/drafts', ['subject' => 'Tief', 'body_json' => json_encode(['type' => 'doc', 'content' => [$node]])])
        ->assertStatus(422)->assertJsonPath('message', __('mail.body.too_deep'));
    expect(MailMessage::query()->count())->toBe(0);
});

it('counts the raw recipients before any other check', function () {
    $id = ml_draft($this);
    $many = array_merge([['kein text']], array_fill(0, 100, 'x@ignis.ef'));

    $this->putJson("/mail/drafts/$id", ['to' => $many])->assertStatus(422)
        ->assertJsonPath('message', __('mail.send.too_many_raw', ['max' => 100]));
});

it('limits the resolved deliveries', function () {
    $list = MailList::query()->forceCreate(['address' => 'alle@ignis.ef', 'name' => 'Alle']);
    Mailbox::query()->insert(array_map(fn (int $i) => ['address' => "viele$i@ignis.ef", 'display_name' => "Viele $i", 'domain' => 'ignis.ef', 'kind' => 'group'], range(0, 500)));
    $list->mailboxes()->attach(Mailbox::query()->where('address', 'like', 'viele%')->pluck('id'));

    $this->postJson('/mail/drafts/'.ml_draft($this).'/send', ['subject' => 'Rundmail', 'to' => ['alle@ignis.ef']])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '500'));
});

it('does nothing without the right or without an open mailbox', function () {
    $this->actingAs(ml_user([]))->postJson('/mail/drafts', ['subject' => 'x'])->assertForbidden();

    $noRight = ml_member('Ohne Recht', []);
    $this->actingAs($noRight['user'])->postJson('/mail/drafts', ['subject' => 'x'])->assertForbidden();

    $this->alice['mailbox']->forceFill(['is_locked' => true])->save();
    $this->actingAs($this->alice['user'])->postJson('/mail/drafts', ['subject' => 'x'])->assertForbidden();
    expect(MailMessage::query()->count())->toBe(0);
});

// ---- group mailboxes, chosen mailbox, lists ----

it('lets members send as the group, records who wrote and notifies the other members only', function () {
    $max = ml_member('Max Mitglied');
    $otto = ml_member('Otto Ohne');
    $group = ml_group('Leitstelle', 'leitstelle', [$this->alice['user'], $max['user'], $otto['user']]);
    $otto['user']->forceFill(['is_active' => false])->save();
    $bob = ml_member('Bob Empfang');

    $id = ml_draft($this, ['mailbox_id' => $group->id, 'to' => [$bob['mailbox']->address, 'leitstelle@ignis.ef']]);
    $this->postJson("/mail/drafts/$id/send", ['mailbox_id' => $group->id])->assertOk();

    $message = MailMessage::query()->findOrFail($id);
    expect($message->sender_mailbox_id)->toBe($group->id)
        ->and($message->sent_by_user_id)->toBe($this->alice['user']->id)
        ->and(ml_deliveries($id))->toBe([[$group->id, 'sender', 'sent'], [$group->id, 'to', 'inbox'], [$bob['mailbox']->id, 'to', 'inbox']])
        ->and(ml_notified($id))->toBe(collect([$max['user']->id, $bob['user']->id])->sort()->values()->all());

    $title = $bob['user']->notifications()->sole()->data['title'];
    expect($title)->toBe('Neue Mail von Leitstelle');
});

it('refuses a foreign mailbox_id with 403 and never sends from another mailbox', function () {
    $bob = ml_member('Bob Empfang');
    $foreign = ml_group('Leitstelle', 'leitstelle', [$bob['user']]);

    $this->postJson('/mail/drafts', ['mailbox_id' => $foreign->id, 'subject' => 'x'])->assertForbidden();
    $this->postJson('/mail/drafts', ['mailbox_id' => $bob['mailbox']->id, 'subject' => 'x'])->assertForbidden();
    expect(MailMessage::query()->count())->toBe(0);

    $mine = ml_group('Wache', 'wache', [$this->alice['user']]);
    $id = ml_draft($this, ['to' => [$bob['mailbox']->address]]);
    $this->postJson("/mail/drafts/$id/send", ['mailbox_id' => $mine->id])->assertNotFound();
    $this->postJson("/mail/drafts/$id/send", ['mailbox_id' => $foreign->id])->assertForbidden();
    expect(MailMessage::query()->findOrFail($id)->status)->toBe('draft');
});

it('lets only list managers write to a managers-only list, in to, cc and bcc alike', function () {
    $bob = ml_member('Bob Empfang');
    $list = MailList::query()->forceCreate(['address' => 'leitung@ignis.ef', 'name' => 'Geheime Runde', 'senders' => 'managers']);
    $list->mailboxes()->attach($bob['mailbox']->id);

    foreach (['to', 'cc', 'bcc'] as $field) {
        $message = $this->postJson('/mail/drafts/'.ml_draft($this).'/send', [$field => ['Leitung@ignis.ef']])->assertStatus(422)->json('message');
        expect($message)->toContain('leitung@ignis.ef')->not->toContain('Geheime Runde');
    }

    $replyAll = ml_draft($this, ['to' => [$bob['mailbox']->address, 'leitung@ignis.ef']]);
    $this->postJson("/mail/drafts/$replyAll/send", [])->assertStatus(422);

    $manager = ml_member('Mona Manager', ['mail.use', 'mail.lists.manage']);
    $this->actingAs($manager['user']);
    $id = ml_draft($this, ['to' => ['leitung@ignis.ef']]);
    $this->postJson("/mail/drafts/$id/send", [])->assertOk();
    expect(ml_deliveries($id))->toContain([$bob['mailbox']->id, 'to', 'inbox']);
});

it('sets thread and reply only from a sent original the mailbox takes part in', function () {
    $bob = ml_member('Bob Empfang');
    $eve = ml_member('Eve Fremd');
    $original = ml_message($bob['mailbox'], [[$this->alice['mailbox'], 'to', 'inbox']], 'Frage');

    $id = ml_draft($this, ['in_reply_to' => $original->id, 'thread_id' => 'f00', 'to' => [$bob['mailbox']->address]]);
    $draft = MailMessage::query()->findOrFail($id);
    expect($draft->thread_id)->toBe($original->thread_id)->and($draft->in_reply_to)->toBe($original->id);

    $fresh = MailMessage::query()->findOrFail(ml_draft($this));
    expect($fresh->thread_id)->toMatch('/^[0-9a-f]{32}$/')->not->toBe($original->thread_id)->and($fresh->in_reply_to)->toBeNull();

    $this->actingAs($eve['user']);
    $this->postJson('/mail/drafts', ['in_reply_to' => $original->id])->assertNotFound();
    $this->postJson('/mail/drafts', ['forward_from' => $original->id])->assertNotFound();
    $this->actingAs($this->alice['user']);
    $this->postJson('/mail/drafts', ['in_reply_to' => $id])->assertNotFound();
    expect(MailMessage::query()->where('sender_mailbox_id', $eve['mailbox']->id)->count())->toBe(0);
});

it('throttles draft writes per account (mail-drafts, 30 a minute)', function () {
    $id = ml_draft($this);
    foreach (range(2, 30) as $_) {
        $this->putJson("/mail/drafts/$id", ['subject' => 'x'])->assertOk();
    }
    $this->putJson("/mail/drafts/$id", ['subject' => 'x'])->assertStatus(429);

    $this->actingAs(ml_member('Bob Eigen')['user'])->postJson('/mail/drafts', ['subject' => 'x'])->assertCreated();
});

it('shows admins and super admins a 404 on foreign drafts', function (Closure $make) {
    $id = ml_draft($this, ['to' => [$this->alice['mailbox']->address]]);
    $viewer = $make();
    $own = ml_provision(ml_person('Vera Viewer'));
    $viewer->forceFill(['personnel_id' => $own->personnel_id])->save();

    $this->actingAs($viewer->fresh());
    $this->putJson("/mail/drafts/$id", ['subject' => 'Gekapert'])->assertNotFound();
    $this->postJson("/mail/drafts/$id/send", ['to' => [$own->address]])->assertNotFound();
    $this->postJson('/mail/drafts', ['in_reply_to' => $id])->assertNotFound();
    expect(MailMessage::query()->findOrFail($id)->subject)->toBe('Betreff')->and(MailMessage::query()->findOrFail($id)->status)->toBe('draft');
})->with([
    'admin' => [fn () => ml_user(['admin'])],
    'super admin' => [fn () => User::factory()->superAdmin()->create()],
    'mail.admin' => [fn () => ml_user(['mail.use', 'mail.admin', 'mail.lists.manage', 'mail.domain.choose'])],
]);

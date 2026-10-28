<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\MailList;

require_once __DIR__.'/Mail/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->alice = ml_member('Alice Alt');
    $this->group = ml_group('Wache', 'wache');
    $this->message = ml_message($this->alice['mailbox'], [[$this->group, 'to', 'inbox']]);
    $this->list = MailList::query()->forceCreate(['address' => 'alle@ignis.ef', 'name' => 'Alle']);
    $this->attachment = Attachment::query()->forceCreate(['message_id' => $this->message->id, 'disk' => 'mail-attachments',
        'path' => 'mail-1-0123456789abcdef.txt', 'original_name' => 'a.txt', 'mime' => 'text/plain', 'size' => 1]);
    foreach ([['mail_message', 501, $this->message->id], ['mail_mailbox', 7, $this->group->id], ['mail_mailbox', 8, $this->alice['mailbox']->id],
        ['mail_list', 31, $this->list->id], ['mail_attachment', 61, $this->attachment->id]] as [$entity, $legacy, $new]) {
        DB::table('legacy_id_map')->insert(['entity' => $entity, 'legacy_id' => (string) $legacy, 'new_id' => $new]);
    }
    $this->actingAs($this->alice['user']);
});

it('redirects legacy mail pages with ids to the imported records', function () {
    foreach ([
        'message' => ['/mail/inbox/501?postfach=7', fn ($t) => "/mail/inbox/{$t->message->id}?mailbox={$t->group->id}"],
        'other folder' => ['/mail/archive/501?postfach=8', fn ($t) => "/mail/archive/{$t->message->id}?mailbox={$t->alice['mailbox']->id}"],
        'php marker' => ['/mail/sent/501.php', fn ($t) => "/mail/sent/{$t->message->id}"],
        'unknown message' => ['/mail/inbox/999?postfach=7', fn ($t) => "/mail/inbox?mailbox={$t->group->id}"],
        'unknown mailbox' => ['/mail/inbox/501?postfach=999', fn ($t) => "/mail/inbox/{$t->message->id}"],
        'reply' => ['/mail/compose/reply/501?postfach=7', fn ($t) => "/mail/compose/reply/{$t->message->id}?mailbox={$t->group->id}"],
        'reply all' => ['/mail/compose/reply-all/501?postfach=7', fn ($t) => "/mail/compose/reply-all/{$t->message->id}?mailbox={$t->group->id}"],
        'forward' => ['/mail/compose/forward/501?postfach=7', fn ($t) => "/mail/compose/forward/{$t->message->id}?mailbox={$t->group->id}"],
        'draft' => ['/mail/compose/draft/501?postfach=7', fn ($t) => "/mail/compose/draft/{$t->message->id}?mailbox={$t->group->id}"],
        'compose unknown' => ['/mail/compose/reply/999?postfach=7', fn ($t) => "/mail?mailbox={$t->group->id}"],
        'attachment' => ['/mail/attachments/61.php', fn ($t) => "/mail/attachments/{$t->attachment->id}"],
        'attachment unknown' => ['/mail/attachments/62.php', fn ($t) => '/mail'],
        'list' => ['/mail/lists/31/edit?postfach=7', fn ($t) => "/mail/lists/{$t->list->id}/edit?mailbox={$t->group->id}"],
        'list unknown' => ['/mail/lists/32/edit.php', fn ($t) => '/mail/lists'],
        'mailbox admin' => ['/settings/mail/mailboxes/7/edit.php', fn ($t) => "/settings/mail/mailboxes/{$t->group->id}/edit"],
        'mailbox admin unknown' => ['/settings/mail/mailboxes/99/edit.php', fn ($t) => '/settings/mail/mailboxes'],
        'folder keeps path' => ['/mail/trash?postfach=7', fn ($t) => "/mail/trash?mailbox={$t->group->id}"],
        'start keeps path' => ['/mail?postfach=8', fn ($t) => "/mail?mailbox={$t->alice['mailbox']->id}"],
        'compose keeps path' => ['/mail/compose?postfach=7', fn ($t) => "/mail/compose?mailbox={$t->group->id}"],
        'signature keeps path' => ['/mail/signature?postfach=7', fn ($t) => "/mail/signature?mailbox={$t->group->id}"],
        'settings keeps path' => ['/settings/mail/mailboxes?postfach=7', fn ($t) => "/settings/mail/mailboxes?mailbox={$t->group->id}"],
    ] as [$legacy, $expected]) {
        $this->get($legacy)->assertStatus(301)->assertRedirect($expected($this));
    }
});

it('leaves new-instance urls to the live routes', function () {
    $this->get('/mail/inbox/501')->assertNotFound();
    $this->get('/mail/inbox?mailbox='.$this->alice['mailbox']->id)->assertOk();
    $this->get("/mail/sent/{$this->message->id}")->assertOk();
});

it('has no preview fragment and no legacy post endpoints', function () {
    $this->get("/mail/inbox/{$this->message->id}/preview")->assertNotFound();
    $this->get('/mail/inbox/501/preview?postfach=7')->assertNotFound();
    foreach (['/mail/messages/501/move', '/mail/messages/501/read', '/mail/messages/501/flag', '/mail/messages/501/delete',
        '/mail/attachments/61/delete', '/mail/lists/31/delete', '/settings/mail/mailboxes/7/unlock', '/mail/signature', "/mail/drafts/{$this->message->id}"] as $path) {
        expect($this->post($path)->status())->toBeIn([404, 405], $path);
    }
});

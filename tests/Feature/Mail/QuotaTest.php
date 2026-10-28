<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentStorage;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    Storage::fake(AttachmentStorage::DISK);
    $this->alice = ml_member('Alice Absender');
    $this->actingAs($this->alice['user']);
});

function mq_used(Mailbox $mailbox, int $bytes): void
{
    $old = ml_message($mailbox, []);
    Attachment::query()->forceCreate(['message_id' => $old->id, 'disk' => AttachmentStorage::DISK, 'path' => "mail-{$old->id}-0123456789abcdef.txt",
        'original_name' => 'alt.txt', 'mime' => 'text/plain', 'size' => $bytes]);
}

it('refuses an upload beyond 200 MB per mailbox without naming a path', function () {
    mq_used($this->alice['mailbox'], AttachmentStorage::MAILBOX_QUOTA_BYTES - 10);
    $id = ml_draft($this);

    ml_attach($this, $id, '1234567890', 'passt.txt')->assertCreated();
    $res = ml_attach($this, $id, 'zu viel', 'zuviel.txt')->assertUnprocessable();

    expect($res->json('message'))->toBe(__('mail.attachment.quota', ['max' => 200]))
        ->and($res->getContent())->not->toContain('mail-attachments')->not->toContain('storage')
        ->and(Attachment::query()->where('message_id', $id)->count())->toBe(1)
        ->and(Storage::disk(AttachmentStorage::DISK)->allFiles())->toHaveCount(1);
});

it('counts per mailbox: another mailbox and received mail do not count', function () {
    $bob = ml_member('Bob Empfang');
    mq_used($bob['mailbox'], AttachmentStorage::MAILBOX_QUOTA_BYTES);
    $received = ml_message($bob['mailbox'], [[$this->alice['mailbox'], 'to', 'inbox']]);
    Attachment::query()->forceCreate(['message_id' => $received->id, 'disk' => AttachmentStorage::DISK, 'path' => "mail-{$received->id}-0123456789abcdef.txt",
        'original_name' => 'b.txt', 'mime' => 'text/plain', 'size' => 100]);

    ml_attach($this, ml_draft($this), 'frei', 'frei.txt')->assertCreated();

    $this->actingAs($bob['user']);
    ml_attach($this, ml_draft($this), 'voll', 'voll.txt')->assertUnprocessable();
});

it('counts the group mailbox on its own and keeps forward copies inside the quota', function () {
    $group = ml_group('Leitstelle', 'leitstelle', [$this->alice['user']]);
    mq_used($this->alice['mailbox'], AttachmentStorage::MAILBOX_QUOTA_BYTES);
    ml_attach($this, ml_draft($this, ['mailbox_id' => $group->id]), 'gruppe', 'g.txt', ['mailbox_id' => $group->id])->assertCreated();

    $bob = ml_member('Bob Empfang');
    $sent = ml_message($bob['mailbox'], [[$this->alice['mailbox'], 'to', 'inbox']]);
    Storage::disk(AttachmentStorage::DISK)->put("mail-{$sent->id}-aaaaaaaaaaaaaaaa.txt", 'Anlage');
    Attachment::query()->forceCreate(['message_id' => $sent->id, 'disk' => AttachmentStorage::DISK, 'path' => "mail-{$sent->id}-aaaaaaaaaaaaaaaa.txt",
        'original_name' => 'anlage.txt', 'mime' => 'text/plain', 'size' => 6]);
    $forward = (int) $this->postJson('/mail/drafts', ['forward_from' => $sent->id])->assertCreated()->json('messageId');

    expect(Attachment::query()->where('message_id', $forward)->count())->toBe(0)
        ->and(MailMessage::query()->findOrFail($forward)->status)->toBe('draft');
});

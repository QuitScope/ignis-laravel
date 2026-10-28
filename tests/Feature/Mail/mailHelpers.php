<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\MailboxProvisioner;
use Tests\TestCase;

function ml_person(string $name, array $over = []): Personnel
{
    return Personnel::factory()->create($over + ['full_name' => $name]);
}

function ml_user(array $perms = ['mail.use'], ?Personnel $person = null, array $over = []): User
{
    $role = Role::create(['name' => 'M'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create($over + ['personnel_id' => $person?->id]);
}

function ml_provision(Personnel $person): Mailbox
{
    $mailbox = app(MailboxProvisioner::class)->sync($person->id);
    expect($mailbox)->not->toBeNull();

    return $mailbox;
}

/** @return array{user: User, person: Personnel, mailbox: Mailbox} account with record and bound mailbox */
function ml_member(string $name, array $perms = ['mail.use']): array
{
    $person = ml_person($name);
    $user = ml_user($perms, $person);

    return ['user' => $user, 'person' => $person, 'mailbox' => ml_provision($person)->refresh()];
}

/** @param list<User> $members */
function ml_group(string $name, string $local, array $members = [], array $over = []): Mailbox
{
    $mailbox = Mailbox::query()->forceCreate($over + ['kind' => Mailbox::GROUP, 'address' => "$local@ignis.ef", 'display_name' => $name, 'domain' => 'ignis.ef']);
    $mailbox->members()->attach(collect($members)->pluck('id'));

    return $mailbox->refresh();
}

function ml_message(Mailbox $from, array $copies, string $subject = 'Betreff'): MailMessage
{
    $message = MailMessage::query()->forceCreate([
        'sender_mailbox_id' => $from->id, 'subject' => $subject, 'thread_id' => bin2hex(random_bytes(16)),
        'status' => 'sent', 'sent_at' => now(), 'header' => ['to' => [], 'cc' => [], 'bcc' => []],
    ]);
    foreach ([[$from, 'sender', 'sent'], ...$copies] as [$mailbox, $role, $folder]) {
        MailDelivery::query()->forceCreate(['message_id' => $message->id, 'mailbox_id' => $mailbox->id, 'role' => $role, 'folder' => $folder, 'sorted_at' => now()]);
    }

    return $message;
}

function ml_doc(string $text): string
{
    return (string) json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]);
}

function ml_draft(TestCase $test, array $fields = []): int
{
    return (int) $test->postJson('/mail/drafts', $fields + ['subject' => 'Betreff', 'body_json' => ml_doc('Hallo')])
        ->assertCreated()->json('messageId');
}

function ml_send(TestCase $test, array $fields): TestResponse
{
    return $test->postJson('/mail/drafts/'.ml_draft($test).'/send', $fields + ['subject' => 'Betreff', 'body_json' => ml_doc('Hallo')])->assertOk();
}

function ml_upload_file(string $contents, string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function ml_attach(TestCase $test, int $draftId, string $contents, string $name, array $fields = []): TestResponse
{
    return $test->post("/mail/drafts/$draftId/attachments", $fields + ['file' => ml_upload_file($contents, $name)], ['Accept' => 'application/json']);
}

/** @return list<array{int, string, string}> [mailbox_id, role, folder] of a message, by mailbox and role */
function ml_deliveries(int $messageId): array
{
    return MailDelivery::query()->where('message_id', $messageId)->orderBy('mailbox_id')->orderBy('role')->get()
        ->map(fn (MailDelivery $d) => [$d->mailbox_id, $d->role, $d->folder])->all();
}

/** @return list<int> accounts with a bell entry of kind mail for this message, sorted */
function ml_notified(int $messageId): array
{
    return DB::table('notifications')->where('type', 'mail')->where('data->link', "/mail/inbox/$messageId")
        ->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
}

<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Http\Controllers\AttachmentController;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentRefused;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\Mailer;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

const MLA_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    Storage::fake(AttachmentStorage::DISK);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    $this->actingAs($this->alice['user']);
});

function mla_path(int $id): ?string
{
    return app(AttachmentStorage::class)->absolutePath(Attachment::query()->findOrFail($id));
}

it('uploads, serves only participants and freezes a sent attachment', function () {
    $eve = ml_member('Eve Fremd', ['mail.use', 'admin']);
    $id = ml_draft($this);
    $res = ml_attach($this, $id, "Dienstplan\nMontag", "Plan \"März\"\r\n.txt")->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $attachmentId = (int) $res->json('attachment.id');
    expect(Attachment::query()->findOrFail($attachmentId)->original_name)->toBe('Plan "März".txt')
        ->and($res->json('attachment'))->toBe(['id' => $attachmentId, 'name' => 'Plan "März".txt', 'size' => 17]);

    $this->postJson("/mail/drafts/$id/send", ['to' => [$this->bob['mailbox']->address]])->assertOk();

    $this->actingAs($this->bob['user']);
    $download = $this->get("/mail/attachments/$attachmentId")->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Disposition', 'attachment; filename="Plan _M_rz_.txt"; filename*=UTF-8\'\'Plan%20%22M%C3%A4rz%22.txt');
    expect(file_get_contents($download->baseResponse->getFile()->getPathname()))->toBe("Dienstplan\nMontag")
        ->and($download->headers->get('Content-Type'))->toStartWith('text/plain');

    $this->actingAs($eve['user']);
    $this->get("/mail/attachments/$attachmentId")->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs($this->bob['user']);
    $this->deleteJson("/mail/messages/$id")->assertOk();
    $this->get("/mail/attachments/$attachmentId")->assertNotFound();

    $this->actingAs($this->alice['user']);
    $this->deleteJson("/mail/attachments/$attachmentId")->assertNotFound();
    expect(Attachment::query()->find($attachmentId))->not->toBeNull()->and(mla_path($attachmentId))->not->toBeNull();
});

it('takes the type from the magic bytes and keeps 10 MB per mail', function () {
    $id = ml_draft($this);

    ml_attach($this, $id, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'bild.png')->assertUnprocessable();
    ml_attach($this, $id, '<!DOCTYPE html><html><body>x</body></html>', 'seite.txt')->assertUnprocessable();

    $chunk = str_repeat("Zeile mit Text\n", (int) (4 * 1024 * 1024 / 15));
    ml_attach($this, $id, $chunk, 'a.txt')->assertCreated();
    ml_attach($this, $id, $chunk, 'b.txt')->assertCreated();
    $third = ml_attach($this, $id, $chunk, 'c.txt')->assertUnprocessable();
    expect($third->json('message'))->toContain('10 MB')
        ->and(Attachment::query()->where('message_id', $id)->count())->toBe(2)
        ->and(Storage::disk(AttachmentStorage::DISK)->allFiles())->toHaveCount(2);
});

it('copies attachments on forward and removes the files with a discarded draft', function () {
    $id = ml_draft($this);
    ml_attach($this, $id, 'Anlage', 'anlage.txt')->assertCreated();
    $this->postJson("/mail/drafts/$id/send", ['to' => [$this->bob['mailbox']->address]])->assertOk();

    $this->actingAs($this->bob['user']);
    $this->get("/mail/compose/forward/$id")->assertOk()->assertInertia(fn ($p) => $p->where('forwardNames', ['anlage.txt']));
    $res = $this->postJson('/mail/drafts', ['forward_from' => $id, 'subject' => 'Fwd: Betreff'])->assertCreated();
    $forward = (int) $res->json('messageId');
    $copy = Attachment::query()->where('message_id', $forward)->firstOrFail();
    $original = Attachment::query()->where('message_id', $id)->firstOrFail();
    expect($copy->original_name)->toBe('anlage.txt')
        ->and($copy->path)->not->toBe($original->path)
        ->and($copy->path)->toMatch('/^mail-'.$forward.'-[0-9a-f]{16}\.txt$/')
        ->and($res->json('attachments'))->toBe([['id' => $copy->id, 'name' => 'anlage.txt', 'size' => 6]]);

    $copyPath = mla_path($copy->id);
    expect($copyPath)->not->toBeNull();
    $this->deleteJson("/mail/messages/$forward")->assertOk();
    expect(file_exists($copyPath))->toBeFalse()
        ->and(mla_path($original->id))->not->toBeNull()
        ->and(MailMessage::query()->find($forward))->toBeNull()
        ->and(ml_deliveries($forward))->toBe([]);

    $this->actingAs(ml_member('Eve Fremd')['user']);
    $this->postJson('/mail/drafts', ['forward_from' => $id])->assertNotFound();
    expect(Attachment::query()->count())->toBe(1);
});

it('copies forwarded attachments only up to 10 MB and skips missing files', function () {
    $sent = ml_message($this->bob['mailbox'], [[$this->alice['mailbox'], 'to', 'inbox']]);
    $disk = Storage::disk(AttachmentStorage::DISK);
    foreach ([['a', 6 * 1024 * 1024, false], ['b', 3 * 1024 * 1024, true], ['c', 3 * 1024 * 1024, false], ['d', 2 * 1024 * 1024, false]] as [$n, $size, $missing]) {
        $path = "mail-{$sent->id}-".str_repeat($n, 16).'.txt';
        $missing || $disk->put($path, str_repeat('x', $size));
        Attachment::query()->forceCreate(['message_id' => $sent->id, 'disk' => AttachmentStorage::DISK, 'path' => $path, 'original_name' => "$n.txt", 'mime' => 'text/plain', 'size' => $size, 'missing' => $missing]);
    }

    $this->get("/mail/compose/forward/{$sent->id}")->assertInertia(fn ($p) => $p->where('forwardNames', ['a.txt', 'c.txt']));
    $forward = (int) $this->postJson('/mail/drafts', ['forward_from' => $sent->id])->assertCreated()->json('messageId');

    expect(Attachment::query()->where('message_id', $forward)->orderBy('id')->pluck('original_name')->all())->toBe(['a.txt', 'c.txt']);
});

it('takes no attachment after the send, also not from a stale draft', function () {
    $id = ml_draft($this);
    $stale = MailMessage::query()->findOrFail($id);
    $this->postJson("/mail/drafts/$id/send", ['to' => [$this->bob['mailbox']->address]])->assertOk();

    ml_attach($this, $id, 'zu spät', 'nachtrag.txt')->assertNotFound();
    expect(fn () => app(AttachmentStorage::class)->store($stale, ml_upload_file('zu spät', 'nachtrag.txt')))
        ->toThrow(AttachmentRefused::class, __('mail.attachment.sent'));
    expect(Attachment::query()->where('message_id', $id)->count())->toBe(0)
        ->and(Storage::disk(AttachmentStorage::DISK)->allFiles())->toBe([]);
});

it('waits for the message lock: a send committed during the upload wins', function () {
    $id = ml_draft($this);
    $done = false;
    DB::listen(function ($q) use (&$done, $id) {
        if (! $done && str_contains($q->sql, 'mail_messages') && str_contains($q->sql, 'exists')) {
            $done = true;
            app(Mailer::class)->send(MailMessage::query()->findOrFail($id), $this->alice['mailbox'], $this->alice['user'], 'Betreff',
                ['type' => 'doc', 'content' => []], ['to' => [$this->bob['mailbox']->address], 'cc' => [], 'bcc' => []]);
        }
    });

    ml_attach($this, $id, 'zu spät', 'nachtrag.txt')->assertStatus(409)->assertJsonPath('message', __('mail.attachment.sent'));

    expect($done)->toBeTrue()->and(MailMessage::query()->findOrFail($id)->status)->toBe('sent')
        ->and(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk(AttachmentStorage::DISK)->allFiles())->toBe([]);
});

it('names the file after the detected type', function () {
    $id = ml_draft($this);
    $png = (string) base64_decode(MLA_PNG);
    $cases = [
        ["start calc\r\nexit\r\n", 'Wachplan.bat', 'Wachplan.txt'],
        ['Nur Text', 'LIESMICH', 'LIESMICH.txt'],
        ['Nur Text', 'notiz.TXT', 'notiz.TXT'],
        [$png, 'bild.txt', 'bild.png'],
        [$png, '.exe', __('mail.attachment.fallback_name').'.png'],
        ['Nur Text', 'rechnung.pdf.exe', 'rechnung.pdf.txt'],
        ['Nur Text', "bild.png\0.html", 'bild.png.txt'],
    ];
    $ids = [];
    foreach ($cases as [$contents, $name, $expected]) {
        $res = ml_attach($this, $id, $contents, $name)->assertCreated();
        expect($res->json('attachment.name'))->toBe($expected);
        $ids[] = (int) $res->json('attachment.id');
    }
    foreach (Attachment::query()->where('message_id', $id)->get() as $a) {
        expect($a->path)->toMatch('/^mail-'.$id.'-[0-9a-f]{16}\.(txt|png)$/')->and($a->disk)->toBe(AttachmentStorage::DISK);
    }

    $this->postJson("/mail/drafts/$id/send", ['to' => [$this->bob['mailbox']->address]])->assertOk();
    $this->actingAs($this->bob['user']);
    expect($this->get("/mail/attachments/{$ids[0]}")->headers->get('Content-Disposition'))->toStartWith('attachment; filename="Wachplan.txt"');

    Attachment::query()->whereKey($ids[0])->update(['original_name' => 'Wachplan.bat']);
    expect($this->get("/mail/attachments/{$ids[0]}")->headers->get('Content-Disposition'))->toStartWith('attachment; filename="Wachplan.txt"');
    expect($this->get("/mail/attachments/{$ids[3]}")->assertOk()->headers->get('Content-Type'))->toBe('image/png');
});

it('discards a draft only while it is one: a send committed meanwhile keeps the delivered mail', function () {
    $id = ml_draft($this, ['to' => [$this->bob['mailbox']->address]]);
    $a = (int) ml_attach($this, $id, 'Anlage', 'anlage.txt')->json('attachment.id');
    $path = mla_path($a);
    $done = false;
    DB::listen(function ($q) use (&$done, $id) {
        if (! $done && str_contains($q->sql, 'mail_deliveries') && str_contains($q->sql, '!=')) {
            $done = true;
            app(Mailer::class)->send(MailMessage::query()->findOrFail($id), $this->alice['mailbox'], $this->alice['user'], 'Betreff',
                ['type' => 'doc', 'content' => []], ['to' => [$this->bob['mailbox']->address], 'cc' => [], 'bcc' => []]);
        }
    });

    $this->deleteJson("/mail/messages/$id")->assertOk();

    expect($done)->toBeTrue()
        ->and(MailMessage::query()->findOrFail($id)->status)->toBe('sent')
        ->and(MailMessage::query()->findOrFail($id)->deliveries()->where('mailbox_id', $this->bob['mailbox']->id)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(MailMessage::query()->findOrFail($id)->deliveries()->where('mailbox_id', $this->alice['mailbox']->id)->value('deleted_at'))->not->toBeNull()
        ->and(file_exists($path))->toBeTrue();
    $this->actingAs($this->bob['user']);
    $this->get("/mail/attachments/$a")->assertOk();
});

it('strips C1 controls and bidi/format characters from names and refuses a NUL in a stored path', function () {
    $id = ml_draft($this);
    expect(ml_attach($this, $id, 'Nur Text', "rechnung\u{202E}fdp.exe")->assertCreated()->json('attachment.name'))->toBe('rechnungfdp.txt')
        ->and(ml_attach($this, $id, 'Nur Text', "a\u{0085}b\u{200B}c.txt")->assertCreated()->json('attachment.name'))->toBe('abc.txt')
        ->and(AttachmentStorage::sanitizeName("\u{202E}\u{0090}"))->toBe(__('mail.attachment.fallback_name'));

    $row = Attachment::query()->where('message_id', $id)->firstOrFail();
    expect(mla_path($row->id))->not->toBeNull();
    $row->forceFill(['path' => $row->path."\0.php"])->save();
    expect(mla_path($row->id))->toBeNull();
    $this->get("/mail/attachments/{$row->id}")->assertNotFound();
});

it('builds the file name after RFC 6266', function () {
    expect(AttachmentController::contentDisposition('aßb.pdf'))->toBe('attachment; filename="a_b.pdf"; filename*=UTF-8\'\'a%C3%9Fb.pdf')
        ->and(AttachmentController::contentDisposition('a"b\\c.txt'))->toBe('attachment; filename="a_b_c.txt"; filename*=UTF-8\'\'a%22b%5Cc.txt');
});

it('refuses oversized, empty, array and missing uploads with a message but no path', function () {
    $id = ml_draft($this);

    $big = ml_attach($this, $id, str_repeat('a', AttachmentStorage::MAX_FILE_BYTES + 1), 'gross.txt')->assertUnprocessable();
    expect($big->json('message'))->toContain('5 MB');
    $this->post("/mail/drafts/$id/attachments", [], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/mail/drafts/$id/attachments", ['file' => 'text'], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/mail/drafts/$id/attachments", ['file' => [ml_upload_file('a', 'a.txt'), ml_upload_file('b', 'b.txt')]], ['Accept' => 'application/json'])->assertUnprocessable();
    ml_attach($this, $id, '', 'leer.txt')->assertUnprocessable();

    foreach ([$big] as $res) {
        expect($res->getContent())->not->toContain('storage')->not->toContain('mail-attachments');
    }
    expect(Attachment::query()->count())->toBe(0)->and(Storage::disk(AttachmentStorage::DISK)->allFiles())->toBe([]);
});

it('attaches only to the own draft of the chosen mailbox', function (Closure $make) {
    $id = ml_draft($this);
    $other = $make($this);
    $this->actingAs($other);

    ml_attach($this, $id, 'fremd', 'fremd.txt')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    ml_attach($this, 999999, 'fremd', 'fremd.txt')->assertNotFound();
    expect(Attachment::query()->count())->toBe(0);
})->with([
    'mail.use' => [fn () => ml_member('Eve Fremd')['user']],
    'admin' => [fn () => ml_member('Eve Admin', ['mail.use', 'admin'])['user']],
    'super admin' => [fn () => User::factory()->superAdmin()->create()],
    'mail.admin' => [fn () => ml_member('Eve Verwaltung', ['mail.use', 'mail.admin'])['user']],
    'recipient' => [fn ($t) => $t->bob['user']],
]);

it('deletes an attachment only from the own draft, with its file', function () {
    $id = ml_draft($this);
    $a = (int) ml_attach($this, $id, 'weg', 'weg.txt')->json('attachment.id');
    $b = (int) ml_attach($this, $id, 'bleibt', 'bleibt.txt')->json('attachment.id');
    $path = mla_path($a);

    $this->actingAs(User::factory()->superAdmin()->create());
    $this->deleteJson("/mail/attachments/$a")->assertNotFound();
    $this->actingAs(ml_member('Eve Fremd')['user']);
    $this->deleteJson("/mail/attachments/$a")->assertNotFound();
    expect(file_exists($path))->toBeTrue();

    $this->actingAs($this->alice['user']);
    $this->deleteJson("/mail/attachments/$a", ['mailbox_id' => $this->alice['mailbox']->id])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(Attachment::query()->find($a))->toBeNull()->and(file_exists($path))->toBeFalse()->and(mla_path($b))->not->toBeNull();
    $this->deleteJson("/mail/attachments/$a")->assertNotFound();
});

it('lets a group member attach to and clean up the group draft', function () {
    $carl = ml_member('Carl Kollege');
    $group = ml_group('Leitstelle', 'leitstelle', [$this->alice['user'], $carl['user']]);
    $id = ml_draft($this, ['mailbox_id' => $group->id]);
    $a = (int) ml_attach($this, $id, 'gruppe', 'gruppe.txt', ['mailbox_id' => $group->id])->assertCreated()->json('attachment.id');

    ml_attach($this, $id, 'x', 'x.txt', ['mailbox_id' => $this->alice['mailbox']->id])->assertNotFound();

    $this->actingAs($carl['user']);
    $this->get("/mail/attachments/$a")->assertOk();
    $this->deleteJson("/mail/attachments/$a", ['mailbox_id' => $group->id])->assertOk();
    expect(Attachment::query()->count())->toBe(0);
});

it('serves a download only to participants with a copy left', function (string $who, int $status) {
    $carl = ml_member('Carl Kopie');
    $dora = ml_member('Dora Blind');
    $gina = ml_member('Gina Gruppe');
    $group = ml_group('Wache', 'wache', [$gina['user']]);
    $id = ml_draft($this);
    $a = (int) ml_attach($this, $id, 'Inhalt', 'inhalt.txt')->json('attachment.id');
    $this->postJson("/mail/drafts/$id/send", [
        'to' => [$this->bob['mailbox']->address, $group->address], 'cc' => [$carl['mailbox']->address], 'bcc' => [$dora['mailbox']->address],
    ])->assertOk();
    $deleted = ml_member('Dirk Geloescht');
    MailMessage::query()->findOrFail($id)->deliveries()->forceCreate(['mailbox_id' => $deleted['mailbox']->id, 'role' => 'to', 'folder' => 'inbox', 'deleted_at' => now()]);

    $users = [
        'sender' => $this->alice['user'], 'to' => $this->bob['user'], 'cc' => $carl['user'], 'bcc' => $dora['user'], 'group member' => $gina['user'],
        'other user' => ml_member('Eve Fremd')['user'], 'admin' => ml_member('Adam Admin', ['mail.use', 'admin'])['user'],
        'super admin' => User::factory()->superAdmin()->create(), 'mail.admin' => ml_member('Mia Verwaltung', ['mail.use', 'mail.admin'])['user'],
        'deleted copy' => $deleted['user'],
    ];
    $this->actingAs($users[$who]);
    $res = $this->get("/mail/attachments/$a")->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private');
    if ($status === 200) {
        expect($res->headers->get('Content-Disposition'))->toStartWith('attachment;')->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
})->with([
    ['sender', 200], ['to', 200], ['cc', 200], ['bcc', 200], ['group member', 200],
    ['other user', 404], ['admin', 404], ['super admin', 404], ['mail.admin', 404], ['deleted copy', 404],
]);

it('answers 404 for a missing file or a row marked missing, and never leaves the disk', function () {
    $id = ml_draft($this);
    $a = (int) ml_attach($this, $id, 'da', 'da.txt')->json('attachment.id');
    $row = Attachment::query()->findOrFail($a);
    $stored = $row->path;
    $this->get("/mail/attachments/$a")->assertOk();

    $row->forceFill(['missing' => true])->save();
    $this->get("/mail/attachments/$a")->assertNotFound();

    $row->forceFill(['missing' => false, 'path' => '../../../.env'])->save();
    $this->get("/mail/attachments/$a")->assertNotFound();

    Storage::disk(AttachmentStorage::DISK)->delete($stored);
    $row->forceFill(['path' => $stored])->save();
    $this->get("/mail/attachments/$a")->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    expect(Attachment::query()->find($a))->not->toBeNull();
});

it('throttles uploads with the drafts limiter', function () {
    $id = ml_draft($this);
    for ($i = 0; $i < 29; $i++) {
        ml_attach($this, $id, "Text $i", "t$i.txt")->assertCreated();
    }
    ml_attach($this, $id, 'zu viel', 'x.txt')->assertStatus(429);
});

it('shows the attachments in the reading pane and the draft form', function () {
    $id = ml_draft($this);
    $a = (int) ml_attach($this, $id, 'Inhalt', 'inhalt.txt')->json('attachment.id');

    $this->get("/mail/compose/draft/$id")->assertInertia(fn ($p) => $p->where('attachments', [['id' => $a, 'name' => 'inhalt.txt', 'size' => 6]]));
    $this->postJson("/mail/drafts/$id/send", ['to' => [$this->bob['mailbox']->address]])->assertOk();

    $this->actingAs($this->bob['user']);
    $this->get("/mail/inbox/$id")->assertInertia(fn ($p) => $p->where('message.attachments', [['id' => $a, 'name' => 'inhalt.txt', 'size' => 6, 'missing' => false]]));
});

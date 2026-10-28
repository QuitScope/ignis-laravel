<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\Mailer;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    Storage::fake(AttachmentStorage::DISK);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    $this->actingAs($this->alice['user']);
});

function mc_orphan(string $name, int $ageHours = 48): string
{
    $disk = Storage::disk(AttachmentStorage::DISK);
    $disk->put($name, 'x');
    touch($disk->path($name), time() - $ageHours * 3600);

    return $disk->path($name);
}

/** @return array{int, int, int, string} stale draft, fresh draft, abandoned sent mail, path of the stale draft's file */
function mc_scene($test): array
{
    $stale = ml_draft($test);
    ml_attach($test, $stale, 'alt', 'alt.txt')->assertCreated();
    $stalePath = app(AttachmentStorage::class)->absolutePath(Attachment::query()->where('message_id', $stale)->firstOrFail());
    MailMessage::query()->whereKey($stale)->toBase()->update(['updated_at' => now()->subDays(31)]);

    $fresh = ml_draft($test);
    MailMessage::query()->whereKey($fresh)->toBase()->update(['updated_at' => now()->subDays(29)]);

    $sent = ml_draft($test);
    ml_attach($test, $sent, 'weg', 'weg.txt')->assertCreated();
    $test->postJson("/mail/drafts/$sent/send", ['to' => [$test->bob['mailbox']->address]])->assertOk();
    $test->deleteJson("/mail/messages/$sent")->assertOk();
    $test->actingAs($test->bob['user']);
    $test->deleteJson("/mail/messages/$sent")->assertOk();

    return [$stale, $fresh, $sent, $stalePath];
}

it('removes drafts untouched for 30 days and attachments nobody holds any more', function () {
    [$stale, $fresh, $sent, $stalePath] = mc_scene($this);
    $kept = ml_draft($this->actingAs($this->alice['user']));
    ml_attach($this, $kept, 'bleibt', 'bleibt.txt')->assertCreated();
    $this->postJson("/mail/drafts/$kept/send", ['to' => [$this->bob['mailbox']->address]])->assertOk();
    $this->deleteJson("/mail/messages/$kept")->assertOk();

    $this->artisan('mail:cleanup')
        ->expectsOutputToContain('1 draft(s) removed, 2 attachment(s) removed, 0 orphaned file(s) found, 0 deleted, 0 missing file(s)')
        ->assertSuccessful();

    expect(MailMessage::query()->find($stale))->toBeNull()
        ->and(file_exists($stalePath))->toBeFalse()
        ->and(ml_deliveries($stale))->toBe([])
        ->and(MailMessage::query()->find($fresh))->not->toBeNull()
        ->and(Attachment::query()->where('message_id', $sent)->count())->toBe(0)
        ->and(MailMessage::query()->find($sent))->not->toBeNull()
        ->and(Attachment::query()->where('message_id', $kept)->count())->toBe(1)
        ->and(Storage::disk(AttachmentStorage::DISK)->allFiles())->toHaveCount(1);

    $this->artisan('mail:cleanup')->expectsOutputToContain('0 draft(s) removed, 0 attachment(s) removed')->assertSuccessful();
});

it('never removes a draft that was sent after it was picked', function () {
    $id = ml_draft($this, ['to' => [$this->bob['mailbox']->address]]);
    ml_attach($this, $id, 'Anlage', 'anlage.txt')->assertCreated();
    $path = app(AttachmentStorage::class)->absolutePath(Attachment::query()->where('message_id', $id)->sole());
    MailMessage::query()->whereKey($id)->toBase()->update(['updated_at' => now()->subDays(31)]);
    $done = false;
    DB::listen(function ($q) use (&$done, $id) {
        if (! $done && str_contains($q->sql, 'attachments_count')) {
            $done = true;
            app(Mailer::class)->send(MailMessage::query()->findOrFail($id), $this->alice['mailbox'], $this->alice['user'], 'Betreff',
                ['type' => 'doc', 'content' => []], ['to' => [$this->bob['mailbox']->address], 'cc' => [], 'bcc' => []]);
            MailMessage::query()->whereKey($id)->toBase()->update(['updated_at' => now()->subDays(31)]);
        }
    });

    $this->artisan('mail:cleanup')->expectsOutputToContain('0 draft(s) removed, 0 attachment(s) removed')->assertSuccessful();

    expect($done)->toBeTrue()
        ->and(MailMessage::query()->findOrFail($id)->status)->toBe('sent')
        ->and(ml_deliveries($id))->toBe([[$this->alice['mailbox']->id, 'sender', 'sent'], [$this->bob['mailbox']->id, 'to', 'inbox']])
        ->and(file_exists($path))->toBeTrue();
});

it('changes nothing on a dry run', function () {
    [$stale, , $sent] = mc_scene($this);
    mc_orphan('mail-999-0123456789abcdef.txt');

    $this->artisan('mail:cleanup --dry-run --prune-orphans')
        ->expectsOutputToContain('1 draft(s) removed, 2 attachment(s) removed, 1 orphaned file(s) found, 0 deleted, 0 missing file(s) (dry run, nothing changed)')
        ->assertSuccessful();

    expect(MailMessage::query()->find($stale))->not->toBeNull()
        ->and(Attachment::query()->count())->toBe(2)
        ->and(Storage::disk(AttachmentStorage::DISK)->allFiles())->toHaveCount(3);
});

it('reports orphaned files and deletes them only with --prune-orphans', function () {
    $old = mc_orphan('mail-999-0123456789abcdef.txt');
    $young = mc_orphan('mail-998-0123456789abcdef.pdf', 1);
    $foreign = mc_orphan('notizen.md');
    $id = ml_draft($this);
    ml_attach($this, $id, 'echt', 'echt.txt')->assertCreated();

    $this->artisan('mail:cleanup')
        ->expectsOutputToContain('orphan: mail-999-0123456789abcdef.txt')
        ->expectsOutputToContain('1 orphaned file(s) found, 0 deleted')
        ->assertSuccessful();
    expect(file_exists($old))->toBeTrue();

    $this->artisan('mail:cleanup --prune-orphans')->expectsOutputToContain('1 orphaned file(s) found, 1 deleted')->assertSuccessful();
    expect(file_exists($old))->toBeFalse()->and(file_exists($young))->toBeTrue()->and(file_exists($foreign))->toBeTrue()
        ->and(Attachment::query()->where('message_id', $id)->count())->toBe(1)
        ->and(app(AttachmentStorage::class)->absolutePath(Attachment::query()->where('message_id', $id)->sole()))->not->toBeNull();
});

it('counts missing files and keeps their rows', function () {
    $id = ml_draft($this);
    $a = Attachment::query()->findOrFail((int) ml_attach($this, $id, 'eins', 'eins.txt')->json('attachment.id'));
    $b = Attachment::query()->findOrFail((int) ml_attach($this, $id, 'zwei', 'zwei.txt')->json('attachment.id'));
    Storage::disk(AttachmentStorage::DISK)->delete($a->path);
    $b->forceFill(['missing' => true])->save();

    $this->artisan('mail:cleanup')->expectsOutputToContain('2 missing file(s)')->assertSuccessful();
    expect(Attachment::query()->count())->toBe(2);
});

it('never follows a symlink out of the disk', function () {
    $outside = sys_get_temp_dir().'/mc_outside_'.uniqid();
    mkdir($outside);
    file_put_contents($outside.'/mail-997-0123456789abcdef.txt', 'x');
    touch($outside.'/mail-997-0123456789abcdef.txt', time() - 99 * 3600);
    $root = Storage::disk(AttachmentStorage::DISK)->path('');
    $link = @symlink($outside.'/mail-997-0123456789abcdef.txt', $root.'mail-997-0123456789abcdef.txt');
    $dirLink = @symlink($outside, $root.'sub');
    if (! $link && ! $dirLink) {
        @unlink($outside.'/mail-997-0123456789abcdef.txt');
        @rmdir($outside);
        $this->markTestSkipped('symlinks not available');
    }

    $this->artisan('mail:cleanup --prune-orphans')->assertSuccessful();

    expect(file_exists($outside.'/mail-997-0123456789abcdef.txt'))->toBeTrue();
    @unlink($root.'mail-997-0123456789abcdef.txt');
    @unlink($root.'sub');
    @unlink($outside.'/mail-997-0123456789abcdef.txt');
    @rmdir($outside);
});

it('runs nightly at 03:30, only while the module is on', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'mail:cleanup'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 3 * * *')->and($event->filtersPass(app()))->toBeTrue();
    app(Settings::class)->set('modules.mail.enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});

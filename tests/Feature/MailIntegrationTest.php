<?php

use App\Events\PersonnelSaved;
use App\Models\Personnel;
use App\Navigation\NavigationCounters;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentStorage;

require_once __DIR__.'/Mail/mailHelpers.php';

uses(RefreshDatabase::class);

function p7bi_hits(string $q): array
{
    return test()->getJson('/api/system/global-search?'.http_build_query(['q' => $q]))->assertOk()
        ->collect('results')->firstWhere('key', 'mails')['items'] ?? [];
}

it('runs from a new record to a trashed and restored mail with bell, counter, search, reply-all and attachment', function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    Storage::fake(AttachmentStorage::DISK);

    $person = Personnel::factory()->create(['full_name' => 'Nina Neu']);
    $nina = ml_user(['mail.use'], $person);
    event(new PersonnelSaved($person->id));
    $ninaBox = Mailbox::query()->where('personnel_id', $person->id)->sole();
    expect($ninaBox->user_id)->toBe($nina->id);

    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Kopie');
    $carla = ml_member('Carla Blind');
    $dora = ml_member('Dora Verteiler');
    $eve = ml_member('Eve Admin', ['mail.use', 'admin', 'mail.admin']);
    $list = MailList::query()->forceCreate(['address' => 'wache@ignis.ef', 'name' => 'Wache']);
    $list->mailboxes()->attach([$ninaBox->id, $dora['mailbox']->id]);

    $this->actingAs($alice['user']);
    $draft = ml_draft($this);
    ml_attach($this, $draft, "Dienstplan\nMontag", 'plan.txt')->assertCreated();
    $id = $this->postJson("/mail/drafts/$draft/send", [
        'subject' => 'Rundmail Alarmuebung', 'body_json' => ml_doc('Alarmuebung am Samstag'),
        'to' => ['wache@ignis.ef'], 'cc' => [$bob['mailbox']->address], 'bcc' => [$carla['mailbox']->address],
    ])->assertOk()->json('messageId');
    expect($id)->toBe($draft)->and(MailMessage::query()->findOrFail($id)->status)->toBe('sent');

    $roles = MailDelivery::query()->where('message_id', $id)->pluck('role', 'mailbox_id')->all();
    ksort($roles);
    expect($roles)->toEqual(collect([
        $alice['mailbox']->id => 'sender', $ninaBox->id => 'to', $dora['mailbox']->id => 'to',
        $bob['mailbox']->id => 'cc', $carla['mailbox']->id => 'bcc',
    ])->sortKeys()->all());

    expect(ml_notified($id))->toEqualCanonicalizing([$nina->id, $dora['user']->id, $bob['user']->id, $carla['user']->id])
        ->and(app(NavigationCounters::class)->for($nina)['mail'])->toBe(1)
        ->and(app(NavigationCounters::class)->for($alice['user'])['mail'])->toBe(0);
    foreach ([$nina, $bob['user'], $carla['user']] as $reader) {
        $this->actingAs($reader);
        expect(p7bi_hits('Alarmuebung'))->toHaveCount(1);
    }
    $this->actingAs($eve['user']);
    expect(p7bi_hits('Alarmuebung'))->toBe([]);
    $this->get("/mail/inbox/$id")->assertNotFound();
    $this->get('/mail/attachments/'.Attachment::query()->where('message_id', $id)->value('id'))->assertNotFound();

    $this->actingAs($nina);
    $this->get("/mail/inbox/$id")->assertOk()->assertInertia(fn ($p) => $p->where('message.header.bcc', [])->where('message.attachments.0.name', 'plan.txt'));
    $this->patchJson("/mail/messages/$id/read", ['read' => true])->assertOk();
    expect(app(NavigationCounters::class)->for($nina)['mail'])->toBe(0)->and($nina->unreadNotifications()->count())->toBe(0);

    $this->get("/mail/compose/reply-all/$id")->assertOk()->assertInertia(fn ($p) => $p->where('subject', 'Re: Rundmail Alarmuebung')
        ->where('recipients.to', [$alice['mailbox']->address, 'wache@ignis.ef'])->where('recipients.bcc', [])
        ->where('recipients.cc', fn ($cc) => ! in_array($carla['mailbox']->address, collect($cc)->all(), true)
            && in_array($bob['mailbox']->address, collect($cc)->all(), true)));
    $reply = $this->postJson('/mail/drafts', ['subject' => 'Re: Rundmail Alarmuebung', 'body_json' => ml_doc('Ich komme'), 'in_reply_to' => $id])->assertCreated()->json('messageId');
    $this->postJson("/mail/drafts/$reply/send", ['to' => [$alice['mailbox']->address], 'cc' => [$bob['mailbox']->address, $dora['mailbox']->address]])->assertOk();
    expect(MailMessage::query()->findOrFail($reply)->thread_id)->toBe(MailMessage::query()->findOrFail($id)->thread_id)
        ->and(MailDelivery::query()->where('message_id', $reply)->where('mailbox_id', $carla['mailbox']->id)->exists())->toBeFalse()
        ->and(MailDelivery::query()->where('message_id', $reply)->where('mailbox_id', $alice['mailbox']->id)->where('role', 'to')->where('folder', 'inbox')->exists())->toBeTrue()
        ->and(ml_notified($reply))->toEqualCanonicalizing([$alice['user']->id, $bob['user']->id, $dora['user']->id]);

    $attachment = Attachment::query()->where('message_id', $id)->sole();
    foreach ([$nina, $carla['user'], $alice['user']] as $reader) {
        $this->actingAs($reader);
        $this->get("/mail/attachments/{$attachment->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename="plan.txt"; filename*=UTF-8\'\'plan.txt');
    }

    $this->actingAs($dora['user']);
    $this->patchJson("/mail/messages/$id/folder", ['folder' => 'trash'])->assertOk();
    expect($dora['user']->notifications()->where('type', 'mail')->where('data->link', "/mail/inbox/$id")->count())->toBe(0)
        ->and(MailDelivery::query()->where('message_id', $id)->where('mailbox_id', $dora['mailbox']->id)->value('folder'))->toBe('trash')
        ->and(MailDelivery::query()->where('message_id', $id)->where('mailbox_id', $bob['mailbox']->id)->value('folder'))->toBe('inbox');
    $this->patchJson("/mail/messages/$id/folder", ['folder' => 'restore'])->assertOk();
    expect(MailDelivery::query()->where('message_id', $id)->where('mailbox_id', $dora['mailbox']->id)->value('folder'))->toBe('inbox');
});

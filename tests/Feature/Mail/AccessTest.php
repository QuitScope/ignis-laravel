<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\AttachmentStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
});

it('gives a non-participant nothing, not even with every right', function (Closure $make) {
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang');
    $this->actingAs($alice['user']);
    $draft = ml_draft($this, ['to' => [$bob['mailbox']->address]]);
    $sent = ml_send($this, ['subject' => 'Intern', 'to' => [$bob['mailbox']->address]])->json('messageId');

    $eve = $make();
    $own = ml_provision(ml_person('Eve Fremd'));
    $eve->forceFill(['personnel_id' => $own->personnel_id])->save();
    $this->actingAs($eve->fresh());

    foreach ([$draft, $sent] as $id) {
        $this->putJson("/mail/drafts/$id", ['subject' => 'Gekapert'])->assertNotFound();
        $this->postJson("/mail/drafts/$id/send", ['to' => [$own->address]])->assertNotFound();
        $this->postJson('/mail/drafts', ['forward_from' => $id])->assertNotFound();
        $this->postJson('/mail/drafts', ['in_reply_to' => $id])->assertNotFound();
    }
    $this->putJson('/mail/drafts/999999', ['subject' => 'x'])->assertNotFound();

    expect(MailMessage::query()->findOrFail($draft)->subject)->toBe('Betreff')
        ->and(MailMessage::query()->findOrFail($draft)->status)->toBe('draft')
        ->and(MailMessage::query()->where('sender_mailbox_id', $own->id)->count())->toBe(0)
        ->and(ml_deliveries($sent))->toHaveCount(2);
})->with([
    'mail.use' => [fn () => ml_user(['mail.use'])],
    'admin' => [fn () => ml_user(['mail.use', 'admin'])],
    'super admin' => [fn () => User::factory()->superAdmin()->create()],
    'mail.admin' => [fn () => ml_user(['mail.use', 'mail.admin', 'mail.lists.manage', 'mail.domain.choose'])],
]);

it('binds {message} only through the participant scope', function (Closure $make) {
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang');
    $dora = ml_member('Dora Blind');
    $message = ml_message($alice['mailbox'], [[$bob['mailbox'], 'to', 'inbox'], [$dora['mailbox'], 'bcc', 'inbox']]);
    $bind = function (User $user, string $id) {
        $this->actingAs($user);

        return app('router')->getBindingCallback('message')($id);
    };

    foreach ([$alice, $bob, $dora] as $member) {
        expect($bind($member['user'], (string) $message->id)->is($message))->toBeTrue();
    }
    expect(fn () => $bind($make(), (string) $message->id))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $bind($bob['user'], $message->id.'x'))->toThrow(NotFoundHttpException::class);

    $message->deliveries()->where('mailbox_id', $bob['mailbox']->id)->update(['deleted_at' => now()]);
    expect(fn () => $bind($bob['user'], (string) $message->id))->toThrow(NotFoundHttpException::class);
})->with([
    'admin' => [fn () => ml_user(['mail.use', 'admin'])],
    'super admin' => [fn () => User::factory()->superAdmin()->create()],
    'mail.admin' => [fn () => ml_user(['mail.use', 'mail.admin'])],
]);

it('lets a bcc recipient download attachments', function () {
    Storage::fake(AttachmentStorage::DISK);
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang');
    $dora = ml_member('Dora Blind');
    $this->actingAs($alice['user']);
    $id = ml_draft($this);
    $attachment = (int) ml_attach($this, $id, 'Anlage', 'anlage.txt')->assertCreated()->json('attachment.id');
    $this->postJson("/mail/drafts/$id/send", ['to' => [$bob['mailbox']->address], 'bcc' => [$dora['mailbox']->address]])->assertOk();

    $this->actingAs($dora['user']);
    $this->get("/mail/attachments/$attachment")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="anlage.txt"; filename*=UTF-8\'\'anlage.txt');
});

it('writes nothing without the csrf token', function () {
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang');
    $this->actingAs($alice['user']);
    $draft = ml_draft($this, ['to' => [$bob['mailbox']->address]]);

    $this->app['env'] = 'local';
    $this->post('/mail/drafts', ['subject' => 'Ohne Token'])->assertStatus(419);
    $this->put("/mail/drafts/$draft", ['subject' => 'Ohne Token'])->assertStatus(419);
    $this->post("/mail/drafts/$draft/send")->assertStatus(419);

    expect(MailMessage::query()->count())->toBe(1)->and(MailMessage::query()->sole()->status)->toBe('draft')
        ->and(MailMessage::query()->sole()->subject)->toBe('Betreff');
});

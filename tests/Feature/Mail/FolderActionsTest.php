<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailMessage;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    $this->carla = ml_member('Carla Kopie');
    $this->actingAs($this->alice['user']);
    $this->id = ml_send($this, ['subject' => 'Wohin?', 'to' => [$this->bob['mailbox']->address, $this->carla['mailbox']->address]])->json('messageId');
});

/** @return array{folder: string, read_at: mixed, is_flagged: bool, deleted_at: mixed} the copy of $member */
function fa_copy(int $id, array $member): array
{
    $d = MailDelivery::query()->where('message_id', $id)->where('mailbox_id', $member['mailbox']->id)->where('role', '!=', 'sender')->first()
        ?? MailDelivery::query()->where('message_id', $id)->where('mailbox_id', $member['mailbox']->id)->firstOrFail();

    return ['folder' => $d->folder, 'read_at' => $d->read_at, 'is_flagged' => $d->is_flagged, 'deleted_at' => $d->deleted_at];
}

function fa_bell(int $userId, bool $unreadOnly = false): int
{
    return DB::table('notifications')->where('notifiable_id', $userId)->where('type', 'mail')
        ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))->count();
}

it('moves only the own copy and takes only the own bell entry along to the trash', function () {
    $this->actingAs($this->bob['user']);

    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'archive'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(fa_copy($this->id, $this->bob)['folder'])->toBe('archive')->and(fa_copy($this->id, $this->carla)['folder'])->toBe('inbox');

    expect(fa_bell($this->bob['user']->id))->toBe(1);
    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'trash'])->assertOk();
    expect(fa_copy($this->id, $this->bob)['folder'])->toBe('trash')
        ->and(fa_bell($this->bob['user']->id))->toBe(0)->and(fa_bell($this->carla['user']->id))->toBe(1);

    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'restore'])->assertOk();
    expect(fa_copy($this->id, $this->bob)['folder'])->toBe('inbox');

    $this->actingAs($this->alice['user']);
    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'trash'])->assertOk();
    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'restore'])->assertOk();
    expect(fa_copy($this->id, $this->alice)['folder'])->toBe('sent');
});

it('refuses unknown folders and moving drafts', function () {
    $this->actingAs($this->bob['user']);
    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'drafts'])->assertStatus(422);
    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => ['trash']])->assertStatus(422);
    $this->patchJson("/mail/messages/{$this->id}/folder", [])->assertStatus(422);

    $this->actingAs($this->alice['user']);
    $draft = ml_draft($this);
    $this->patchJson("/mail/messages/$draft/folder", ['folder' => 'archive'])->assertNotFound();
    expect(fa_copy($draft, $this->alice)['folder'])->toBe('drafts');
});

it('marks read and unread on the own copy, the own bell entry read', function () {
    $mia = ml_member('Mia Mitglied');
    $max = ml_member('Max Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user'], $max['user']]);
    $this->actingAs($this->alice['user']);
    $toGroup = ml_send($this, ['subject' => 'Gruppe', 'to' => [$group->address]])->json('messageId');

    $this->actingAs($this->bob['user']);
    $this->patchJson("/mail/messages/{$this->id}/read", ['read' => true])->assertOk();
    expect(fa_copy($this->id, $this->bob)['read_at'])->not->toBeNull()->and(fa_copy($this->id, $this->carla)['read_at'])->toBeNull()
        ->and(fa_bell($this->bob['user']->id, true))->toBe(0)->and(fa_bell($this->carla['user']->id, true))->toBe(1);

    $this->patchJson("/mail/messages/{$this->id}/read", ['read' => false])->assertOk();
    expect(fa_copy($this->id, $this->bob)['read_at'])->toBeNull();
    $this->patchJson("/mail/messages/{$this->id}/read", ['read' => 'ja'])->assertStatus(422);

    $this->actingAs($mia['user']);
    $this->patchJson("/mail/messages/$toGroup/read", ['read' => true, 'mailbox_id' => $group->id])->assertOk();
    expect(MailDelivery::query()->where('message_id', $toGroup)->where('mailbox_id', $group->id)->value('read_at'))->not->toBeNull()
        ->and(fa_bell($mia['user']->id, true))->toBe(0)->and(fa_bell($max['user']->id, true))->toBe(1);
});

it('flags only the own copy and only participants may', function () {
    $this->actingAs($this->bob['user']);

    $this->patchJson("/mail/messages/{$this->id}/flag", ['flagged' => true])->assertOk();
    expect(fa_copy($this->id, $this->bob)['is_flagged'])->toBeTrue()->and(fa_copy($this->id, $this->carla)['is_flagged'])->toBeFalse();
    $this->get('/mail/inbox')->assertInertia(fn ($page) => $page->where('messages.data.0.flagged', true));
    $this->get("/mail/inbox/{$this->id}")->assertInertia(fn ($page) => $page->where('message.flagged', true));

    $this->patchJson("/mail/messages/{$this->id}/flag", ['flagged' => false])->assertOk();
    expect(fa_copy($this->id, $this->bob)['is_flagged'])->toBeFalse();
    $this->patchJson("/mail/messages/{$this->id}/flag", ['flagged' => ['1']])->assertStatus(422);

    $this->actingAs(ml_member('Eve Fremd')['user']);
    $this->patchJson("/mail/messages/{$this->id}/flag", ['flagged' => true])->assertNotFound();
});

it('deletes only the own copy for good and forgets the own bell entry', function () {
    $this->actingAs($this->bob['user']);
    $this->deleteJson("/mail/messages/{$this->id}")->assertOk();

    expect(fa_copy($this->id, $this->bob)['deleted_at'])->not->toBeNull()->and(fa_copy($this->id, $this->carla)['deleted_at'])->toBeNull()
        ->and(fa_bell($this->bob['user']->id))->toBe(0)->and(fa_bell($this->carla['user']->id))->toBe(1)
        ->and(MailMessage::query()->whereKey($this->id)->exists())->toBeTrue();
    $this->get("/mail/inbox/{$this->id}")->assertNotFound();
    $this->deleteJson("/mail/messages/{$this->id}")->assertNotFound();

    $this->actingAs($this->carla['user'])->get("/mail/inbox/{$this->id}")->assertOk();
});

it('removes a draft without foreign copies entirely', function () {
    $draft = ml_draft($this);
    $this->deleteJson("/mail/messages/$draft")->assertOk();

    expect(MailMessage::query()->whereKey($draft)->exists())->toBeFalse()
        ->and(MailDelivery::query()->where('message_id', $draft)->exists())->toBeFalse();

    $other = ml_draft($this);
    $this->actingAs(ml_member('Mallory Fremd')['user'])->deleteJson("/mail/messages/$other")->assertNotFound();
    expect(MailMessage::query()->whereKey($other)->exists())->toBeTrue();
});

it('gives a non-participant a 404 on every action, also with every right', function (Closure $make) {
    $eve = $make();
    $own = ml_provision(ml_person('Eve Fremd'));
    $eve->forceFill(['personnel_id' => $own->personnel_id])->save();
    $this->actingAs($eve->fresh());
    $before = DB::table('mail_deliveries')->orderBy('id')->get()->toJson();

    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'trash'])->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    $this->patchJson("/mail/messages/{$this->id}/read", ['read' => true])->assertNotFound();
    $this->patchJson("/mail/messages/{$this->id}/read", ['read' => false])->assertNotFound();
    $this->patchJson("/mail/messages/{$this->id}/flag", ['flagged' => true])->assertNotFound();
    $this->deleteJson("/mail/messages/{$this->id}")->assertNotFound();
    $this->deleteJson('/mail/messages/999999')->assertNotFound();

    expect(DB::table('mail_deliveries')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(fa_bell($this->bob['user']->id))->toBe(1);
})->with([
    'mail.use' => [fn () => ml_user(['mail.use'])],
    'admin' => [fn () => ml_user(['mail.use', 'admin'])],
    'super admin' => [fn () => User::factory()->superAdmin()->create()],
    'mail.admin' => [fn () => ml_user(['mail.use', 'mail.admin', 'mail.lists.manage', 'mail.domain.choose'])],
]);

it('acts only in an accessible mailbox: a foreign mailbox_id is a 403, a mailbox without a copy a 404', function () {
    $mia = ml_member('Mia Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$this->bob['user']]);
    $this->actingAs($this->bob['user']);

    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'trash', 'mailbox_id' => $mia['mailbox']->id])->assertForbidden();
    $this->patchJson("/mail/messages/{$this->id}/folder", ['folder' => 'trash', 'mailbox_id' => $group->id])->assertNotFound();
    expect(fa_copy($this->id, $this->bob)['folder'])->toBe('inbox');
});

it('writes nothing without the csrf token', function () {
    $this->actingAs($this->bob['user']);
    $this->app['env'] = 'local';
    $this->patch("/mail/messages/{$this->id}/folder", ['folder' => 'trash'])->assertStatus(419);
    $this->delete("/mail/messages/{$this->id}")->assertStatus(419);
    expect(fa_copy($this->id, $this->bob))->toMatchArray(['folder' => 'inbox', 'deleted_at' => null]);
});

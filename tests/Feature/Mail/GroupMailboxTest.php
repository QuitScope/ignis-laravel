<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\MailboxProvisioner;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

/** @return list<int> message ids the account sees */
function mg_visible(User $user): array
{
    return MailDelivery::query()->visibleTo($user)->orderBy('message_id')->pluck('message_id')->unique()->values()->all();
}

it('never deactivates a group mailbox without a record (administration)', function () {
    $group = ml_group('Leitstelle', 'leitstelle');

    app(MailboxProvisioner::class)->deactivateOrphans();
    $this->artisan('mail:backfill')->assertSuccessful();

    expect($group->refresh()->is_active)->toBeTrue()->and($group->personnel_id)->toBeNull()->and($group->user_id)->toBeNull();
});

/** @return list<array{action: string, context: array<string, mixed>}> */
function mg_audit(User $by): array
{
    return Activity::query()->where('log_name', 'Mail')->where('causer_id', $by->id)->orderBy('id')->get()
        ->map(fn ($a) => ['action' => $a->description, 'context' => (array) $a->properties->get('context')])->all();
}

it('lets the administration create a group mailbox without a record', function () {
    $admin = ml_member('Ada Admin', ['mail.use', 'mail.admin']);
    $this->actingAs($admin['user']);

    $this->get('/settings/mail/mailboxes/groups/create')->assertOk()->assertInertia(fn ($p) => $p->component('Mail/Admin/GroupCreate')
        ->where('defaultDomain', 'ignis.ef')->where('canChooseDomain', false));
    $response = $this->post('/settings/mail/mailboxes/groups', ['name' => 'Leitstelle', 'local' => 'leitstelle', 'domain' => 'fremd.de']);

    $mailbox = Mailbox::query()->where('address', 'leitstelle@ignis.ef')->sole();
    $response->assertRedirect("/settings/mail/mailboxes/{$mailbox->id}/edit");
    expect($mailbox->isGroup())->toBeTrue()->and($mailbox->personnel_id)->toBeNull()->and($mailbox->user_id)->toBeNull()
        ->and($mailbox->display_name)->toBe('Leitstelle')->and($mailbox->is_active)->toBeTrue()
        ->and(mg_audit($admin['user'])[0])->toBe(['action' => 'Gruppenpostfach angelegt', 'context' => ['mailbox_id' => $mailbox->id, 'address' => 'leitstelle@ignis.ef']]);

    $this->get('/settings/mail/mailboxes?kind=group')->assertInertia(fn ($p) => $p->where('mailboxes.data.0.address', 'leitstelle@ignis.ef')
        ->where('mailboxes.data.0.members_count', 0)->has('mailboxes.data', 1));
    $this->get("/settings/mail/mailboxes/{$mailbox->id}/edit")->assertInertia(fn ($p) => $p->where('members', [])
        ->where('accountCandidates', [])->where('memberCandidates', fn ($c) => collect($c)->pluck('id')->doesntContain($admin['user']->id)));

    MailList::query()->forceCreate(['address' => 'wache@ignis.ef', 'name' => 'Wache']);
    $this->post('/settings/mail/mailboxes/groups', ['name' => 'Doppelt', 'local' => 'leitstelle'])->assertSessionHasErrors('local');
    $this->post('/settings/mail/mailboxes/groups', ['name' => 'Wache', 'local' => 'wache'])->assertSessionHasErrors('local');
    $this->post('/settings/mail/mailboxes/groups', ['name' => '', 'local' => 'ohne'])->assertSessionHasErrors('name');
    $this->post('/settings/mail/mailboxes/groups', ['name' => str_repeat('x', 151), 'local' => 'lang'])->assertSessionHasErrors('name');
    $this->post('/settings/mail/mailboxes/groups', ['name' => ['x'], 'local' => 'arr'])->assertSessionHasErrors('name');

    app(Settings::class)->set('mail.allowed_domains', ['lspd.de']);
    $admin['user']->roles()->first()->givePermissionTo('mail.domain.choose');
    $this->actingAs($admin['user']->fresh())->post('/settings/mail/mailboxes/groups', ['name' => 'LSPD', 'local' => 'leitstelle', 'domain' => 'lspd.de'])->assertRedirect();
    expect(Mailbox::query()->where('kind', 'group')->orderBy('id')->pluck('address')->all())->toBe(['leitstelle@ignis.ef', 'leitstelle@lspd.de']);
});

it('maintains members and the name only through the administration, never oneself', function () {
    $admin = ml_member('Ada Admin', ['mail.use', 'mail.admin']);
    $member = ml_member('Mia Mitglied');
    $inactive = ml_user(['mail.use'], null, ['is_active' => false]);
    $group = ml_group('Leitstelle', 'leitstelle');
    $this->actingAs($admin['user']);
    $base = "/settings/mail/mailboxes/{$group->id}";

    $this->post("$base/members", ['user_id' => $member['user']->id])->assertRedirect("$base/edit");
    expect($group->hasMember($member['user']))->toBeTrue()
        ->and(MailboxAccess::accessibleIds($member['user']))->toContain($group->id);
    $this->post("$base/members", ['user_id' => $member['user']->id])->assertSessionHasErrors('user_id');

    $this->post("$base/members", ['user_id' => $admin['user']->id])->assertSessionHasErrors('user_id');
    $this->post("$base/members", ['user_id' => $inactive->id])->assertSessionHasErrors('user_id');
    $this->post("$base/members", ['user_id' => 999999])->assertSessionHasErrors('user_id');
    expect($group->hasMember($admin['user']))->toBeFalse();

    $this->patch("$base/name", ['name' => 'Leitstelle Nord'])->assertRedirect("$base/edit");
    expect($group->refresh()->display_name)->toBe('Leitstelle Nord');
    $this->patch("$base/name", ['name' => ''])->assertSessionHasErrors('name');
    $this->put("$base/account", ['user_id' => $member['user']->id])->assertSessionHasErrors('mailbox');
    expect($group->refresh()->user_id)->toBeNull();

    $this->post("/settings/mail/mailboxes/{$member['mailbox']->id}/members", ['user_id' => $member['user']->id])->assertNotFound();
    $this->patch("/settings/mail/mailboxes/{$member['mailbox']->id}/name", ['name' => 'X'])->assertNotFound();

    $group->members()->attach($admin['user']->id);
    $this->delete("$base/members/{$admin['user']->id}")->assertSessionHasErrors('user_id');
    expect($group->hasMember($admin['user']))->toBeTrue();
    $group->members()->detach($admin['user']->id);

    $this->get("$base/edit")->assertInertia(fn ($p) => $p->where('members', [['id' => $member['user']->id, 'name' => $member['user']->name, 'is_active' => true]]));
    $this->delete("$base/members/{$member['user']->id}")->assertRedirect("$base/edit");
    expect($group->hasMember($member['user']))->toBeFalse()->and(MailboxAccess::accessibleIds($member['user']))->not->toContain($group->id);
    $this->delete("$base/members/{$member['user']->id}")->assertSessionHasErrors('user_id');

    $audit = mg_audit($admin['user']);
    expect(array_column($audit, 'action'))->toBe(['Gruppenpostfach: Mitglied aufgenommen', 'Gruppenpostfach umbenannt', 'Gruppenpostfach: Mitglied entfernt'])
        ->and($audit[1]['context'])->toBe(['mailbox_id' => $group->id, 'from' => 'Leitstelle', 'to' => 'Leitstelle Nord'])
        ->and($audit[2]['context'])->toBe(['mailbox_id' => $group->id, 'user_id' => $member['user']->id]);
});

it('refuses group administration without mail.admin', function () {
    $user = ml_member('Nur Nutzer');
    $group = ml_group('Leitstelle', 'leitstelle');
    $this->actingAs($user['user']);

    $this->get('/settings/mail/mailboxes/groups/create')->assertForbidden();
    $this->post("/settings/mail/mailboxes/{$group->id}/members", ['user_id' => $user['user']->id])->assertForbidden();
    expect($group->hasMember($user['user']))->toBeFalse();
});

it('lets members switch into the group mailbox and nobody else, not even mail.admin', function () {
    $member = ml_member('Mia Mitglied');
    $outsider = ml_member('Otto Aussen', ['mail.use', 'mail.admin']);
    $group = ml_group('Leitstelle', 'leitstelle', [$member['user']]);

    expect(MailboxAccess::accessibleIds($member['user']))->toBe([$member['mailbox']->id, $group->id])
        ->and(MailboxAccess::selected($member['user'])?->id)->toBe($member['mailbox']->id)
        ->and(MailboxAccess::selected($member['user'], $group->id)?->id)->toBe($group->id);

    $this->actingAs($member['user'])->get("/mail?mailbox={$group->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('selected', $group->id)->has('mailboxes', 2));
    $this->get('/mail')->assertInertia(fn ($page) => $page->where('selected', $group->id));

    expect(MailboxAccess::accessibleIds($outsider['user']))->toBe([$outsider['mailbox']->id])
        ->and(MailboxAccess::selected($outsider['user'], $group->id))->toBeNull();
    $this->actingAs($outsider['user'])->get("/mail?mailbox={$group->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('selected', null)->has('mailboxes', 1))
        ->assertDontSee('leitstelle@ignis.ef');
});

it('gives the group one shared copy that every member sees (sending, bell, counter: Tasks 3 and 8)', function () {
    $mia = ml_member('Mia Mitglied');
    $max = ml_member('Max Mitglied');
    $sender = ml_member('Sven Sender');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user'], $max['user']]);
    $message = ml_message($sender['mailbox'], [[$group, 'to', 'inbox']], 'Lage');

    expect(MailDelivery::query()->where('message_id', $message->id)->count())->toBe(2)
        ->and(mg_visible($mia['user']))->toBe([$message->id])
        ->and(mg_visible($max['user']))->toBe([$message->id])
        ->and(MailDelivery::query()->visibleTo($mia['user'])->sole()->id)->toBe(MailDelivery::query()->visibleTo($max['user'])->sole()->id);
});

it('opens and lists nothing of a locked group mailbox', function () {
    $mia = ml_member('Mia Mitglied');
    $sender = ml_member('Sven Sender');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user']]);
    ml_message($sender['mailbox'], [[$group, 'to', 'inbox']]);
    $group->forceFill(['is_locked' => true])->save();

    expect(MailboxAccess::accessibleIds($mia['user']))->toBe([$mia['mailbox']->id])
        ->and(mg_visible($mia['user']))->toBe([])
        ->and(MailboxAccess::selected($mia['user'], $group->id))->toBeNull();

    $group->forceFill(['is_locked' => false, 'is_active' => false])->save();
    expect(MailboxAccess::accessibleIds($mia['user']))->toBe([$mia['mailbox']->id]);
});

it('names the kind of recipients in the address book', function () {
    $ada = ml_member('Ada Adressbuch');
    ml_group('Leitstelle Adressbuch', 'leitstelle-ab');
    MailList::query()->forceCreate(['address' => 'alle-ab@ignis.ef', 'name' => 'Alle Adressbuch']);

    $results = collect($this->actingAs($ada['user'])->getJson('/mail/addressbook?q=Adressbuch')->assertOk()->json('results'))->keyBy('address');

    expect($results['leitstelle-ab@ignis.ef'])->toBe(['address' => 'leitstelle-ab@ignis.ef', 'name' => 'Leitstelle Adressbuch', 'kind' => 'group'])
        ->and($results['alle-ab@ignis.ef'])->toBe(['address' => 'alle-ab@ignis.ef', 'name' => 'Alle Adressbuch', 'kind' => 'list'])
        ->and($results[$ada['mailbox']->address])->toBe(['address' => $ada['mailbox']->address, 'name' => 'Ada Adressbuch', 'kind' => 'personal']);
});

it('fills the group signature with the writer and the group address', function () {
    $mia = ml_member('Mia Mitglied');
    $mia['person']->forceFill(['extra_qualification' => 'Disponentin'])->save();
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user']]);
    MailSignature::query()->forceCreate(['mailbox_id' => $group->id, 'body' => ['type' => 'doc', 'content' => array_map(
        fn (string $key) => ['type' => 'paragraph', 'content' => [['type' => 'docVariable', 'attrs' => ['name' => $key]]]],
        ['absender.name', 'absender.position', 'postfach.name', 'absender.mailadresse'],
    )]]);

    $this->actingAs($mia['user'])->get("/mail/compose?mailbox={$group->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('mailbox.id', $group->id)->where('body.content', [
            ['type' => 'paragraph'], ['type' => 'paragraph'],
            ...array_map(fn (string $text) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]], ['Mia Mitglied', 'Disponentin', 'Leitstelle', 'leitstelle@ignis.ef']),
        ]));
});

it('gives an account without a mailbox of its own the group anyway', function () {
    $user = ml_user();
    $group = ml_group('Leitstelle', 'leitstelle', [$user]);

    expect(MailboxAccess::current($user))->toBeNull()
        ->and(MailboxAccess::accessibleIds($user))->toBe([$group->id])
        ->and(MailboxAccess::selected($user)?->id)->toBe($group->id);
    $this->actingAs($user)->get('/mail')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page->component('Mail/Index')->where('selected', $group->id)->where('mailboxes.0.kind', 'group'));
});

// ---- visibleTo is the only rule; admin, super admin and mail.admin see nothing foreign ----

it('shows admin, super admin and mail.admin nothing of foreign mailboxes', function (Closure $make) {
    $alice = ml_member('Alice Privat');
    $bob = ml_member('Bob Privat');
    $group = ml_group('Leitstelle', 'leitstelle', [$bob['user']]);
    ml_message($alice['mailbox'], [[$bob['mailbox'], 'to', 'inbox'], [$group, 'cc', 'inbox']]);

    $viewer = $make();
    $own = ml_provision(ml_person('Vera Viewer'));
    $viewer->forceFill(['personnel_id' => $own->personnel_id])->save();
    $mine = ml_message($own, [[$bob['mailbox'], 'to', 'inbox']]);

    expect(mg_visible($viewer->fresh()))->toBe([$mine->id])
        ->and(MailboxAccess::accessibleIds($viewer->fresh()))->toBe([$own->id])
        ->and(MailboxAccess::selected($viewer->fresh(), $alice['mailbox']->id))->toBeNull()
        ->and(MailboxAccess::selected($viewer->fresh(), $group->id))->toBeNull();
})->with([
    'admin' => [fn () => ml_user(['admin'])],
    'super admin' => [fn () => User::factory()->superAdmin()->create()],
    'mail.admin' => [fn () => ml_user(['mail.use', 'mail.admin', 'mail.lists.manage', 'mail.domain.choose'])],
]);

it('hides deleted copies and copies of other mailboxes', function () {
    $alice = ml_member('Alice Ab');
    $bob = ml_member('Bob Empf');
    $message = ml_message($alice['mailbox'], [[$bob['mailbox'], 'to', 'inbox']]);

    MailDelivery::query()->where('message_id', $message->id)->where('mailbox_id', $bob['mailbox']->id)->update(['deleted_at' => now()]);

    expect(mg_visible($bob['user']))->toBe([])
        ->and(MailDelivery::query()->visibleTo($alice['user'])->pluck('mailbox_id')->all())->toBe([$alice['mailbox']->id]);
});

it('opens nothing for a locked or inactive own mailbox', function () {
    $alice = ml_member('Alice Lock');
    $bob = ml_member('Bob Sender');
    ml_message($bob['mailbox'], [[$alice['mailbox'], 'to', 'inbox']]);

    $alice['mailbox']->forceFill(['is_locked' => true])->save();
    expect(MailboxAccess::current($alice['user']))->toBeNull()->and(mg_visible($alice['user']))->toBe([]);

    $alice['mailbox']->forceFill(['is_locked' => false, 'is_active' => false])->save();
    expect(MailboxAccess::current($alice['user']))->toBeNull()->and(mg_visible($alice['user']))->toBe([]);
});

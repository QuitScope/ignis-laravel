<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\MailList;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
});

it('gives admin and super admins every mail right but no foreign mail', function (array $perms, bool $super) {
    $admin = ml_member('Adam Admin', $perms);
    $admin['user']->forceFill(['is_super_admin' => $super])->save();
    $bob = ml_member('Bob Empfang');
    $this->actingAs($admin['user']);

    $this->get('/mail')->assertOk();
    ml_send($this, ['to' => [$bob['mailbox']->address]]);

    $this->get('/mail/lists')->assertOk();
    $this->post('/mail/lists', ['name' => 'Wache 1', 'local' => 'wache1', 'domain' => 'ignis.ef', 'kind' => 'static', 'senders' => 'managers', 'members' => [$bob['mailbox']->id]])
        ->assertRedirect('/mail/lists');
    expect(MailList::query()->where('address', 'wache1@ignis.ef')->exists())->toBeTrue();
    ml_send($this, ['to' => ['wache1@ignis.ef']]);

    $this->get('/settings/mail')->assertOk();
    $this->get('/settings/mail/mailboxes')->assertOk();
    $this->get("/settings/mail/mailboxes/{$bob['mailbox']->id}/edit")->assertOk()->assertInertia(fn ($p) => $p->where('canChooseDomain', true));

    $this->actingAs($bob['user']);
    $incoming = ml_send($this, ['to' => [$bob['mailbox']->address]])->json('messageId');
    $this->actingAs($admin['user'])->get("/mail/inbox/$incoming")->assertNotFound();
    $this->get("/mail/inbox/$incoming?mailbox={$bob['mailbox']->id}")->assertNotFound();
})->with([
    'admin' => [['admin'], false],
    'super admin' => [[], true],
]);

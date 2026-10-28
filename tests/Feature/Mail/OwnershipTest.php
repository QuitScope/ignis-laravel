<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Support\MailboxAccess;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mo_unbind(Mailbox $mailbox): Mailbox
{
    Mailbox::query()->whereKey($mailbox->id)->update(['user_id' => null]);

    return $mailbox->refresh();
}

function mo_relink(User $user, ?int $personnelId): void
{
    $user->forceFill(['personnel_id' => $personnelId])->save();
}

it('opens no foreign mailbox when records are re-linked', function () {
    $alice = ml_member('Alice Opfer');
    $bob = ml_member('Bob Personal');
    $carla = ml_member('Carla Absender');
    expect($alice['mailbox']->user_id)->toBe($alice['user']->id);
    $message = ml_message($carla['mailbox'], [[$alice['mailbox'], 'to', 'inbox']], 'Nur für Alice');

    mo_relink($alice['user'], null);
    mo_relink($bob['user'], $alice['person']->id);
    mo_relink($alice['user'], $bob['person']->id);

    expect(MailboxAccess::current($bob['user']->fresh())?->id)->toBe($bob['mailbox']->id)
        ->and(MailDelivery::query()->visibleTo($bob['user']->fresh())->where('message_id', $message->id)->exists())->toBeFalse();

    $mallory = ml_user();
    mo_relink($bob['user'], null);
    mo_relink($mallory, $alice['person']->id);
    expect(MailboxAccess::current($mallory->fresh()))->toBeNull()
        ->and(MailDelivery::query()->visibleTo($mallory->fresh())->exists())->toBeFalse();
    $this->actingAs($mallory->fresh())->get('/mail')->assertOk()->assertDontSee('Nur für Alice')->assertDontSee($alice['mailbox']->address);

    expect(MailDelivery::query()->visibleTo($alice['user']->fresh())->where('message_id', $message->id)->exists())->toBeTrue()
        ->and($alice['mailbox']->refresh()->user_id)->toBe($alice['user']->id);
});

it('binds a free mailbox on first access, only to the one active account without a mailbox', function () {
    $person = ml_person('Frieda Frei');
    $mailbox = ml_provision($person);
    expect($mailbox->user_id)->toBeNull();

    $user = ml_user(['mail.use'], $person);
    expect(MailboxAccess::current($user)?->id)->toBe($mailbox->id)
        ->and($mailbox->refresh()->user_id)->toBe($user->id);

    $zora = ml_person('Zora Zwei');
    $shared = ml_provision($zora);
    $inactive = ml_user(['mail.use'], $zora, ['is_active' => false]);
    expect(MailboxAccess::current($inactive))->toBeNull()->and($shared->refresh()->user_id)->toBeNull();

    $olga = ml_member('Olga Hatschon');
    $third = ml_provision(ml_person('Theo Dritt'));
    mo_relink($olga['user'], $third->personnel_id);
    expect(MailboxAccess::autoBind($third))->toBeNull()
        ->and(MailboxAccess::current($olga['user']->fresh())?->id)->toBe($olga['mailbox']->id)
        ->and($third->refresh()->user_id)->toBeNull();

    $this->actingAs(ml_user())->get('/mail')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mail/Index')->where('mailboxes', [])->where('selected', null));
});

it('lets the backfill bind free mailboxes and never move bound ones', function () {
    $alice = ml_member('Alice Bestand');
    $mallory = ml_user();
    mo_relink($alice['user'], null);
    mo_relink($mallory, $alice['person']->id);

    $person = ml_person('Fritz Frei');
    $free = ml_provision($person);
    $user = ml_user(['mail.use'], $person);
    expect($free->refresh()->user_id)->toBeNull();

    $this->artisan('mail:backfill')->assertSuccessful();

    expect($alice['mailbox']->refresh()->user_id)->toBe($alice['user']->id)
        ->and($free->refresh()->user_id)->toBe($user->id);
});

it('assigns an account only to a matching account, never to oneself, audited, and tells the old account', function () {
    $admin = ml_user(['mail.admin']);
    $paul = ml_member('Paul Umzug');
    $mailbox = $paul['mailbox'];
    $newUser = ml_user();
    $assign = fn (mixed $userId) => $this->put("/settings/mail/mailboxes/{$mailbox->id}/account", ['user_id' => $userId]);
    $this->actingAs($admin);

    $assign($newUser->id)->assertSessionHasErrors('user_id');
    expect($mailbox->refresh()->user_id)->toBe($paul['user']->id);

    mo_relink($paul['user'], null);

    mo_relink($admin, $paul['person']->id);
    $assign($admin->id)->assertSessionHasErrors('user_id');
    expect($mailbox->refresh()->user_id)->toBe($paul['user']->id);
    mo_relink($admin, null);

    $olga = ml_member('Olga Hatschon', ['mail.use', 'mail.admin']);
    mo_relink($olga['user'], $paul['person']->id);
    $assign($olga['user']->id)->assertSessionHasErrors('user_id');
    mo_relink($olga['user'], null);
    $sleeper = ml_user(['mail.use'], $paul['person'], ['is_active' => false]);
    $assign($sleeper->id)->assertSessionHasErrors('user_id');
    $assign('x')->assertSessionHasErrors('user_id');
    expect($mailbox->refresh()->user_id)->toBe($paul['user']->id);

    mo_relink($sleeper, null);
    mo_relink($newUser, $paul['person']->id);
    $this->get("/settings/mail/mailboxes/{$mailbox->id}/edit")->assertInertia(fn ($p) => $p
        ->where('accountCandidates', [['id' => $newUser->id, 'name' => $newUser->name]]));
    $assign($newUser->id)->assertRedirect("/settings/mail/mailboxes/{$mailbox->id}/edit");
    expect($mailbox->refresh()->user_id)->toBe($newUser->id);

    $audit = Activity::query()->where('log_name', 'Mail')->where('causer_id', $admin->id)->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->description)->toBe('Postfach-Konto zugeordnet')
        ->and((array) $audit[0]->properties->get('context'))->toBe(['mailbox_id' => $mailbox->id, 'from' => $paul['user']->id, 'to' => $newUser->id])
        ->and($audit[0]->details.json_encode($audit[0]->properties))->not->toContain($mailbox->address);
    expect(DB::table('notifications')->where('notifiable_id', $paul['user']->id)->where('type', 'system')->count())->toBe(1);

    expect(MailboxAccess::current($newUser)?->id)->toBe($mailbox->id)->and(MailboxAccess::current($paul['user']))->toBeNull();

    mo_relink($newUser, null);
    $this->delete("/settings/mail/mailboxes/{$mailbox->id}/account")->assertSessionHasNoErrors()->assertRedirect();
    expect($mailbox->refresh()->user_id)->toBeNull();

    $this->actingAs($olga['user']);
    $this->put("/settings/mail/mailboxes/{$olga['mailbox']->id}/account", ['user_id' => $paul['user']->id])->assertSessionHasErrors('mailbox');
    $this->delete("/settings/mail/mailboxes/{$olga['mailbox']->id}/account")->assertSessionHasErrors('mailbox');
    expect($olga['mailbox']->refresh()->user_id)->toBe($olga['user']->id);
});

it('never releases an account on an assign without a choice', function () {
    $admin = ml_user(['mail.admin']);
    $paul = ml_member('Paul Bleibt');
    $this->actingAs($admin);

    foreach ([[], ['user_id' => null], ['user_id' => '']] as $fields) {
        $this->put("/settings/mail/mailboxes/{$paul['mailbox']->id}/account", $fields)->assertSessionHasErrors('user_id');
    }

    expect($paul['mailbox']->refresh()->user_id)->toBe($paul['user']->id)
        ->and(Activity::query()->where('log_name', 'Mail')->count())->toBe(0)
        ->and(DB::table('notifications')->where('type', 'system')->count())->toBe(0);

    mo_relink($paul['user'], null);
    $this->delete("/settings/mail/mailboxes/{$paul['mailbox']->id}/account")->assertRedirect("/settings/mail/mailboxes/{$paul['mailbox']->id}/edit")
        ->assertSessionHas('status', 'mail.admin.account_released');
    expect($paul['mailbox']->refresh()->user_id)->toBeNull()
        ->and(Activity::query()->where('log_name', 'Mail')->sole()->description)->toBe('Postfach-Konto gelöst')
        ->and(DB::table('notifications')->where('notifiable_id', $paul['user']->id)->where('type', 'system')->count())->toBe(1);
});

it('refuses to release an account the record still links (autoBind would bind it again), and a release sticks once unlinked', function () {
    $admin = ml_user(['mail.admin']);
    $paul = ml_member('Paul Gebunden');
    $mailbox = $paul['mailbox'];
    $this->actingAs($admin);

    $this->delete("/settings/mail/mailboxes/{$mailbox->id}/account")->assertSessionHasErrors('user_id');
    expect($mailbox->refresh()->user_id)->toBe($paul['user']->id)
        ->and(Activity::query()->where('log_name', 'Mail')->count())->toBe(0)
        ->and(DB::table('notifications')->where('type', 'system')->count())->toBe(0);
    $this->get("/settings/mail/mailboxes/{$mailbox->id}/edit")->assertInertia(fn ($p) => $p->where('releaseBlocked', true));

    mo_relink($paul['user'], null);
    $this->get("/settings/mail/mailboxes/{$mailbox->id}/edit")->assertInertia(fn ($p) => $p->where('releaseBlocked', false));
    $this->delete("/settings/mail/mailboxes/{$mailbox->id}/account")->assertSessionHasNoErrors()->assertRedirect();
    expect(MailboxAccess::accessibleIds($paul['user']))->not->toContain($mailbox->id)
        ->and($mailbox->refresh()->user_id)->toBeNull()
        ->and(Activity::query()->where('log_name', 'Mail')->sole()->description)->toBe('Postfach-Konto gelöst')
        ->and(DB::table('notifications')->where('notifiable_id', $paul['user']->id)->where('type', 'system')->count())->toBe(1);
});

it('refuses a release while another active account of the record would take the mailbox at once', function () {
    $admin = ml_user(['mail.admin']);
    $paul = ml_member('Paul Alt');
    mo_relink($paul['user'], null);
    $other = ml_user(['mail.use'], $paul['person']);
    $this->actingAs($admin);

    $this->delete("/settings/mail/mailboxes/{$paul['mailbox']->id}/account")->assertSessionHasErrors('user_id');
    expect($paul['mailbox']->refresh()->user_id)->toBe($paul['user']->id)
        ->and(MailboxAccess::accessibleIds($other))->toBe([]);
});

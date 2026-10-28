<?php

use App\Navigation\NavigationCounters;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\MailboxAccessMemo;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

function mc_count($user): ?int
{
    return app(NavigationCounters::class)->for($user)['mail'] ?? null;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
});

it('counts unread inbox mails over all accessible mailboxes, not sent, read, deleted or trashed ones', function () {
    $group = ml_group('Leitstelle', 'leitstelle', [$this->bob['user']]);
    $a = ml_message($this->alice['mailbox'], [[$this->bob['mailbox'], 'to', 'inbox']]);
    ml_message($this->alice['mailbox'], [[$group, 'cc', 'inbox']]);
    $read = ml_message($this->alice['mailbox'], [[$this->bob['mailbox'], 'to', 'inbox']]);
    $gone = ml_message($this->alice['mailbox'], [[$this->bob['mailbox'], 'to', 'inbox']]);
    ml_message($this->alice['mailbox'], [[$this->bob['mailbox'], 'to', 'trash']]);
    MailDelivery::query()->where('message_id', $read->id)->where('mailbox_id', $this->bob['mailbox']->id)->update(['read_at' => now()]);
    MailDelivery::query()->where('message_id', $gone->id)->where('mailbox_id', $this->bob['mailbox']->id)->update(['deleted_at' => now()]);

    expect(mc_count($this->bob['user']))->toBe(2)
        ->and(mc_count($this->alice['user']))->toBe(0)
        ->and(mc_count(ml_user(['mail.use'])))->toBeNull();

    $a->deliveries()->where('mailbox_id', $this->bob['mailbox']->id)->update(['read_at' => now()]);
    expect(mc_count($this->bob['user']))->toBe(1);
});

it('is one grouped count once the access is memoized, and drops a locked group', function () {
    $group = ml_group('Leitstelle', 'leitstelle', [$this->bob['user']]);
    ml_message($this->alice['mailbox'], [[$group, 'to', 'inbox']]);
    MailboxAccess::accessibleIds($this->bob['user']);

    $this->bob['user']->hasPermission(['mail.use']);
    DB::enableQueryLog();
    mc_count($this->bob['user']);
    expect(DB::getQueryLog())->toHaveCount(1);

    $group->forceFill(['is_locked' => true])->save();
    expect(mc_count($this->bob['user']))->toBe(0);
});

it('memoizes the accessible mailboxes per account within a request', function () {
    ml_group('Leitstelle', 'leitstelle', [$this->bob['user']]);
    MailboxAccess::forget();

    DB::enableQueryLog();
    $first = MailboxAccess::accessibleIds($this->bob['user']);
    $queries = count(DB::getQueryLog());
    $again = MailboxAccess::accessibleIds($this->bob['user']);
    MailboxAccess::accessible($this->bob['user']);

    expect($queries)->toBeGreaterThan(0)->and($again)->toBe($first)->and(count(DB::getQueryLog()))->toBe($queries)
        ->and(MailboxAccess::accessibleIds($this->alice['user']))->not->toBe($first);
});

it('invalidates after membership change, lock and release, and is scoped, not static', function () {
    $group = ml_group('Leitstelle', 'leitstelle');
    $user = $this->bob['user'];
    expect(MailboxAccess::accessibleIds($user))->toBe([$this->bob['mailbox']->id]);

    $group->members()->attach($user->id);
    MailboxAccess::forget();
    expect(MailboxAccess::accessibleIds($user))->toContain($group->id);

    $group->forceFill(['is_locked' => true])->save();
    expect(MailboxAccess::accessibleIds($user))->not->toContain($group->id);
    $group->forceFill(['is_locked' => false])->save();
    expect(MailboxAccess::accessibleIds($user))->toContain($group->id);

    $user->personnel_id = null;
    $this->bob['mailbox']->forceFill(['user_id' => null])->save();
    expect(MailboxAccess::accessibleIds($user))->toBe([$group->id]);

    app()->forgetScopedInstances();
    expect(app(MailboxAccessMemo::class)->accessible)->toBe([]);
});

<?php

use App\Events\PersonnelSaved;
use App\Models\Personnel;
use App\Navigation\NavigationCounters;
use App\Notifications\NotificationKinds;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;
use Modules\Mail\Notifications\MailType;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
});

function mi_hits(string $q): array
{
    return test()->getJson('/api/system/global-search?'.http_build_query(['q' => $q]))->assertOk()
        ->collect('results')->firstWhere('key', 'mails')['items'] ?? [];
}

it('keeps the bell on the mail: trash and delete remove it, a locked mailbox hides the kind', function () {
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang');
    $carla = ml_member('Carla Kopie');
    $id = ml_send($this->actingAs($alice['user']), ['subject' => 'Wohin?', 'to' => [$bob['mailbox']->address, $carla['mailbox']->address]])->json('messageId');

    $this->actingAs($bob['user'])->patchJson("/mail/messages/$id/folder", ['folder' => 'archive'])->assertOk();
    expect($bob['user']->notifications()->count())->toBe(1);
    $this->patchJson("/mail/messages/$id/folder", ['folder' => 'trash'])->assertOk();
    expect($bob['user']->notifications()->count())->toBe(0)->and($carla['user']->notifications()->count())->toBe(1);

    $this->actingAs($carla['user']);
    $carla['mailbox']->forceFill(['is_locked' => true])->save();
    expect(app(NotificationKinds::class)->allowed($carla['user']))->not->toContain('mail')
        ->and(MailType::link($id))->toBe("/mail/inbox/$id");
});

it('counts in the Inertia prop and reading the mail lowers it together with the bell', function () {
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang');
    $id = ml_send($this->actingAs($alice['user']), ['subject' => 'Einsatzbesprechung', 'to' => [$bob['mailbox']->address]])->json('messageId');

    $this->actingAs($bob['user'])->get('/mail')->assertInertia(fn (Assert $p) => $p->where('nav.counters.mail', 1));
    expect($bob['user']->unreadNotifications()->count())->toBe(1);

    $this->patchJson("/mail/messages/$id/read", ['read' => true])->assertOk();
    expect($bob['user']->unreadNotifications()->count())->toBe(0);
    $this->get('/mail')->assertInertia(fn (Assert $p) => $p->where('nav.counters.mail', 0));
});

it('runs from a new record to a read mail sent to a list', function () {
    $alice = ml_member('Alice Absender');
    $person = Personnel::factory()->create(['full_name' => 'Nina Neu']);
    $nina = ml_user(['mail.use'], $person);
    event(new PersonnelSaved($person->id));
    $mailbox = Mailbox::query()->where('personnel_id', $person->id)->sole();
    expect($mailbox->user_id)->toBe($nina->id);

    $list = MailList::query()->forceCreate(['address' => 'alle@ignis.ef', 'name' => 'Alle']);
    $list->mailboxes()->attach($mailbox->id);
    $id = ml_send($this->actingAs($alice['user']), ['subject' => 'Rundmail Uebung', 'body_json' => ml_doc('Alarmuebung Samstag'), 'to' => ['alle@ignis.ef']])->json('messageId');

    $this->actingAs($nina);
    expect($nina->unreadNotifications()->count())->toBe(1)
        ->and(app(NavigationCounters::class)->for($nina)['mail'])->toBe(1)
        ->and(mi_hits('Alarmuebung'))->toHaveCount(1)
        ->and(mi_hits('Alarmuebung')[0]['href'])->toBe("/mail/inbox/$id?mailbox={$mailbox->id}");

    $this->get("/mail/inbox/$id?mailbox={$mailbox->id}")->assertOk();
    $this->patchJson("/mail/messages/$id/read", ['read' => true])->assertOk();
    expect(app(NavigationCounters::class)->for($nina)['mail'])->toBe(0);

    $this->actingAs(ml_member('Admin Person', ['mail.use', 'mail.admin', 'admin'])['user']);
    expect(mi_hits('Alarmuebung'))->toBe([]);
});

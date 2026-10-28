<?php

use App\Models\EmsQualification;
use App\Models\Rank;
use App\Models\Specialty;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\AddressHistory;
use Modules\Mail\Models\MailList;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->manager = ml_member('Mia Manager', ['mail.use', 'mail.lists.manage']);
    $this->actingAs($this->manager['user']);
});

/** @return list<array{action: string, context: array<string, mixed>}> */
function la_audit(): array
{
    return Activity::query()->where('log_name', 'Mail')->orderBy('id')->get()
        ->map(fn (Activity $a) => ['action' => $a->description, 'context' => (array) $a->properties->get('context')])->all();
}

function la_body(array $over = []): array
{
    return $over + ['name' => 'Wache 1', 'local' => 'wache1', 'domain' => 'ignis.ef', 'kind' => 'static', 'senders' => 'all', 'members' => [], 'rule' => []];
}

/** @return list<int> */
function la_members(MailList $list): array
{
    return DB::table('mail_list_members')->where('mail_list_id', $list->id)->orderBy('mailbox_id')->pluck('mailbox_id')->map(fn ($id) => (int) $id)->all();
}

it('runs a static list from creation to deletion with id-only audit', function () {
    $a = ml_member('Anna Aktiv')['mailbox'];
    $b = ml_member('Bert Zwei')['mailbox'];
    $locked = ml_member('Lars Gesperrt')['mailbox'];
    $locked->forceFill(['is_locked' => true])->save();

    $this->get('/mail/lists')->assertOk()->assertInertia(fn ($p) => $p->component('Mail/Lists/Index')->where('lists', []));
    $this->get('/mail/lists/create')->assertOk()->assertInertia(fn ($p) => $p->component('Mail/Lists/Edit')->where('list', null)
        ->where('mailboxes', fn ($rows) => collect($rows)->pluck('id')->doesntContain($locked->id)));

    $this->post('/mail/lists', la_body(['members' => [$a->id, $locked->id]]))->assertRedirect('/mail/lists');
    $list = MailList::query()->where('address', 'wache1@ignis.ef')->sole();
    expect(la_members($list))->toBe([$a->id]);

    $a->forceFill(['is_locked' => true])->save();
    $this->put("/mail/lists/{$list->id}", la_body(['senders' => 'managers', 'members' => [$a->id, $b->id]]))->assertRedirect('/mail/lists');
    expect(la_members($list))->toBe([$a->id, $b->id])->and($list->refresh()->senders)->toBe('managers');

    $this->get('/mail/lists')->assertInertia(fn ($p) => $p->where('lists.0.address', 'wache1@ignis.ef')
        ->where('lists.0.senders', 'managers')->where('lists.0.members_count', 2));
    $this->get("/mail/lists/{$list->id}/edit")->assertOk()->assertInertia(fn ($p) => $p->where('list.members', [$a->id, $b->id])
        ->where('mailboxes', fn ($rows) => collect($rows)->pluck('id')->contains($a->id)));

    $this->delete("/mail/lists/{$list->id}")->assertRedirect('/mail/lists');
    expect(MailList::query()->find($list->id))->toBeNull();

    $audit = la_audit();
    expect(array_column($audit, 'action'))->toBe(['Verteiler angelegt', 'Verteiler bearbeitet', 'Verteiler gelöscht'])
        ->and($audit[0]['context']['members_added'])->toBe([$a->id])
        ->and($audit[1]['context']['members_added'])->toBe([$b->id])
        ->and($audit[1]['context']['senders_changed'])->toBe(['all', 'managers'])
        ->and($audit[2]['context']['members_removed'])->toBe([$a->id, $b->id])
        ->and(json_encode($audit))->not->toContain('Anna');
});

it('needs a rule for a dynamic list, drops unknown ids and logs the rule ids', function () {
    $rank = Rank::factory()->create();
    $ems = EmsQualification::create(['priority' => 1, 'name' => 'NotSan', 'name_male' => 'NotSan', 'name_female' => 'NotSan']);
    $base = la_body(['name' => 'Alle RD', 'local' => 'rd', 'kind' => 'dynamic']);

    $this->post('/mail/lists', $base)->assertSessionHasErrors('rule');
    $this->post('/mail/lists', $base + ['rule' => ['rank' => [$rank->id], 'ems_qualification' => [$ems->id, 999999]]])->assertSessionHasErrors('rule');
    $this->post('/mail/lists', array_merge($base, ['rule' => ['rank' => [$rank->id], 'ems_qualification' => [$ems->id, 999999]], 'members' => [$this->manager['mailbox']->id]]))
        ->assertRedirect('/mail/lists');

    $list = MailList::query()->where('address', 'rd@ignis.ef')->sole();
    expect($list->rule['rank'])->toBe([$rank->id])
        ->and($list->rule['ems_qualification'])->toBe([$ems->id])
        ->and(la_members($list))->toBe([]);
    $this->get("/mail/lists/{$list->id}/edit")->assertOk()->assertInertia(fn ($p) => $p->where('list.kind', 'dynamic')->where('list.rule.rank', [$rank->id]));

    $this->put("/mail/lists/{$list->id}", array_merge($base, ['rule' => ['ems_qualification' => [$ems->id]]]))->assertRedirect('/mail/lists');
    expect(la_audit()[1]['context']['rank_removed'])->toBe([$rank->id]);

    $this->put("/mail/lists/{$list->id}", la_body(['name' => 'Alle RD', 'local' => 'rd']))->assertRedirect('/mail/lists');
    expect($list->refresh()->rule)->toBeNull()->and($list->kind)->toBe('static');
});

it('offers specialties and ranks with badges as criteria and logs specialty ids', function () {
    $fd = Specialty::create(['number' => 903, 'name' => 'Höhenrettung']);
    $badged = Rank::factory()->create(['badge' => '/storage/ranks/1.png']);
    $plain = Rank::factory()->create(['badge' => null]);

    $this->get('/mail/lists/create')->assertInertia(fn ($p) => $p
        ->where('criteria.specialty', fn ($o) => collect($o)->contains(fn ($x) => $x['id'] === $fd->id && $x['name'] === 'Höhenrettung'))
        ->where('criteria.rank', fn ($o) => collect($o)->firstWhere('id', $plain->id)['badge'] === null
            && collect($o)->firstWhere('id', $badged->id)['badge'] === '/storage/ranks/1.png')
        ->has('criteria.role')->has('criteria.ems_qualification')->has('criteria.fire_qualification'));

    $this->post('/mail/lists', la_body(['name' => 'Höhenretter', 'local' => 'hoehe', 'kind' => 'dynamic', 'rule' => ['specialty' => [$fd->id, 999999]]]))
        ->assertRedirect('/mail/lists');
    $list = MailList::query()->where('address', 'hoehe@ignis.ef')->sole();
    expect($list->rule['specialty'])->toBe([$fd->id])->and(la_audit()[0]['context']['specialty_added'])->toBe([$fd->id]);
    $this->get('/mail/lists')->assertInertia(fn ($p) => $p->where('lists.0.rule.specialty', ['Höhenrettung']));
});

it('keeps the address unique over mailboxes, lists and former addresses and checks every field', function () {
    $bob = ml_member('Bob Empfang')['mailbox'];
    AddressHistory::query()->forceCreate(['address' => 'alt@ignis.ef', 'mailbox_id' => $bob->id, 'released_at' => now()]);
    MailList::query()->forceCreate(['address' => 'liste@ignis.ef', 'name' => 'Liste']);

    $this->post('/mail/lists', la_body(['local' => 'b.empfang']))->assertSessionHasErrors('local');
    $this->post('/mail/lists', la_body(['local' => 'alt']))->assertSessionHasErrors('local');
    $this->post('/mail/lists', la_body(['local' => 'liste']))->assertSessionHasErrors('local');
    $this->post('/mail/lists', la_body(['local' => 'ok', 'domain' => 'fremd.de']))->assertSessionHasErrors('local');
    $this->post('/mail/lists', la_body(['local' => 'Böse Adresse']))->assertSessionHasErrors('local');
    $this->post('/mail/lists', la_body(['local' => 'ok', 'senders' => 'jeder']))->assertSessionHasErrors('senders');
    $this->post('/mail/lists', la_body(['local' => 'ok', 'kind' => 'gemischt']))->assertSessionHasErrors('kind');
    $this->post('/mail/lists', la_body(['local' => ['ok']]))->assertSessionHasErrors('local');
    $this->post('/mail/lists', la_body(['local' => 'ok', 'name' => '']))->assertSessionHasErrors('name');
    $this->post('/mail/lists', la_body(['local' => 'ok', 'name' => str_repeat('x', 151)]))->assertSessionHasErrors('name');
    expect(MailList::query()->count())->toBe(1);

    $list = MailList::query()->sole();
    $this->put("/mail/lists/{$list->id}", la_body(['local' => 'liste', 'name' => 'Liste neu']))->assertRedirect('/mail/lists');
    expect($list->refresh()->name)->toBe('Liste neu');
});

it('answers a lost address race as a validation error, never a database error', function () {
    MailList::creating(function (MailList $l) {
        DB::table('mail_lists')->insert(['address' => $l->address, 'name' => 'Rennen', 'kind' => 'static', 'senders' => 'all', 'created_at' => now(), 'updated_at' => now()]);
    });

    $this->post('/mail/lists', la_body())->assertSessionHasErrors('local');
    expect(MailList::query()->pluck('name')->all())->toBe([]);
});

it('lets nobody without mail.lists.manage manage lists', function () {
    $list = MailList::query()->forceCreate(['address' => 'x@ignis.ef', 'name' => 'X']);
    $this->actingAs(ml_user(['mail.use', 'mail.admin']));

    $this->get('/mail/lists')->assertForbidden();
    $this->get('/mail/lists/create')->assertForbidden();
    $this->get("/mail/lists/{$list->id}/edit")->assertForbidden();
    $this->post('/mail/lists', la_body())->assertForbidden();
    $this->put("/mail/lists/{$list->id}", la_body())->assertForbidden();
    $this->delete("/mail/lists/{$list->id}")->assertForbidden();
    expect(MailList::query()->count())->toBe(1)->and(la_audit())->toBe([]);

    auth()->logout();
    $this->get('/mail/lists')->assertRedirect('/login');
});

it('answers every list page without caching', function () {
    $this->get('/mail/lists')->assertHeader('Cache-Control', 'no-store, private');
});

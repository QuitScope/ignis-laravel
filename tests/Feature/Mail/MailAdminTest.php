<?php

use App\Events\PersonnelSaved;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\AddressRules;
use Modules\Mail\Support\SignatureTemplate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.allowed_domains', ['lspd.de']);
    $this->admin = ml_member('Ada Admin', ['mail.use', 'mail.admin']);
});

function ma_as(array $admin, bool $chooseDomain = false): void
{
    $perms = $chooseDomain ? ['mail.use', 'mail.admin', 'mail.domain.choose'] : ['mail.use', 'mail.admin'];
    $admin['user']->roles()->first()->syncPermissions($perms);
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs($admin['user']->fresh());
}

/** @return list<array{action: string, details: ?string, context: array<string, mixed>}> */
function ma_audit(User $by): array
{
    return Activity::query()->where('log_name', 'Mail')->where('causer_id', $by->id)->orderBy('id')->get()
        ->map(fn (Activity $a) => ['action' => $a->description, 'details' => $a->details, 'context' => (array) $a->properties->get('context')])->all();
}

it('changes an address and keeps the old one reserved as an alias of the same mailbox', function () {
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang')['mailbox'];
    ma_as($this->admin);

    $this->get('/settings/mail/mailboxes')->assertOk()->assertInertia(fn ($p) => $p->component('Mail/Admin/Mailboxes')
        ->where('mailboxes.data', fn ($rows) => collect($rows)->pluck('address')->contains('b.empfang@ignis.ef')));
    $this->get("/settings/mail/mailboxes/{$bob->id}/edit")->assertOk()->assertInertia(fn ($p) => $p->component('Mail/Admin/Edit')
        ->where('mailbox.local', 'b.empfang')->where('canChooseDomain', false)->where('isOwn', false));

    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => 'bob.e', 'domain' => 'lspd.de'])->assertRedirect('/settings/mail/mailboxes');
    expect($bob->refresh()->address)->toBe('bob.e@ignis.ef')
        ->and(AddressRules::isTaken('b.empfang@ignis.ef'))->toBeTrue()
        ->and(AddressRules::isTaken('b.empfang@ignis.ef', $bob->id))->toBeFalse();

    $this->actingAs($alice['user']);
    $id = ml_send($this, ['to' => ['b.empfang@ignis.ef']])->json('messageId');
    expect(MailDelivery::query()->where('message_id', $id)->where('mailbox_id', $bob->id)->where('role', 'to')->exists())->toBeTrue();

    $carla = ml_member('Carla Kopie')['mailbox'];
    ma_as($this->admin);
    $this->put("/settings/mail/mailboxes/{$carla->id}", ['local' => 'b.empfang'])->assertSessionHasErrors('local');
    expect($carla->refresh()->address)->toBe('c.kopie@ignis.ef');

    ma_as($this->admin, chooseDomain: true);
    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => 'bob.e', 'domain' => 'fremd.de'])->assertSessionHasErrors('local');
    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => 'bob.e', 'domain' => 'lspd.de'])->assertRedirect('/settings/mail/mailboxes');
    expect($bob->refresh()->address)->toBe('bob.e@lspd.de')->and($bob->domain)->toBe('lspd.de');

    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => 'b.empfang', 'domain' => 'ignis.ef'])->assertRedirect('/settings/mail/mailboxes');
    expect($bob->refresh()->address)->toBe('b.empfang@ignis.ef')
        ->and(DB::table('mail_address_history')->where('mailbox_id', $bob->id)->orderBy('address')->pluck('address')->all())->toBe(['bob.e@ignis.ef', 'bob.e@lspd.de']);

    $audit = ma_audit($this->admin['user']);
    expect(array_column($audit, 'action'))->toBe(['Postfach-Adresse geändert', 'Postfach-Domain geändert', 'Postfach-Domain geändert'])
        ->and($audit[0]['context'])->toBe(['mailbox_id' => $bob->id, 'from' => 'b.empfang@ignis.ef', 'to' => 'bob.e@ignis.ef']);
});

it('never changes, locks or unlocks the own mailbox and refuses arrays and bad addresses', function () {
    ma_as($this->admin, chooseDomain: true);
    $own = $this->admin['mailbox'];

    $this->get("/settings/mail/mailboxes/{$own->id}/edit")->assertOk()->assertInertia(fn ($p) => $p->where('isOwn', true));
    $this->put("/settings/mail/mailboxes/{$own->id}", ['local' => 'chef'])->assertSessionHasErrors('mailbox');
    expect($own->refresh()->address)->toBe('a.admin@ignis.ef');

    $this->patch("/settings/mail/mailboxes/{$own->id}/lock", ['locked' => true])->assertSessionHasErrors('mailbox');
    expect($own->refresh()->is_locked)->toBeFalse();
    $own->forceFill(['is_locked' => true])->save();
    $this->patch("/settings/mail/mailboxes/{$own->id}/lock", ['locked' => false])->assertSessionHasErrors('mailbox');
    expect($own->refresh()->is_locked)->toBeTrue();
    $own->forceFill(['is_locked' => false])->save();

    $bob = ml_member('Bob Empfang')['mailbox'];
    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => ['x']])->assertSessionHasErrors('local');
    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => 'Böse Adresse'])->assertSessionHasErrors('local');
    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => 'ok', 'domain' => ['x']])->assertSessionHasErrors('domain');
    $this->put("/settings/mail/mailboxes/{$bob->id}", ['local' => 'b.empfang'])->assertRedirect('/settings/mail/mailboxes')
        ->assertSessionHas('status', 'mail.admin.unchanged');
    expect($bob->refresh()->address)->toBe('b.empfang@ignis.ef')->and(ma_audit($this->admin['user']))->toBe([]);
});

it('locks and unlocks without any view into mail; the lock is never undone by a save or the backfill', function () {
    $alice = ml_member('Alice Absender');
    $bob = ml_member('Bob Empfang');
    $this->actingAs($alice['user']);
    $sent = ml_send($this, ['subject' => 'Geheimer Betreff', 'body_json' => ml_doc('Geheimer Text'), 'to' => [$bob['mailbox']->address]])->json('messageId');

    ma_as($this->admin);
    foreach (['/settings/mail/mailboxes', "/settings/mail/mailboxes/{$bob['mailbox']->id}/edit"] as $page) {
        expect($this->get($page)->assertOk()->getContent())->not->toContain('Geheim');
    }
    $this->get("/mail/inbox/$sent?mailbox={$bob['mailbox']->id}")->assertNotFound();

    $this->patch("/settings/mail/mailboxes/{$bob['mailbox']->id}/lock", ['locked' => true])->assertRedirect('/settings/mail/mailboxes');
    expect($bob['mailbox']->refresh()->is_locked)->toBeTrue()->and($bob['mailbox']->is_active)->toBeTrue();

    event(new PersonnelSaved($bob['person']->id));
    $this->artisan('mail:backfill')->assertSuccessful();
    expect($bob['mailbox']->refresh()->is_locked)->toBeTrue();
    $this->actingAs($alice['user']);
    $draft = ml_draft($this);
    $this->postJson("/mail/drafts/$draft/send", ['to' => [$bob['mailbox']->address]])->assertUnprocessable()->assertSee($bob['mailbox']->address);
    $this->actingAs($bob['user'])->get('/mail')->assertInertia(fn ($p) => $p->where('selected', null));

    ma_as($this->admin);
    $this->patch("/settings/mail/mailboxes/{$bob['mailbox']->id}/lock", ['locked' => false])->assertRedirect('/settings/mail/mailboxes');
    expect($bob['mailbox']->refresh()->is_locked)->toBeFalse();
    $this->patch("/settings/mail/mailboxes/{$bob['mailbox']->id}/lock", ['locked' => 'ja'])->assertSessionHasErrors('locked');

    $audit = ma_audit($this->admin['user']);
    expect(array_column($audit, 'action'))->toBe(['Postfach gesperrt', 'Postfach entsperrt'])
        ->and(json_encode($audit))->not->toContain('Geheim');
});

it('lists mailbox metadata only, filtered by kind and state, 50 a page', function () {
    $alice = ml_member('Alice Absender');
    $locked = ml_member('Lars Gesperrt')['mailbox'];
    $locked->forceFill(['is_locked' => true])->save();
    $inactive = ml_member('Ina Inaktiv')['mailbox'];
    $inactive->forceFill(['is_active' => false])->save();
    $group = ml_group('Leitstelle', 'leitstelle', [$alice['user']]);
    $this->actingAs($alice['user']);
    ml_send($this, ['subject' => 'Geheim', 'to' => [$group->address]]);

    ma_as($this->admin);
    $this->get('/settings/mail/mailboxes?kind=group')->assertInertia(fn ($p) => $p->where('mailboxes.data', [[
        'id' => $group->id, 'kind' => 'group', 'address' => 'leitstelle@ignis.ef', 'display_name' => 'Leitstelle', 'is_active' => true,
        'is_locked' => false, 'members_count' => 1, 'owner' => null, 'account' => null, 'is_own' => false,
    ]])->where('filters', ['kind' => 'group', 'state' => null, 'q' => '']));
    $this->get('/settings/mail/mailboxes?state=locked')->assertInertia(fn ($p) => $p->where('mailboxes.data', fn ($r) => collect($r)->pluck('id')->all() === [$locked->id]));
    $this->get('/settings/mail/mailboxes?state=inactive')->assertInertia(fn ($p) => $p->where('mailboxes.data', fn ($r) => collect($r)->pluck('id')->all() === [$inactive->id]));
    $this->get('/settings/mail/mailboxes?state=active&kind=personal')->assertInertia(fn ($p) => $p->where('mailboxes.data', fn ($r) => collect($r)->pluck('id')->sort()->values()->all() === [$this->admin['mailbox']->id, $alice['mailbox']->id]));
    $this->get('/settings/mail/mailboxes?q=leit')->assertInertia(fn ($p) => $p->where('mailboxes.data', fn ($r) => collect($r)->pluck('id')->all() === [$group->id]));
    $this->get('/settings/mail/mailboxes?kind=nonsense&state=nonsense')->assertSessionHasErrors(['kind', 'state']);

    foreach (range(1, 50) as $i) {
        ml_group("Gruppe $i", "gruppe$i");
    }
    $this->get('/settings/mail/mailboxes')->assertInertia(fn ($p) => $p->has('mailboxes.data', 50)->where('mailboxes.total', 55));
});

it('checks every setting, lets only mail.domain.choose change the domain and logs no signature text', function () {
    ma_as($this->admin);
    $this->get('/settings/mail')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn ($p) => $p->component('Mail/Admin/Settings')
        ->where('settings', ['domain' => 'ignis.ef', 'address_pattern' => 'initial_dot_last', 'allowed_domains' => 'lspd.de', 'send_cooldown' => 10])
        ->where('signature', SignatureTemplate::builtIn())->where('canChooseDomain', false)->has('variables')->has('values'));

    $sig = fn (array ...$inline) => json_encode(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mit Gruß']]],
        ['type' => 'paragraph', 'attrs' => ['textAlign' => null], 'content' => $inline],
    ]]);
    $valid = ['domain' => 'Feuerwehr.test', 'address_pattern' => 'first_dot_last', 'allowed_domains' => 'lspd.de, rettung.test', 'signature' => $sig(['type' => 'text', 'text' => 'Wache 1 ']), 'send_cooldown' => '15'];

    ma_as($this->admin, chooseDomain: true);
    $this->put('/settings/mail', ['domain' => 'kaputt'] + $valid)->assertSessionHasErrors('domain');
    $this->put('/settings/mail', ['domain' => ['x']] + $valid)->assertSessionHasErrors('domain');
    $this->put('/settings/mail', ['address_pattern' => 'nachname'] + $valid)->assertSessionHasErrors('address_pattern');
    $this->put('/settings/mail', ['allowed_domains' => 'gut.de, -schlecht'] + $valid)->assertSessionHasErrors('allowed_domains');
    $this->put('/settings/mail', ['signature' => $sig(['type' => 'text', 'text' => str_repeat('x', 17000)])] + $valid)->assertSessionHasErrors('signature');
    $this->put('/settings/mail', ['signature' => $sig(['type' => 'docVariable', 'attrs' => ['name' => 'absender.passwort']])] + $valid)->assertSessionHasErrors('signature');
    $this->put('/settings/mail', ['signature' => $sig(['type' => 'image', 'attrs' => ['src' => '/a.png']])] + $valid)->assertSessionHasErrors('signature');
    $this->put('/settings/mail', ['signature' => "Mit Gruß\nWache 1"] + $valid)->assertSessionHasErrors('signature');
    $this->put('/settings/mail', ['signature' => ['x']] + $valid)->assertSessionHasErrors('signature');
    foreach (['-1', '3601', 'zehn', '1.5', ''] as $cooldown) {
        $this->put('/settings/mail', ['send_cooldown' => $cooldown] + $valid)->assertSessionHasErrors('send_cooldown');
    }
    expect(ma_audit($this->admin['user']))->toBe([]);

    $this->put('/settings/mail', $valid)->assertRedirect('/settings/mail')->assertSessionHas('status', 'mail.settings.saved');
    $s = app(Settings::class);
    expect($s->get('mail.domain'))->toBe('feuerwehr.test')
        ->and($s->get('mail.address_pattern'))->toBe('first_dot_last')
        ->and($s->get('mail.allowed_domains'))->toBe(['lspd.de', 'rettung.test'])
        ->and($s->get('mail.send_cooldown'))->toBe(15)
        ->and(SignatureTemplate::default())->toBe(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mit Gruß']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Wache 1 ']]],
        ]]);

    $audit = ma_audit($this->admin['user']);
    expect($audit)->toHaveCount(1)->and($audit[0]['action'])->toBe('Mail-Einstellungen geändert')
        ->and($audit[0]['details'])->toContain('mail.signature')
        ->and(json_encode($audit[0]['context']))->not->toContain('Wache')
        ->and($audit[0]['context']['mail.domain'])->toBe(['from' => 'ignis.ef', 'to' => 'feuerwehr.test']);

    expect(ml_member('Max Muster')['mailbox']->address)->toBe('max.muster@feuerwehr.test');

    $this->put('/settings/mail', ['signature' => json_encode(SignatureTemplate::builtIn())] + $valid)->assertRedirect('/settings/mail');
    expect(MailSignature::query()->whereNull('mailbox_id')->count())->toBe(0);
    $this->put('/settings/mail', ['signature' => json_encode(SignatureTemplate::builtIn())] + $valid)->assertSessionHas('status', 'mail.admin.unchanged');

    ma_as($this->admin);
    $this->put('/settings/mail', ['domain' => 'anders.test', 'send_cooldown' => '20'] + $valid)->assertRedirect('/settings/mail');
    expect($s->get('mail.domain'))->toBe('feuerwehr.test')->and($s->get('mail.send_cooldown'))->toBe(20);
});

it('shows the stored default signature, legacy plain text as paragraphs', function () {
    ma_as($this->admin);
    SignatureTemplate::saveDefault(SignatureTemplate::decode("Wache 1\nLeitstelle"));

    $this->get('/settings/mail')->assertInertia(fn ($p) => $p->where('signature', ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Wache 1']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Leitstelle']]],
    ]]));
});

it('keeps every admin route closed without mail.admin', function () {
    $bob = ml_member('Bob Empfang');
    $group = ml_group('Leitstelle', 'leitstelle');
    $this->actingAs(ml_user(['mail.use', 'mail.lists.manage', 'mail.domain.choose']));

    $this->get('/settings/mail')->assertForbidden();
    $this->put('/settings/mail', ['domain' => 'x.test'])->assertForbidden();
    $this->get('/settings/mail/mailboxes')->assertForbidden();
    $this->get('/settings/mail/mailboxes/groups/create')->assertForbidden();
    $this->post('/settings/mail/mailboxes/groups', ['name' => 'X', 'local' => 'x'])->assertForbidden();
    $this->get("/settings/mail/mailboxes/{$bob['mailbox']->id}/edit")->assertForbidden();
    $this->put("/settings/mail/mailboxes/{$bob['mailbox']->id}", ['local' => 'x'])->assertForbidden();
    $this->patch("/settings/mail/mailboxes/{$group->id}/name", ['name' => 'X'])->assertForbidden();
    $this->patch("/settings/mail/mailboxes/{$bob['mailbox']->id}/lock", ['locked' => true])->assertForbidden();
    $this->put("/settings/mail/mailboxes/{$bob['mailbox']->id}/account", ['user_id' => $bob['user']->id])->assertForbidden();
    $this->delete("/settings/mail/mailboxes/{$bob['mailbox']->id}/account")->assertForbidden();
    $this->post("/settings/mail/mailboxes/{$group->id}/members", ['user_id' => $bob['user']->id])->assertForbidden();
    $this->delete("/settings/mail/mailboxes/{$group->id}/members/{$bob['user']->id}")->assertForbidden();

    expect($bob['mailbox']->refresh()->is_locked)->toBeFalse()->and($bob['mailbox']->address)->toBe('b.empfang@ignis.ef')
        ->and($group->members()->count())->toBe(0)->and(Mailbox::query()->count())->toBe(3);

    auth()->logout();
    $this->get('/settings/mail')->assertRedirect('/login');
    $this->get('/settings/mail/mailboxes')->assertRedirect('/login');
});

<?php

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Specialty;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\AddressHistory;
use Modules\Mail\Models\MailList;
use Modules\Mail\Support\RecipientResolver;
use Tests\TestCase;

require_once __DIR__.'/../../Feature/Mail/mailHelpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function rr(array $raw): array
{
    $r = app(RecipientResolver::class)->resolve($raw);
    $roles = $r->roles;
    ksort($roles);

    return ['roles' => $roles, 'unknown' => $r->unknown];
}

function rr_list(string $address, array $over = []): MailList
{
    return MailList::query()->forceCreate($over + ['address' => $address, 'name' => ucfirst(strtok($address, '@'))]);
}

/** @return list<string> addresses a list resolves to */
function rr_members(MailList $list): array
{
    $ids = array_keys(app(RecipientResolver::class)->resolve(['to' => [$list->address]])->roles);

    return DB::table('mail_mailboxes')->whereIn('id', $ids)->orderBy('address')->pluck('address')->all();
}

it('resolves current addresses and old ones (alias), case-insensitive, and collects unknown ones', function () {
    $bob = ml_member('Bob Empf')['mailbox'];
    AddressHistory::query()->forceCreate(['address' => 'bob.alt@ignis.ef', 'mailbox_id' => $bob->id, 'released_at' => now()]);

    expect(rr(['to' => [' B.Empf@IGNIS.ef ', 'bob.alt@ignis.ef', 'niemand@ignis.ef'], 'cc' => ['Niemand@ignis.ef', 'x@y.ef']]))
        ->toBe(['roles' => [$bob->id => 'to'], 'unknown' => ['niemand@ignis.ef', 'x@y.ef']]);
});

it('treats inactive and locked mailboxes, also behind an old address, as unknown', function () {
    $locked = ml_member('Lars Lock')['mailbox'];
    $inactive = ml_member('Ina Aktiv')['mailbox'];
    $locked->forceFill(['is_locked' => true])->save();
    $inactive->forceFill(['is_active' => false])->save();
    AddressHistory::query()->forceCreate(['address' => 'lars.alt@ignis.ef', 'mailbox_id' => $locked->id]);

    expect(rr(['to' => [$locked->address, $inactive->address, 'lars.alt@ignis.ef']]))
        ->toBe(['roles' => [], 'unknown' => [$locked->address, $inactive->address, 'lars.alt@ignis.ef']]);
});

it('keeps one copy per mailbox with the highest role: to over cc over bcc', function () {
    $a = ml_member('Anna A')['mailbox'];
    $b = ml_member('Bert B')['mailbox'];
    $c = ml_member('Cleo C')['mailbox'];
    $list = rr_list('team@ignis.ef');
    $list->mailboxes()->attach([$a->id, $b->id, $c->id]);

    expect(rr(['to' => [$b->address], 'cc' => ['team@ignis.ef'], 'bcc' => [$a->address, $b->address]]))
        ->toBe(['roles' => [$a->id => 'cc', $b->id => 'to', $c->id => 'cc'], 'unknown' => []]);
});

it('gives static lists only their deliverable members and never nests lists', function () {
    $a = ml_member('Anna Aktiv')['mailbox'];
    $b = ml_member('Bert Gesperrt')['mailbox'];
    $c = ml_member('Cleo Inaktiv')['mailbox'];
    $b->forceFill(['is_locked' => true])->save();
    $c->forceFill(['is_active' => false])->save();
    $list = rr_list('team@ignis.ef');
    $list->mailboxes()->attach([$a->id, $b->id, $c->id]);

    expect(rr_members($list))->toBe([$a->address])
        ->and(rr(['to' => [rr_list('leer@ignis.ef')->address]]))->toBe(['roles' => [], 'unknown' => []]);
});

it('resolves a dynamic rule by any role of the account, rank and qualifications', function () {
    $role = Role::create(['name' => 'Wache', 'guard_name' => 'web', 'priority' => 400, 'color' => 'info']);
    $rank = Rank::factory()->create();
    $ems = EmsQualification::create(['priority' => 1, 'name' => 'NotSan', 'name_male' => 'NotSan', 'name_female' => 'NotSan']);
    $fire = FireQualification::create(['priority' => 1, 'short_name' => 'TF', 'name' => 'TF', 'name_male' => 'TF', 'name_female' => 'TF']);

    $byRole = ml_member('Rolf Rolle');
    $byRole['user']->assignRole($role);
    $byRank = ml_provision(ml_person('Dina Dienstgrad', ['rank_id' => $rank->id]));
    $byEms = ml_provision(ml_person('Rita Rd', ['ems_qualification_id' => $ems->id]));
    $byFire = ml_provision(ml_person('Falk Fw', ['fire_qualification_id' => $fire->id]));
    ml_provision(ml_person('Nils Nichts'));
    $locked = ml_provision(ml_person('Lars Gesperrt', ['rank_id' => $rank->id]));
    $locked->forceFill(['is_locked' => true])->save();

    $list = rr_list('dyn@ignis.ef', ['kind' => 'dynamic', 'rule' => [
        'role' => [$role->id], 'rank' => [$rank->id], 'ems_qualification' => [$ems->id], 'fire_qualification' => [$fire->id],
    ]]);

    expect(rr_members($list))->toBe(collect([$byRole['mailbox'], $byRank, $byEms, $byFire])->pluck('address')->sort()->values()->all())
        ->and(rr_members(rr_list('leer@ignis.ef', ['kind' => 'dynamic', 'rule' => []])))->toBe([])
        ->and(rr_members(rr_list('null@ignis.ef', ['kind' => 'dynamic', 'rule' => null])))->toBe([]);
});

it('resolves a dynamic rule by specialty over the pivot; a specialty nobody has means nobody', function () {
    $breathing = Specialty::create(['number' => 901, 'name' => 'Atemschutz']);
    $hazmat = Specialty::create(['number' => 902, 'name' => 'Gefahrgut']);
    $diver = Specialty::create(['number' => 904, 'name' => 'Taucher']);

    $with = ml_person('Anja Atemschutz');
    $with->specialties()->attach([$breathing->id, $hazmat->id]);
    $other = ml_person('Gero Gefahrgut');
    $other->specialties()->attach($hazmat->id);
    $without = ml_person('Olaf Ohne');
    foreach ([$with, $other, $without] as $person) {
        ml_provision($person);
    }

    expect(rr_members(rr_list('as@ignis.ef', ['kind' => 'dynamic', 'rule' => ['specialty' => [$breathing->id]]])))->toBe(['a.atemschutz@ignis.ef'])
        ->and(rr_members(rr_list('gg@ignis.ef', ['kind' => 'dynamic', 'rule' => ['specialty' => [$hazmat->id]]])))->toBe(['a.atemschutz@ignis.ef', 'g.gefahrgut@ignis.ef'])
        ->and(rr_members(rr_list('tau@ignis.ef', ['kind' => 'dynamic', 'rule' => ['specialty' => [$diver->id]]])))->toBe([]);
});

it('reports the lists it used', function () {
    $list = rr_list('leitung@ignis.ef', ['senders' => 'managers']);

    expect(app(RecipientResolver::class)->resolve(['bcc' => ['Leitung@ignis.ef']])->lists->pluck('address')->all())->toBe(['leitung@ignis.ef']);
});

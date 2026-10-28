<?php

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function personnelUser(string ...$perms): User
{
    $role = Role::create(['name' => 'P'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function person(array $attrs = [], array $rank = []): Personnel
{
    return Personnel::factory()->create($attrs + ['rank_id' => Rank::factory()->create($rank)->id]);
}

function listRows(mixed $test, User $u, string $qs): array
{
    return $test->actingAs($u)->get('/personnel?'.$qs)->viewData('page')['props']['personnel']['data'];
}

it('requires login and personnel.view for the list and the export', function () {
    $this->get('/personnel')->assertRedirect('/login');
    $this->actingAs(personnelUser('audit.view'))->get('/personnel')->assertForbidden();
    $this->actingAs(personnelUser('audit.view'))->get('/personnel?export=csv')->assertForbidden();
    $this->actingAs(personnelUser('personnel.view'))->get('/personnel')->assertOk();
    $this->actingAs(personnelUser('admin'))->get('/personnel')->assertOk();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/personnel')->assertOk();
});

it('lists active people by default and archived ones with archive=1', function () {
    person(['full_name' => 'Aktiv Eins']);
    person(['full_name' => 'Archiv Eins'], ['is_archive' => true]);
    $u = personnelUser('personnel.view');

    $this->actingAs($u)->get('/personnel')->assertInertia(fn (Assert $p) => $p
        ->component('Personnel/Index')->has('personnel.data', 1)->where('personnel.data.0.full_name', 'Aktiv Eins')->where('filters.archive', false));
    $this->actingAs($u)->get('/personnel?archive=1')->assertInertia(fn (Assert $p) => $p
        ->has('personnel.data', 1)->where('personnel.data.0.full_name', 'Archiv Eins')->where('filters.archive', true));
});

it('searches name and service number and treats % and _ literally', function () {
    person(['full_name' => 'Max Muster', 'service_number' => 'BF-10']);
    person(['full_name' => '100% Mann', 'service_number' => 'BF-20']);
    person(['full_name' => 'A_B', 'service_number' => 'BF-30']);
    person(['full_name' => 'AxB', 'service_number' => 'BF-40']);
    $u = personnelUser('personnel.view');

    $names = fn (string $q) => collect(listRows($this, $u, 'search='.urlencode($q)))->pluck('full_name')->all();
    expect($names('muster'))->toBe(['Max Muster'])
        ->and($names('BF-20'))->toBe(['100% Mann'])
        ->and($names('%'))->toBe(['100% Mann'])
        ->and($names('A_B'))->toBe(['A_B']);
});

it('filters by rank, ems and fire qualification', function () {
    $fire = FireQualification::create(['priority' => 1, 'short_name' => 'GF', 'name' => 'GF', 'name_male' => 'GF', 'name_female' => 'GF', 'is_none' => false]);
    $ems = EmsQualification::create(['priority' => 1, 'name' => 'NFS', 'name_male' => 'NFS', 'name_female' => 'NFS', 'is_none' => false]);
    $a = person(['full_name' => 'A', 'fire_qualification_id' => $fire->id]);
    $b = person(['full_name' => 'B', 'ems_qualification_id' => $ems->id]);
    $c = person(['full_name' => 'C']);
    $u = personnelUser('personnel.view');

    $ids = fn (string $qs) => collect(listRows($this, $u, $qs))->pluck('id')->all();
    expect($ids('fire='.$fire->id))->toBe([$a->id])
        ->and($ids('ems='.$ems->id))->toBe([$b->id])
        ->and($ids('rank='.$c->rank_id))->toBe([$c->id]);
});

it('sorts by every column in both directions', function (string $sort) {
    $r1 = Rank::factory()->create(['priority' => 1]);
    $r2 = Rank::factory()->create(['priority' => 2]);
    $f = FireQualification::create(['priority' => 5, 'short_name' => 'X', 'name' => 'X', 'name_male' => 'X', 'name_female' => 'X', 'is_none' => false]);
    $e = EmsQualification::create(['priority' => 5, 'name' => 'X', 'name_male' => 'X', 'name_female' => 'X', 'is_none' => false]);
    Personnel::factory()->create(['full_name' => 'a lo', 'service_number' => 'A-1', 'hired_at' => '2020-01-01', 'rank_id' => $r1->id]);
    Personnel::factory()->create(['full_name' => 'b hi', 'service_number' => 'B-2', 'hired_at' => '2021-01-01', 'rank_id' => $r2->id, 'fire_qualification_id' => $f->id, 'ems_qualification_id' => $e->id]);
    $u = personnelUser('personnel.view');

    $names = fn (string $dir) => collect(listRows($this, $u, "sort={$sort}&dir={$dir}"))->pluck('full_name')->all();
    expect($names('asc'))->toBe(['a lo', 'b hi'])->and($names('desc'))->toBe(['b hi', 'a lo']);
})->with(['service_number', 'name', 'rank', 'ems', 'fire', 'hired_at']);

it('falls back to the default order for an unknown sort', function () {
    person(['full_name' => 'x']);
    $this->actingAs(personnelUser('personnel.view'))->get('/personnel?sort=password&dir=sideways')->assertOk();
});

it('paginates 25 per page and keeps the query string', function () {
    Personnel::factory()->count(27)->create(['rank_id' => Rank::factory()->create()->id]);

    $this->actingAs(personnelUser('personnel.view'))->get('/personnel?search=&sort=name')->assertInertia(fn (Assert $p) => $p
        ->has('personnel.data', 25)->where('personnel.total', 27)->where('personnel.next_page_url', fn ($url) => str_contains($url, 'sort=name')));
});

it('exports the filtered list as CSV and neutralises formulas', function () {
    $rank = Rank::factory()->create(['name_male' => 'Brandmeister', 'name_female' => 'Brandmeisterin']);
    person(['full_name' => '=cmd|calc', 'service_number' => '+1', 'gender' => 1, 'hired_at' => '2022-03-04', 'rank_id' => $rank->id]);
    person(['full_name' => "\tTab", 'service_number' => '@2', 'rank_id' => $rank->id]);
    person(['full_name' => 'Andere', 'service_number' => 'ZZ-9'], ['is_archive' => true]);

    $r = $this->actingAs(personnelUser('personnel.view'))->get('/personnel?export=csv&sort=service_number');
    $r->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($r->headers->get('Content-Disposition'))->toContain('mitarbeiter_export_');
    $csv = $r->getContent();
    expect($csv)->toStartWith("\xEF\xBB\xBFDienstnummer;Name;Dienstgrad;RD-Qualifikation;FW-Qualifikation;Einstellungsdatum\n")
        ->toContain('"\'+1";"\'=cmd|calc";"Brandmeisterin";"-";"-";"04.03.2022"')
        ->toContain("\"'@2\";\"'\tTab\"")
        ->not->toContain('ZZ-9');
});

it('guards CSV cells that start with a dash or a carriage return', function () {
    person(['full_name' => '-1+2', 'service_number' => 'A-1']);
    person(['full_name' => "\rCR", 'service_number' => 'A-2']);

    $csv = $this->actingAs(personnelUser('personnel.view'))->get('/personnel?export=csv&sort=service_number')->getContent();

    expect($csv)->toContain("\"A-1\";\"'-1+2\"")->toContain("\"A-2\";\"'\rCR\"");
});

it('does not fail on array-valued query parameters', function () {
    person();

    $this->actingAs(personnelUser('personnel.view'))
        ->get('/personnel?search[]=x&sort[]=name&dir[]=desc&export[]=csv&rank[]=1')->assertOk();
});

it('shows the create and delete flags only to people who may', function () {
    person();
    $this->actingAs(personnelUser('personnel.view'))->get('/personnel')->assertInertia(fn (Assert $p) => $p
        ->where('can.create', false)->where('personnel.data.0.can_delete', false));
    $this->actingAs(personnelUser('personnel.view', 'personnel.edit', 'personnel.delete'))->get('/personnel')->assertInertia(fn (Assert $p) => $p
        ->where('can.create', true)->where('personnel.data.0.can_delete', true));
});

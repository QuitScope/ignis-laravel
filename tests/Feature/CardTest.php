<?php

use App\Models\Personnel;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function cd_user(array $permissions): User
{
    $role = Role::create(['name' => 'C'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->givePermissionTo(array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'), $permissions,
    ));

    return User::factory()->withRole($role)->create();
}

function cd_person(array $attrs = []): Personnel
{
    return Personnel::factory()->create($attrs + [
        'full_name' => 'Erika Muster', 'phone' => '555-0100', 'discord_id' => '123456789012345678', 'gender' => 1,
        'service_number' => 'BF-42', 'rank_id' => Rank::factory()->create(['name_female' => 'Brandmeisterin', 'badge' => 'bm.png'])->id,
    ]);
}

it('returns the card without phone or discord id, privately cacheable for 60 seconds', function () {
    $person = cd_person();
    $res = $this->actingAs(cd_user(['personnel.view']))->getJson("/api/personnel/{$person->id}/card");

    $res->assertOk()->assertExactJson([
        'id' => $person->id,
        'name' => 'Erika Muster',
        'rank' => ['label' => 'Brandmeisterin', 'badge' => 'bm.png'],
        'service_number' => 'BF-42',
        'ems' => null,
        'fire' => null,
        'picture_url' => null,
        'linked_account' => null,
    ]);
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=60');
    expect($res->getContent())->not->toContain('555-0100')->not->toContain('123456789012345678');
});

it('shows the picture url and, only to users viewers, the linked account', function () {
    $person = cd_person(['profile_picture' => str_repeat('a', 32).'.png']);
    User::factory()->create(['username' => 'erika', 'personnel_id' => $person->id]);

    $this->actingAs(cd_user(['personnel.view']))->getJson("/api/personnel/{$person->id}/card")
        ->assertOk()->assertJsonPath('picture_url', '/storage/profile-pictures/'.str_repeat('a', 32).'.png')
        ->assertJsonPath('linked_account', null);
    $this->actingAs(cd_user(['personnel.view', 'users.view']))->getJson("/api/personnel/{$person->id}/card")
        ->assertJsonPath('linked_account.username', 'erika');
});

it('looks the card up by service number', function () {
    $person = cd_person();
    $this->actingAs(cd_user(['personnel.view']))->getJson('/api/personnel/by-dienstnr/BF-42/card')
        ->assertOk()->assertJsonPath('id', $person->id);
});

it('answers a missing record with the same 404 for ids and service numbers', function () {
    $viewer = cd_user(['personnel.view', 'users.view']);
    $bodies = [
        $this->actingAs($viewer)->getJson('/api/personnel/9999/card'),
        $this->actingAs($viewer)->getJson('/api/personnel/by-dienstnr/nope/card'),
        $this->actingAs($viewer)->getJson('/api/users/9999/card'),
    ];
    foreach ($bodies as $res) {
        $res->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
});

it('requires a login and the view right (403 before any lookup)', function () {
    $person = cd_person();
    $this->getJson("/api/personnel/{$person->id}/card")->assertUnauthorized();
    $nobody = cd_user([]);
    $this->actingAs($nobody)->getJson("/api/personnel/{$person->id}/card")->assertForbidden();
    $this->actingAs($nobody)->getJson('/api/personnel/9999/card')->assertForbidden();
    $this->actingAs($nobody)->getJson('/api/personnel/by-dienstnr/BF-42/card')->assertForbidden();
    $this->actingAs($nobody)->getJson('/api/users/'.$nobody->id.'/card')->assertForbidden();
    $this->actingAs(cd_user(['personnel.view']))->getJson('/api/users/'.$nobody->id.'/card')->assertForbidden();
});

it('shows the account card with the linked record only to those who may see personnel', function () {
    $person = cd_person();
    $account = User::factory()->create(['username' => 'erika', 'name' => 'Erika D', 'personnel_id' => $person->id]);

    $this->actingAs(cd_user(['users.view', 'personnel.view']))->getJson("/api/users/{$account->id}/card")
        ->assertOk()->assertJsonPath('id', $person->id)->assertJsonPath('name', 'Erika Muster')
        ->assertJsonPath('rank.label', 'Brandmeisterin')->assertJsonPath('linked_account.username', 'erika');

    $res = $this->actingAs(cd_user(['users.view']))->getJson("/api/users/{$account->id}/card")->assertOk();
    $res->assertExactJson([
        'id' => null, 'name' => 'Erika D', 'rank' => null, 'service_number' => null, 'ems' => null, 'fire' => null,
        'picture_url' => null, 'linked_account' => ['username' => 'erika'],
    ]);
});

it('shows an account without a record as a bare card', function () {
    $account = User::factory()->create(['username' => 'solo', 'name' => null]);
    $this->actingAs(cd_user(['users.view', 'personnel.view']))->getJson("/api/users/{$account->id}/card")
        ->assertOk()->assertJsonPath('name', 'solo')->assertJsonPath('id', null)->assertJsonPath('rank', null);
});

it('throttles the cards to 120 per minute', function () {
    $person = cd_person();
    $viewer = cd_user(['personnel.view']);
    RateLimiter::clear('x');
    foreach (range(1, 120) as $_) {
        $this->actingAs($viewer)->getJson("/api/personnel/{$person->id}/card")->assertOk();
    }
    $this->actingAs($viewer)->getJson("/api/personnel/{$person->id}/card")->assertStatus(429);
});

it('does not share its throttle bucket with the service-number check or the invite route', function () {
    $person = cd_person();
    $viewer = cd_user(['personnel.view', 'personnel.edit', 'users.create']);
    foreach (range(1, 70) as $_) {
        $this->actingAs($viewer)->getJson("/api/personnel/{$person->id}/card")->assertOk();
    }
    $this->actingAs($viewer)->postJson('/api/personnel/check-dienstnr', ['dienstnr' => 'BF-1'])->assertOk();
    $this->actingAs($viewer)->postJson("/personnel/{$person->id}/invite")->assertSuccessful();
});

it('gives malformed ids the shared 404 and never a cacheable error', function () {
    $viewer = cd_user(['personnel.view', 'users.view']);
    foreach (['abc', '99999999999999999999', '1x', '1%0A'] as $id) {
        foreach (['personnel', 'users'] as $kind) {
            $res = $this->actingAs($viewer)->getJson("/api/{$kind}/{$id}/card")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            expect($res->headers->get('Cache-Control'))->not->toContain('max-age=60');
        }
    }
    $nobody = cd_user([]);
    expect($this->actingAs($nobody)->getJson('/api/personnel/1/card')->assertForbidden()->headers->get('Cache-Control'))->not->toContain('max-age=60');
});

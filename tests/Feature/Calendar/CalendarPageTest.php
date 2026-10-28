<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('passes rights, roles, zone, options and the open flags to the page', function () {
    $role = cal_role();
    $this->actingAs(cal_user(['calendar.view', 'calendar.create']))->get('/calendar')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Calendar/Index')
            ->where('can', ['create' => true, 'manage' => false])
            ->where('timezone', 'Europe/Berlin')
            ->where('roles', fn ($roles) => collect($roles)->contains(fn ($r) => $r['id'] === $role->id && $r['name'] === $role->name))
            ->where('options.colors', ['accent', 'blue', 'green', 'red', 'purple', 'gray'])
            ->where('options.categories', ['general', 'meeting', 'training', 'duty', 'absence'])
            ->where('options.visibilities', ['private', 'attendees', 'role', 'all'])
            ->where('openNew', false)
            ->where('openEvent', null));
});

it('reflects manage and a read-only account', function () {
    $this->actingAs(cal_user(['calendar.view']))->get('/calendar')->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'manage' => false]));
    $this->actingAs(cal_user(['calendar.manage']))->get('/calendar')->assertInertia(fn (Assert $page) => $page->where('can.manage', true));
});

it('opens the create dialog by ?new=1 and /calendar/create, only with calendar.create', function () {
    $creator = cal_user(['calendar.view', 'calendar.create']);
    $this->actingAs($creator)->get('/calendar?new=1')->assertInertia(fn (Assert $page) => $page->where('openNew', true));
    $this->actingAs($creator)->get('/calendar/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Calendar/Index')->where('openNew', true));

    $reader = cal_user(['calendar.view']);
    $this->actingAs($reader)->get('/calendar?new=1')->assertInertia(fn (Assert $page) => $page->where('openNew', false));
    $this->actingAs($reader)->get('/calendar/create')->assertOk()->assertInertia(fn (Assert $page) => $page->where('openNew', false));
});

it('passes a numeric ?event= only', function () {
    $user = cal_user(['calendar.view']);
    $this->actingAs($user)->get('/calendar?event=42')->assertInertia(fn (Assert $page) => $page->where('openEvent', 42));
    $this->actingAs($user)->get('/calendar?event=abc')->assertInertia(fn (Assert $page) => $page->where('openEvent', null));
    $this->actingAs($user)->get('/calendar?event[]=1')->assertInertia(fn (Assert $page) => $page->where('openEvent', null));
});

it('keeps the alias route behind login', function () {
    $this->get('/calendar/create')->assertRedirect('/login');
});

it('has equal de and en keys in the module lang files', function () {
    $de = json_decode((string) file_get_contents(base_path('modules/Calendar/lang/de.json')), true, flags: JSON_THROW_ON_ERROR);
    $en = json_decode((string) file_get_contents(base_path('modules/Calendar/lang/en.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($de))->toEqualCanonicalizing(array_keys($en));
});

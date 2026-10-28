<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Notifications\NotificationKinds;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function nk_user(string ...$perms): User
{
    $role = Role::create(['name' => 'K'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('knows the core kinds and shows unknown ones raw', function () {
    $kinds = app(NotificationKinds::class);

    expect($kinds->describe('vehicle_defect'))->toMatchArray(['icon' => 'wrench', 'known' => true])
        ->and($kinds->describe('dokument')['label'])->toBe(trans('notifications.kind.dokument'))
        ->and($kinds->describe('system')['known'])->toBeTrue()
        ->and($kinds->describe('zz_unregistered'))->toBe(['label' => 'zz_unregistered', 'icon' => 'bell', 'known' => false]);
});

it('lets vehicle_defect through for admin, vehicles.view or vehicles.manage only', function () {
    $kinds = app(NotificationKinds::class);

    expect($kinds->allowed(nk_user('audit.view')))->toBe(['dokument', 'system', 'antrag'])
        ->and($kinds->hidden(nk_user('audit.view')))->toBe(['vehicle_defect', 'calendar', 'protokoll', 'fire_protocol', 'mail'])
        ->and($kinds->allowed(nk_user('edivi.view')))->toContain('protokoll')
        ->and($kinds->allowed(nk_user('vehicles.manage')))->toContain('vehicle_defect')
        ->and($kinds->allowed(nk_user('vehicles.view')))->toContain('vehicle_defect')
        ->and($kinds->allowed(nk_user('admin')))->toContain('vehicle_defect')
        ->and($kinds->hidden(User::factory()->superAdmin()->create()))->toBe(['mail']);
});

it('takes module kinds with their own ability', function () {
    $kinds = app(NotificationKinds::class);
    $kinds->register('calendar', ['label' => 'notifications.kind.system', 'icon' => 'calendar', 'ability' => fn (User $u) => $u->is_super_admin]);

    expect($kinds->describe('calendar')['known'])->toBeTrue()
        ->and($kinds->hidden(nk_user('admin')))->toBe(['calendar', 'mail']);
});

it('refuses an unknown kind or an empty title when creating', function () {
    expect(fn () => new InboxNotification('zz_unregistered', 'x', null, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new InboxNotification('system', '  ', null, null))->toThrow(InvalidArgumentException::class);
});

it('cuts the title and keeps only internal links', function () {
    foreach ([
        ['/documents/5', '/documents/5'],
        ['/vehicles/defects?vehicle=3', '/vehicles/defects?vehicle=3'],
        ['https://evil.example/x', null],
        ['//evil.example', null],
        ['/\\evil.example', null],
        ["/\t/evil.example", null],
        ['/a b', null],
        ["/\u{2028}x", null],
        ["/\u{FEFF}x", null],
        ["/\u{00A0}x", null],
        ["/\xC3\x28", null],
        ['/personnel/Müller', '/personnel/Müller'],
        ['javascript:alert(1)', null],
        ['relative/path', null],
        ['/'.str_repeat('a', 512), null],
        ['', null],
        [null, null],
    ] as [$link, $expected]) {
        $n = new InboxNotification('system', str_repeat('ä', 300), 'm', $link);

        expect($n->toArray(new User))->toBe(['title' => str_repeat('ä', 255), 'message' => 'm', 'link' => $expected]);
    }
});

<?php

use App\Auth\NoSuperAdminBypass;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

class SuperAdminGateTestSubject {}

class SuperAdminGateTestPlainSubject {}

class SuperAdminGateTestPlainPolicy
{
    public function view(User $u, ?SuperAdminGateTestPlainSubject $s = null): bool
    {
        return false;
    }
}

class SuperAdminGateTestMarkedPolicy implements NoSuperAdminBypass
{
    public function view(User $u, ?SuperAdminGateTestSubject $s = null): bool
    {
        return false;
    }
}

it('lets super admins pass normal abilities but not the mail content abilities', function () {
    Gate::define('sa.normal', fn (User $u) => false);
    Gate::define('mail.message.view', fn (User $u) => false);
    Gate::define('mail.delivery.move', fn (User $u) => false);
    Gate::define('mail.message.own', fn (User $u) => $u->id > 0);
    $super = User::factory()->superAdmin()->create();

    expect($super->can('sa.normal'))->toBeTrue()
        ->and($super->can('mail.message.view'))->toBeFalse()
        ->and($super->can('mail.delivery.move'))->toBeFalse()
        ->and($super->can('mail.message.own'))->toBeTrue()
        ->and($super->can('mail.use'))->toBeTrue();
});

it('does not let super admins pass a policy marked NoSuperAdminBypass', function () {
    Gate::policy(SuperAdminGateTestSubject::class, SuperAdminGateTestMarkedPolicy::class);
    $super = User::factory()->superAdmin()->create();

    expect($super->can('view', new SuperAdminGateTestSubject))->toBeFalse()
        ->and($super->can('view', SuperAdminGateTestSubject::class))->toBeFalse();
});

it('still lets super admins through a registered policy that is not marked', function () {
    Gate::policy(SuperAdminGateTestPlainSubject::class, SuperAdminGateTestPlainPolicy::class);
    $super = User::factory()->superAdmin()->create();

    expect($super->can('view', new SuperAdminGateTestPlainSubject))->toBeTrue()
        ->and($super->can('view', SuperAdminGateTestPlainSubject::class))->toBeTrue()
        ->and(User::factory()->create()->can('view', new SuperAdminGateTestPlainSubject))->toBeFalse();
});

it('names the exceptions in one small, tested place', function () {
    expect(AppServiceProvider::NO_SUPER_ADMIN_BYPASS)->toBe(['mail.message', 'mail.delivery'])
        ->and(AppServiceProvider::superAdminBypasses('mail.messages.view', []))->toBeFalse()
        ->and(AppServiceProvider::superAdminBypasses('mail.admin', []))->toBeTrue()
        ->and(AppServiceProvider::superAdminBypasses('view', [new SuperAdminGateTestSubject]))->toBeTrue()
        ->and(AppServiceProvider::superAdminBypasses('view', ['not-a-class']))->toBeTrue();
});

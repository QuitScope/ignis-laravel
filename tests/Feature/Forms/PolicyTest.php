<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Forms\Models\FormSubmission;

require_once __DIR__.'/formsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('applies the rights matrix', function () {
    foreach ([
        'no rights, no record' => ['none', ['viewAny' => false, 'view' => false, 'create' => false, 'decide' => false]],
        'no rights, own record' => ['record', ['viewAny' => false, 'view' => false, 'create' => true, 'decide' => false]],
        'owner' => ['owner', ['viewAny' => false, 'view' => true, 'create' => true, 'decide' => false]],
        'view' => ['view', ['viewAny' => true, 'view' => true, 'create' => false, 'decide' => false]],
        'edit' => ['edit', ['viewAny' => true, 'view' => true, 'create' => false, 'decide' => true]],
        'owner with edit' => ['owner-edit', ['viewAny' => true, 'view' => true, 'create' => true, 'decide' => false]],
        'admin' => ['admin', ['viewAny' => true, 'view' => true, 'create' => false, 'decide' => true]],
        'super admin' => ['super', ['viewAny' => true, 'view' => true, 'create' => true, 'decide' => true]],
    ] as [$who, $expect]) {
        $owner = fs_record();
        $submission = fs_submission(fs_type(), $owner);
        $user = match ($who) {
            'none' => fs_user(),
            'record' => fs_applicant(),
            'owner' => fs_user([], $owner),
            'view' => fs_user(['application.view']),
            'edit' => fs_user(['application.edit']),
            'owner-edit' => fs_user(['application.edit'], $owner),
            'admin' => fs_user(['admin']),
            'super' => User::factory()->superAdmin()->create(),
        };

        expect([
            'viewAny' => $user->can('viewAny', FormSubmission::class),
            'view' => $user->can('view', $submission),
            'create' => $user->can('create', FormSubmission::class),
            'decide' => $user->can('decide', $submission),
        ])->toBe($expect);
    }
});

it('keeps guests out of every page', function () {
    $s = fs_submission(fs_type());

    foreach (['/forms', '/forms/new/'.$s->form_type_id, "/forms/{$s->public_id}", '/forms/admin', "/forms/admin/{$s->public_id}", '/me/applications'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $this->post('/forms/new/'.$s->form_type_id, ['answers' => []])->assertRedirect('/login');
    $this->put("/forms/admin/{$s->public_id}", ['status' => 'accepted'])->assertRedirect('/login');
    expect($s->fresh()->status)->toBe('new');
});

it('opens a submission to owner, view and edit only; the public id is no permission', function () {
    $owner = fs_applicant();
    $s = fs_submission(fs_type(), $owner->personnel);

    $this->actingAs($owner)->get("/forms/{$s->public_id}")->assertOk();
    $this->actingAs(fs_user(['application.view']))->get("/forms/{$s->public_id}")->assertOk();
    $this->actingAs(fs_user(['application.edit']))->get("/forms/{$s->public_id}")->assertOk();
    $this->actingAs(fs_applicant())->get("/forms/{$s->public_id}")->assertNotFound();
    $this->actingAs(fs_user())->get("/forms/{$s->public_id}")->assertNotFound();
    $this->actingAs(fs_user())->get('/forms/999999')->assertNotFound();
});

it('refuses the admin area to rightless accounts before looking the id up', function () {
    $s = fs_submission(fs_type(), fs_record());
    $nobody = fs_applicant();

    foreach ([$s->public_id, '999999'] as $id) {
        $this->actingAs($nobody)->get("/forms/admin/{$id}")->assertForbidden();
        $this->actingAs($nobody)->put("/forms/admin/{$id}", ['status' => 'accepted'])->assertForbidden();
    }
    expect($s->fresh()->status)->toBe('new');
});

it('lets application.view read the admin list and detail but not decide (legacy 1.3-1)', function () {
    $s = fs_submission(fs_type(), fs_record());
    $viewer = fs_user(['application.view']);

    $this->actingAs($viewer)->get('/forms/admin')->assertOk();
    $this->actingAs($viewer)->get("/forms/admin/{$s->public_id}")->assertOk()->assertInertia(fn ($p) => $p->where('can.decide', false));
    $this->actingAs($viewer)->put("/forms/admin/{$s->public_id}", ['status' => 'accepted'])->assertForbidden();
    $this->actingAs(fs_applicant())->get('/forms/admin')->assertForbidden();
    expect($s->fresh()->status)->toBe('new');
});

it('never lets anyone decide their own submission, super admins included', function () {
    $editor = fs_applicant(['application.edit']);
    $super = User::factory()->superAdmin()->create(['personnel_id' => fs_record()->id]);
    $mine = fs_submission(fs_type(), $editor->personnel);
    $supers = fs_submission(fs_type(), $super->personnel);

    $this->actingAs($editor)->get("/forms/admin/{$mine->public_id}")->assertOk()->assertInertia(fn ($p) => $p->where('can.decide', false)->where('own', true));
    $this->actingAs($editor)->put("/forms/admin/{$mine->public_id}", ['status' => 'accepted'])->assertForbidden();
    $this->actingAs($super)->put("/forms/admin/{$supers->public_id}", ['status' => 'accepted'])->assertForbidden();
    $this->actingAs($super)->put("/forms/admin/{$mine->public_id}", ['status' => 'accepted'])->assertRedirect();

    expect($mine->fresh()->status)->toBe('accepted')->and($supers->fresh()->status)->toBe('new');
});

it('answers 404 for every page while the module is switched off', function () {
    $user = fs_applicant(['application.edit']);
    $s = fs_submission(fs_type(), fs_record());
    app(Settings::class)->set('modules.forms.enabled', false);

    foreach (['/forms', '/forms/new/'.$s->form_type_id, "/forms/{$s->public_id}", '/forms/admin', "/forms/admin/{$s->public_id}", '/me/applications'] as $url) {
        $this->actingAs($user)->get($url)->assertNotFound();
    }
    $this->actingAs($user)->put("/forms/admin/{$s->public_id}", ['status' => 'accepted'])->assertNotFound();
    expect($user->can('viewAny', FormSubmission::class))->toBeFalse()->and($user->can('decide', $s))->toBeFalse();
});

it('answers 404 for malformed and unknown public ids', function () {
    $editor = fs_user(['application.edit']);

    foreach (['/forms/12345', '/forms/1234567', '/forms/abcdef', '/forms/999999', '/forms/admin/12345', '/forms/admin/999999'] as $url) {
        $this->actingAs($editor)->get($url)->assertNotFound();
    }
    $this->actingAs($editor)->put('/forms/admin/999999', ['status' => 'accepted'])->assertNotFound();
});

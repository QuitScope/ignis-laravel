<?php

use App\Notifications\NotificationKinds;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forms\Models\FormSubmission;

require_once __DIR__.'/formsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lists only the own submissions, newest first, with status filter and paging', function () {
    $me = fs_applicant();
    $type = fs_type([], ['name' => 'Urlaubsantrag']);
    foreach (range(1, 27) as $i) {
        fs_submission($type, $me->personnel, ['public_id' => (string) (100000 + $i), 'submitted_at' => now()->subDays(30 - $i), 'status' => $i % 2 ? 'new' : 'accepted']);
    }
    fs_submission($type, fs_record(), ['public_id' => '999999']);

    $this->actingAs($me)->get('/me/applications')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Me/Applications')
        ->has('submissions.data', 25)->where('submissions.total', 27)
        ->where('submissions.data.0.public_id', '100027')->where('submissions.data.0.type', 'Urlaubsantrag')->where('submissions.data.0.href', '/forms/100027'));
    $this->actingAs($me)->get('/me/applications?page=2')->assertInertia(fn (Assert $p) => $p->has('submissions.data', 2));
    $this->actingAs($me)->get('/me/applications?status=accepted')->assertInertia(fn (Assert $p) => $p->where('submissions.total', 13)->where('filters.status', 'accepted'));
    $this->actingAs($me)->get('/me/applications?status[]=x&q[]=y')->assertOk()->assertInertia(fn (Assert $p) => $p->where('filters', ['status' => '', 'q' => '']));
});

it('shows an empty own list to an account without a record', function () {
    fs_submission(fs_type(), null);

    $this->actingAs(fs_user())->get('/me/applications')->assertOk()->assertInertia(fn (Assert $p) => $p->where('submissions.total', 0));
});

it('filters the admin list by status, open and search, wildcards taken literally', function () {
    $type = fs_type([], ['name' => 'Beförderungsantrag']);
    fs_submission(fs_type([], ['name' => 'Urlaubsantrag']), null, ['public_id' => '444444', 'applicant_name' => 'Dora Delta (BF-4)', 'status' => 'accepted']);
    fs_submission($type, null, ['public_id' => '111111', 'applicant_name' => 'Anna Alpha (BF-1)', 'status' => 'new']);
    fs_submission($type, null, ['public_id' => '222222', 'applicant_name' => 'Bernd Beta (BF-2)', 'status' => 'in_progress']);
    fs_submission($type, null, ['public_id' => '333333', 'applicant_name' => 'Carla 100% (BF-3)', 'status' => 'rejected']);
    fs_submission($type, null, ['public_id' => '555555', 'applicant_name' => 'Emil Echo (BF-5)', 'status' => 'deferred']);
    $viewer = fs_user(['application.view']);
    $ids = fn (string $qs) => collect($this->actingAs($viewer)->get('/forms/admin'.$qs)->viewData('page')['props']['submissions']['data'])->pluck('public_id')->sort()->values()->all();

    expect($ids(''))->toBe(['111111', '222222', '333333', '444444', '555555'])
        ->and($ids('?status=open'))->toBe(['111111', '222222'])
        ->and($ids('?status=rejected'))->toBe(['333333'])
        ->and($ids('?status=deferred'))->toBe(['555555'])
        ->and($ids('?status=bogus'))->toBe(['111111', '222222', '333333', '444444', '555555'])
        ->and($ids('?q=urlaub'))->toBe(['444444'])
        ->and($ids('?q=beförderung'))->toBe(['111111', '222222', '333333', '555555'])
        ->and($ids('?q=beta'))->toBe(['222222'])
        ->and($ids('?q=1111'))->toBe(['111111'])
        ->and($ids('?q=%25'))->toBe(['333333'])
        ->and($ids('?q=_'))->toBe([]);
});

it('loads both lists with a constant number of queries', function () {
    $viewer = fs_applicant(['application.view']);
    $count = function (string $url) use ($viewer) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $types = [fs_type(), fs_type(), fs_type()];
    foreach (range(1, 3) as $i) {
        fs_submission($types[$i % 3], $viewer->personnel);
    }
    $count('/forms/admin');
    $few = [$count('/forms/admin'), $count('/me/applications')];
    foreach (range(1, 27) as $i) {
        fs_submission($types[$i % 3], $viewer->personnel);
    }

    expect([$count('/forms/admin'), $count('/me/applications')])->toBe($few);
});

it('shows the newest five own submissions on the start page', function () {
    $me = fs_applicant();
    $type = fs_type([], ['name' => 'Urlaubsantrag']);
    foreach (range(1, 7) as $i) {
        fs_submission($type, $me->personnel, ['public_id' => (string) (200000 + $i), 'submitted_at' => now()->subDays(10 - $i)]);
    }

    $this->actingAs($me)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('layout.main', fn ($main) => collect($main)->contains('own_applications'))
        ->where('widgets.own_applications.total', 7)
        ->where('widgets.own_applications.href', '/me/applications')
        ->has('widgets.own_applications.items', 5)
        ->where('widgets.own_applications.items.0.public_id', '200007')
        ->where('widgets.own_applications.items.0.status', 'new'));
});

it('shows an empty state without a record and leaves the widget out while the module is off', function () {
    $this->actingAs(fs_user())->get('/')->assertInertia(fn (Assert $p) => $p->where('widgets.own_applications', ['items' => [], 'total' => 0, 'reason' => 'no_profile', 'href' => '/me/applications']));

    $me = fs_applicant();
    app(Settings::class)->set('modules.forms.enabled', false);
    $this->actingAs($me)->get('/')->assertInertia(fn (Assert $p) => $p->missing('widgets.own_applications'));
});

it('lists all open submissions as an open task for application.edit', function () {
    $type = fs_type([], ['name' => 'Urlaubsantrag']);
    $editor = fs_applicant(['application.edit']);
    fs_submission($type, $editor->personnel, ['public_id' => '100001', 'submitted_at' => now()->subDays(3)]);
    fs_submission($type, fs_record(), ['public_id' => '100002', 'status' => 'in_progress', 'submitted_at' => now()->subDays(2)]);
    fs_submission($type, fs_record(), ['public_id' => '100003', 'status' => 'accepted']);
    fs_submission($type, fs_record(), ['public_id' => '100004', 'status' => 'deferred']);

    $this->actingAs($editor)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('layout.side', ['hints'])
        ->where('widgets.hints.hints', [['tone' => 'info', 'icon' => 'list-check', 'title' => '2 offene Anträge: Urlaubsantrag #100001, Urlaubsantrag #100002', 'href' => '/forms/admin?status=open']]));

    FormSubmission::where('public_id', '100002')->update(['status' => 'rejected']);
    $this->actingAs($editor)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.hints.hints', [['tone' => 'info', 'icon' => 'list-check', 'title' => 'Offener Antrag: Urlaubsantrag #100001', 'href' => '/forms/admin/100001']]));

    FormSubmission::where('public_id', '100001')->update(['status' => 'accepted']);
    $this->actingAs($editor)->get('/')->assertInertia(fn (Assert $p) => $p->where('widgets.hints.hints.0.tone', 'ok'));
});

it('shows no open tasks to application.view or while the module is off', function () {
    fs_submission(fs_type(), fs_record());

    $this->actingAs(fs_user(['application.view']))->get('/')->assertInertia(fn (Assert $p) => $p->missing('widgets.hints'));
    $editor = fs_user(['application.edit']);
    app(Settings::class)->set('modules.forms.enabled', false);
    $this->actingAs($editor)->get('/')->assertInertia(fn (Assert $p) => $p->missing('widgets.hints'));
});

it('registers the antrag notification kind for the module', function () {
    $kinds = app(NotificationKinds::class);

    expect($kinds->has('antrag'))->toBeTrue()->and($kinds->describe('antrag'))->toBe(['label' => 'Anträge', 'icon' => 'file-text', 'known' => true])
        ->and($kinds->allowed(fs_user()))->toContain('antrag');
    app(Settings::class)->set('modules.forms.enabled', false);
    expect($kinds->allowed(fs_user()))->not->toContain('antrag');
});

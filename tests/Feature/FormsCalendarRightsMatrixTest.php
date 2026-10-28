<?php

use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Support\IcalToken;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormType;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/Forms/formsHelpers.php';
require_once __DIR__.'/Calendar/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function p6b_checker(?User $user, array &$bad): Closure
{
    return function (string $label, string $method, string $url, int|string $expected, array $data = []) use ($user, &$bad) {
        app('auth')->forgetGuards();
        $request = $user === null ? test() : test()->actingAs($user);
        $response = $request->call($method, $url, $data);
        $got = $response->isRedirect(url('/login')) ? 'login' : $response->getStatusCode();
        if ($got !== $expected) {
            $bad[] = "{$label}: {$method} {$url} expected {$expected}, got {$got}";
        }
    };
}

it('answers every Forms route with the exact status for every account', function (string $actor) {
    $ownerRecord = fs_record(0, ['full_name' => 'Eigentuemer Eins']);
    $type = fs_type([['key' => 'grund', 'required' => true]]);
    $sub = fs_submission($type, $ownerRecord);
    $gone = fs_type();
    $field = FormField::query()->where('form_type_id', $type->id)->first();

    $user = match ($actor) {
        'guest' => null,
        'plain' => fs_user([]),
        'record' => fs_applicant([]),
        'owner' => fs_user([], $ownerRecord),
        'application.view' => fs_applicant(['application.view']),
        'application.edit' => fs_applicant(['application.edit']),
        'owner-edit' => fs_user(['application.edit'], $ownerRecord),
        'admin' => fs_applicant(['admin']),
        'super-admin' => User::factory()->superAdmin()->create(),
    };
    $in = fn (string ...$who) => in_array($actor, $who, true);
    $guest = $user === null;
    $hasRecord = ! $in('guest', 'plain', 'super-admin');
    $reads = $in('application.view', 'application.edit', 'owner-edit', 'admin', 'super-admin');
    $sees = $reads || $in('owner');
    $deny = $guest ? 'login' : 403;

    $bad = [];
    $check = p6b_checker($user, $bad);
    $any = $guest ? 'login' : 200;

    $check('select', 'GET', '/forms', $any);
    $check('mine', 'GET', '/me/applications', $any);
    $check('create-page', 'GET', "/forms/new/{$type->id}", $guest ? 'login' : ($hasRecord ? 200 : 403));
    $check('show', 'GET', "/forms/{$sub->public_id}", $guest ? 'login' : ($sees ? 200 : 404));
    $check('show-missing', 'GET', '/forms/999999', $guest ? 'login' : 404);

    $check('admin-list', 'GET', '/forms/admin', $reads ? 200 : $deny);
    $check('admin-show', 'GET', "/forms/admin/{$sub->public_id}", $reads ? 200 : $deny);
    $decides = $in('application.edit', 'admin', 'super-admin');
    $check('decide', 'PUT', "/forms/admin/{$sub->public_id}", $decides ? 302 : $deny, ['status' => 'accepted', 'decision_note' => '']);
    $check('decide-missing', 'PUT', '/forms/admin/999999', $reads ? 404 : $deny, ['status' => 'accepted', 'decision_note' => '']);

    $check('store', 'POST', "/forms/new/{$type->id}", $guest ? 'login' : ($hasRecord ? 302 : 403), ['answers' => ['grund' => 'Ein Grund']]);

    $s = $in('admin', 'super-admin');
    $allowed = fn (int $ok) => $s ? $ok : $deny;
    $check('types', 'GET', '/settings/forms', $allowed(200));
    $check('types-create', 'GET', '/settings/forms/create', $allowed(200));
    $check('types-edit', 'GET', "/settings/forms/{$type->id}/edit", $allowed(200));
    $check('types-store', 'POST', '/settings/forms', $allowed(201), ['name' => 'Neu '.uniqid(), 'is_active' => true, 'is_absence' => false]);
    $check('types-update', 'PUT', "/settings/forms/{$type->id}", $allowed(200), ['name' => 'Umbenannt '.uniqid(), 'is_active' => true, 'is_absence' => false]);
    $check('types-active', 'PATCH', "/settings/forms/{$type->id}/active", $allowed(200), ['is_active' => true]);
    $check('types-order', 'PUT', '/settings/forms/order', $allowed(200), ['ids' => FormType::orderBy('sort_order')->orderBy('id')->pluck('id')->all()]);
    $check('fields-store', 'POST', "/settings/forms/{$type->id}/fields", $allowed(201), ['key' => 'neu', 'label' => 'Neu', 'type' => 'text']);
    $check('fields-order', 'PUT', "/settings/forms/{$type->id}/fields/order", $allowed(200), ['ids' => $type->fields()->orderBy('sort_order')->orderBy('id')->pluck('id')->all()]);
    $check('fields-delete', 'DELETE', "/settings/forms/{$type->id}/fields/{$field->id}", $allowed(200));
    $check('types-delete', 'DELETE', "/settings/forms/{$gone->id}", $allowed(200));

    expect($bad)->toBe([]);
})->with(['guest', 'plain', 'record', 'owner', 'application.view', 'application.edit', 'owner-edit', 'admin', 'super-admin']);

it('answers every Calendar route with the exact status for every account', function (string $actor) {
    $creatorUser = cal_user(['calendar.view', 'calendar.create']);
    $event = cal_event(['created_by' => $creatorUser->id]);
    $user = match ($actor) {
        'guest' => null,
        'plain' => cal_user(['audit.view']),
        'calendar.view' => cal_user(['calendar.view']),
        'calendar.create' => cal_user(['calendar.create']),
        'calendar.manage' => cal_user(['calendar.manage']),
        'creator' => $creatorUser,
        'admin' => cal_user(['admin']),
        'super-admin' => User::factory()->superAdmin()->create(['personnel_id' => Personnel::factory()->create()->id]),
    };
    $mine = $actor === 'creator' ? $event : cal_event(['created_by' => $creatorUser->id]);
    $respond = cal_event(['created_by' => $creatorUser->id], [], $user?->personnel_id ? [$user->personnel_id => 'pending'] : []);

    $in = fn (string ...$who) => in_array($actor, $who, true);
    $guest = $user === null;
    $deny = $guest ? 'login' : 403;
    $reads = $in('calendar.view', 'calendar.manage', 'creator', 'admin', 'super-admin');
    $creates = $in('calendar.create', 'creator', 'admin', 'super-admin');
    $writes = $in('calendar.manage', 'creator', 'admin', 'super-admin');
    $hidden = $guest ? 'login' : 404;
    $body = ['title' => 'Termin', 'starts_at' => '2026-11-10', 'ends_at' => '2026-11-10', 'all_day' => true, 'visibility' => 'all'];

    $bad = [];
    $check = p6b_checker($user, $bad);

    $check('page', 'GET', '/calendar', $reads ? 200 : $deny);
    $check('page-create', 'GET', '/calendar/create', $reads ? 200 : $deny);
    $check('feed', 'GET', '/calendar/events?from=2026-11-01&to=2026-12-01', $reads ? 200 : $deny);
    $check('personnel', 'GET', '/calendar/personnel', ($creates || $in('calendar.manage')) ? 200 : $deny);
    $check('store', 'POST', '/calendar', $creates ? 201 : $deny, $body);
    $check('show', 'GET', "/calendar/{$event->id}", $reads ? 200 : $hidden);
    $check('show-missing', 'GET', '/calendar/999999', $guest ? 'login' : 404);
    $check('update', 'PUT', "/calendar/{$mine->id}", $writes ? 200 : ($reads ? 403 : $hidden), $body);
    $check('respond', 'POST', "/calendar/{$respond->id}/response", $reads ? 200 : $hidden, ['response' => 'accepted']);
    $check('subscription-show', 'GET', '/calendar/subscription', $reads ? 200 : $deny);
    $check('subscription-store', 'POST', '/calendar/subscription', $reads ? 200 : $deny);
    $check('subscription-delete', 'DELETE', '/calendar/subscription', $reads ? 204 : $deny);
    $check('delete', 'DELETE', "/calendar/{$mine->id}", $writes ? 204 : ($reads ? 403 : $hidden));

    expect($bad)->toBe([]);
})->with(['guest', 'plain', 'calendar.view', 'calendar.create', 'calendar.manage', 'creator', 'admin', 'super-admin']);

it('serves the public ICS feed only for a live token of an account that may read the calendar', function () {
    $viewer = cal_user(['calendar.view']);
    $token = IcalToken::issue($viewer);
    $bad = [];
    $check = p6b_checker(null, $bad);

    $check('valid', 'GET', "/api/calendar/ical/{$token}", 200);
    $check('unknown', 'GET', '/api/calendar/ical/'.str_repeat('a', 48), 404);
    $check('malformed', 'GET', '/api/calendar/ical/not-a-token', 404);
    $check('legacy-v1', 'GET', "/api/v1/calendar/ical/{$token}", 200);

    $other = cal_user(['calendar.view']);
    $t2 = IcalToken::issue($other);
    IcalToken::revoke($other);
    $check('revoked', 'GET', "/api/calendar/ical/{$t2}", 404);
    $check('no-right', 'GET', '/api/calendar/ical/'.IcalToken::issue(cal_user(['audit.view'])), 404);
    $viewer->roles()->first()->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $check('right-lost', 'GET', "/api/calendar/ical/{$token}", 404);

    expect($bad)->toBe([]);
});

<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\Models\CalendarAttendee;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Forms\Models\FormType;

function cal_role(array $perms = []): Role
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return $role;
}

function cal_user(array $perms = ['calendar.view'], bool $record = true, array $roles = []): User
{
    $user = User::factory()->withRole(cal_role($perms))->create(['personnel_id' => $record ? Personnel::factory()->create()->id : null]);
    foreach ($roles as $role) {
        $user->assignRole($role);
    }

    return $user->fresh();
}

function cal_event(array $over = [], array $roles = [], array $attendees = []): CalendarEvent
{
    $event = (new CalendarEvent)->forceFill($over + [
        'title' => 'Termin '.uniqid(), 'starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-10 10:00:00',
        'all_day' => false, 'visibility' => 'all', 'color' => 'accent', 'category' => 'general',
    ]);
    $event->save();
    $event->roles()->sync(array_map(fn (Role $r) => $r->id, $roles));
    foreach ($attendees as $pid => $response) {
        (new CalendarAttendee)->forceFill(['event_id' => $event->id, 'personnel_id' => $pid, 'response' => $response])->save();
    }

    return $event;
}

function cal_feed_ids(User $user): array
{
    return collect(test()->actingAs($user)->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk()->json())
        ->pluck('id')->unique()->sort()->values()->all();
}

function cal_locked(User $creator): CalendarEvent
{
    $type = (new FormType)->forceFill(['name' => 'Urlaub', 'is_active' => true, 'is_absence' => true, 'sort_order' => 1]);
    $type->save();
    $sid = DB::table('form_submissions')->insertGetId(['public_id' => '123456', 'form_type_id' => $type->id, 'applicant_name' => 'X',
        'status' => 'accepted', 'submitted_at' => now(), 'answers' => '[]']);

    return cal_event(['form_submission_id' => $sid, 'created_by' => $creator->id, 'all_day' => true, 'category' => 'absence',
        'starts_at' => '2026-11-10 00:00:00', 'ends_at' => '2026-11-12 00:00:00']);
}

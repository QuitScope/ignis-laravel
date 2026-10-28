<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Models\CalendarEvent;

require_once __DIR__.'/calendarHelpers.php';
require_once __DIR__.'/../Forms/formsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->type = fs_type([], ['is_absence' => true]);
    $mk = fn (string $status, array $dates, $type = null) => fs_submission($type ?? $this->type, fs_record(), ['status' => $status, 'answers' => [
        ['key' => 'von_datum', 'label' => 'v', 'type' => 'date', 'width' => 'half', 'value' => $dates[0]],
        ['key' => 'bis_datum', 'label' => 'b', 'type' => 'date', 'width' => 'half', 'value' => $dates[1]],
    ]]);
    $this->a = $mk('accepted', ['2026-11-10', '2026-11-12']);
    $this->b = $mk('accepted', ['2026-11-20', '2026-11-21']);
    $this->bad = $mk('accepted', ['2026-11-21', '2026-11-20']);
    $this->rejected = $mk('rejected', ['2026-11-10', '2026-11-12']);
    $this->other = $mk('accepted', ['2026-11-10', '2026-11-12'], fs_type([], ['is_absence' => false]));
});

it('writes nothing on a dry run but reports the counts', function () {
    $this->artisan('calendar:backfill-absences --dry-run')->expectsOutputToContain('2 created, 0 updated, 0 removed, 1 skipped (invalid)')->assertSuccessful();
    expect(CalendarEvent::count())->toBe(0);
});

it('creates, is idempotent, and removes stale mirrors', function () {
    $this->artisan('calendar:backfill-absences')->expectsOutputToContain('2 created, 0 updated, 0 removed, 1 skipped')->assertSuccessful();
    expect(CalendarEvent::count())->toBe(2);

    $this->artisan('calendar:backfill-absences')->expectsOutputToContain('0 created, 2 updated, 0 removed')->assertSuccessful();
    expect(CalendarEvent::count())->toBe(2);

    $this->a->forceFill(['status' => 'rejected'])->save();
    $this->type->forceFill(['is_absence' => false])->save();
    $this->artisan('calendar:backfill-absences --dry-run')->expectsOutputToContain('2 removed')->assertSuccessful();
    expect(CalendarEvent::count())->toBe(2);
    $this->artisan('calendar:backfill-absences')->expectsOutputToContain('0 created, 0 updated, 2 removed')->assertSuccessful();
    expect(CalendarEvent::count())->toBe(0);
});

it('counts an invalid submission with an existing mirror the same in the dry run and the real run', function () {
    $this->artisan('calendar:backfill-absences')->assertSuccessful();
    CalendarEvent::query()->first()->replicate()->forceFill(['form_submission_id' => $this->bad->id])->save();
    $line = '0 created, 2 updated, 1 removed, 1 skipped (invalid)';
    $this->artisan('calendar:backfill-absences --dry-run')->expectsOutputToContain($line)->assertSuccessful();
    expect(CalendarEvent::count())->toBe(3);
    $this->artisan('calendar:backfill-absences')->expectsOutputToContain($line)->assertSuccessful();
    expect(CalendarEvent::count())->toBe(2);
});

it('does nothing while the calendar or forms is off', function () {
    app(Settings::class)->set('modules.calendar.enabled', false);
    $this->artisan('calendar:backfill-absences')->expectsOutputToContain('deaktiviert')->assertSuccessful();
    app(Settings::class)->set('modules.calendar.enabled', true);
    app(Settings::class)->set('modules.forms.enabled', false);
    $this->artisan('calendar:backfill-absences')->expectsOutputToContain('deaktiviert')->assertSuccessful();
    expect(CalendarEvent::count())->toBe(0);
});

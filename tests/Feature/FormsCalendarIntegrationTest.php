<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Forms\FormsServiceProvider;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormSubmission;

require_once __DIR__.'/Forms/formsHelpers.php';
require_once __DIR__.'/Calendar/calendarHelpers.php';

uses(RefreshDatabase::class);

const P6B_REASON = 'Geheimer Grund: Arztbesuch';

function p6b_feed(): string
{
    return test()->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk()->getContent();
}

it('runs submit, decide, calendar, ics and reset for an absence application', function () {
    $this->seed(RoleSeeder::class);
    $type = fs_type([], ['name' => 'Urlaubsantrag', 'is_absence' => true]);
    foreach ([['von_datum', 'date', true], ['bis_datum', 'date', true], ['grund', 'textarea', false]] as $i => [$key, $kind, $required]) {
        (new FormField)->forceFill(['form_type_id' => $type->id, 'key' => $key, 'label' => $key, 'type' => $kind, 'sort_order' => $i + 1, 'required' => $required])->save();
    }
    $applicant = fs_user([], fs_record());
    $decider = fs_applicant(['application.edit', 'calendar.view', 'calendar.create']);
    $colleague = cal_user(['calendar.view']);

    $this->actingAs($applicant)->post("/forms/new/{$type->id}", ['answers' => ['von_datum' => '2026-11-10', 'bis_datum' => '2026-11-12', 'grund' => P6B_REASON]])->assertRedirect();
    $submission = FormSubmission::sole();
    expect($submission->status)->toBe('new')->and(CalendarEvent::count())->toBe(0)
        ->and(FormsServiceProvider::openHints())->toHaveCount(1);

    $this->actingAs($applicant)->put("/forms/admin/{$submission->public_id}", ['status' => 'accepted', 'decision_note' => ''])->assertForbidden();
    expect(CalendarEvent::count())->toBe(0);
    $this->actingAs($decider)->put("/forms/admin/{$submission->public_id}", ['status' => 'accepted', 'decision_note' => ''])->assertRedirect();
    expect($submission->fresh()->status)->toBe('accepted')->and(FormsServiceProvider::openHints())->toBe([]);

    $this->actingAs($colleague);
    $feed = $this->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk();
    $row = $feed->json('0');
    expect($feed->json())->toHaveCount(1)
        ->and($row['title'])->toBe('Abwesend: Max Muster')
        ->and($row['all_day'])->toBeTrue()
        ->and(substr((string) $row['occurrence_start'], 0, 10))->toBe('2026-11-10')
        ->and(substr((string) $row['occurrence_end'], 0, 10))->toBe('2026-11-12')
        ->and($feed->getContent())->not->toContain('Geheimer')->not->toContain('Arztbesuch');
    $event = CalendarEvent::sole();
    $detail = $this->getJson("/calendar/{$event->id}")->assertOk();
    expect($detail->getContent())->not->toContain('Geheimer')->and($detail->json('description'))->toBeNull()
        ->and($event->form_submission_id)->toBe($submission->id);

    $body = ['title' => 'X', 'starts_at' => '2026-11-10', 'ends_at' => '2026-11-10', 'all_day' => true, 'visibility' => 'all'];
    foreach ([cal_user(['calendar.view', 'calendar.create']), cal_user(['calendar.manage']), cal_user(['admin']), $decider] as $other) {
        $this->actingAs($other)->putJson("/calendar/{$event->id}", $body)->assertForbidden();
        $this->actingAs($other)->deleteJson("/calendar/{$event->id}")->assertForbidden();
    }
    expect(CalendarEvent::sole()->title)->toBe('Abwesend: Max Muster');

    $url = $this->actingAs($colleague)->postJson('/calendar/subscription')->assertOk()->json('url');
    $path = parse_url($url, PHP_URL_PATH);
    expect($path)->toStartWith('/api/calendar/ical/');
    auth()->forgetGuards();
    $ics = $this->get($path)->assertOk()->getContent();
    expect($ics)->toContain('SUMMARY:Abwesend: Max Muster')->toContain('DTSTART;VALUE=DATE:20261110')->toContain('DTEND;VALUE=DATE:20261113')
        ->not->toContain('Geheimer')->not->toContain('Arztbesuch')->not->toContain('DESCRIPTION');

    $this->actingAs($decider)->put("/forms/admin/{$submission->public_id}", ['status' => 'deferred', 'decision_note' => 'Spaeter'])->assertRedirect();
    expect(CalendarEvent::count())->toBe(0)->and(FormsServiceProvider::openHints())->toBe([]);
    $this->actingAs($colleague);
    expect($this->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->json())->toBe([]);
    auth()->forgetGuards();
    expect($this->get($path)->assertOk()->getContent())->not->toContain('Abwesend');

    $this->actingAs($decider)->put("/forms/admin/{$submission->public_id}", ['status' => 'accepted', 'decision_note' => ''])->assertRedirect();
    expect(CalendarEvent::count())->toBe(1);
    $this->actingAs($decider)->put("/forms/admin/{$submission->public_id}", ['status' => 'rejected', 'decision_note' => ''])->assertRedirect();
    expect(CalendarEvent::count())->toBe(0);
    auth()->forgetGuards();
    expect($this->get($path)->assertOk()->getContent())->not->toContain('Abwesend');
    $this->actingAs($colleague);
    expect($this->getJson('/calendar/events?from=2026-11-01&to=2026-12-01')->json())->toBe([]);
});

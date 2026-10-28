<?php

use App\Models\Personnel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forms\Events\FormDecided;
use Modules\Forms\Models\FormSubmission;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/formsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->editor = fs_user(['application.edit'], Personnel::factory()->create(['full_name' => 'Erika Bearbeiter']));
    $this->applicant = fs_applicant();
    $this->submission = fs_submission(fs_type(), $this->applicant->personnel, ['public_id' => '424242']);
});

function dc_audits(): array
{
    return Activity::query()->where('log_name', 'Anträge')->orderBy('id')->pluck('description')->all();
}

it('decides: status, remark, decider, time, audit per change, event and notification', function () {
    Event::fake([FormDecided::class]);
    $this->travelTo('2026-10-21 12:00:00');

    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'accepted', 'decision_note' => '  Genehmigt  '])
        ->assertRedirect()->assertSessionHas('status', 'forms.decided');

    $s = $this->submission->fresh();
    expect($s->status)->toBe('accepted')->and($s->decision_note)->toBe('Genehmigt')
        ->and($s->decided_by)->toBe($this->editor->id)->and($s->decided_by_name)->toBe('Erika Bearbeiter')
        ->and($s->decided_at->toDateTimeString())->toBe('2026-10-21 12:00:00')
        ->and(dc_audits())->toBe(['Bearbeiter geändert [ID: 424242]', 'Status geändert [ID: 424242]', 'Bemerkung geändert [ID: 424242]']);
    expect(Activity::query()->where('description', 'Status geändert [ID: 424242]')->value('details'))->toBe('Neuer Status: Angenommen');
    Event::assertDispatchedTimes(FormDecided::class, 1);
    Event::assertDispatched(FormDecided::class, fn (FormDecided $e) => $e->oldStatus === 'new' && $e->submission->is($s));

    $n = $this->applicant->notifications()->sole();
    expect($n->type)->toBe('antrag')
        ->and($n->data['title'])->toBe('Ihr Antrag #424242 wurde bearbeitet')
        ->and($n->data['message'])->toBe('Status: Angenommen. Bearbeiter: Erika Bearbeiter')
        ->and($n->data['link'])->toBe('/forms/424242');
});

it('writes, audits, fires and notifies nothing when nothing changed (legacy 1.3-6)', function () {
    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'accepted', 'decision_note' => 'Ok']);
    $before = $this->submission->fresh();
    Event::fake([FormDecided::class]);
    $this->travel(1)->hours();

    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'accepted', 'decision_note' => ' Ok '])->assertSessionHas('status', 'forms.unchanged');

    Event::assertNotDispatched(FormDecided::class);
    expect(dc_audits())->toHaveCount(3)
        ->and($this->applicant->notifications()->count())->toBe(1)
        ->and($this->submission->fresh()->decided_at->equalTo($before->decided_at))->toBeTrue();
});

it('turns two identical decisions in a row into one change, one event and one notification', function () {
    Event::fake([FormDecided::class]);

    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'rejected', 'decision_note' => 'Nein'])->assertSessionHas('status', 'forms.decided');
    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'rejected', 'decision_note' => 'Nein'])->assertSessionHas('status', 'forms.unchanged');

    Event::assertDispatchedTimes(FormDecided::class, 1);
    expect(dc_audits())->toHaveCount(3)->and($this->applicant->notifications()->count())->toBe(1);
});

it('allows any status to any status, back to new as well', function () {
    foreach (['rejected', 'in_progress', 'deferred', 'accepted', 'new'] as $status) {
        $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => $status])->assertSessionHasNoErrors();
        expect($this->submission->fresh()->status)->toBe($status);
    }
    expect($this->applicant->notifications()->count())->toBe(5);
});

it('defers a submission (legacy Aufgeschoben) and tells the applicant', function () {
    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'deferred'])->assertSessionHasNoErrors();

    expect($this->submission->fresh()->status)->toBe('deferred')
        ->and($this->applicant->notifications()->first()->data['message'])->toContain('Aufgeschoben')
        ->and(dc_audits())->toContain('Status geändert [ID: 424242]');
});

it('notifies for a new remark but not for a removed one; audits the decider only when it changes', function () {
    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'new', 'decision_note' => 'Bitte Nachweis nachreichen']);
    expect($this->applicant->notifications()->count())->toBe(1)
        ->and(dc_audits())->toBe(['Bearbeiter geändert [ID: 424242]', 'Bemerkung geändert [ID: 424242]']);

    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'new', 'decision_note' => '']);
    expect($this->submission->fresh()->decision_note)->toBeNull()
        ->and($this->applicant->notifications()->count())->toBe(1)
        ->and(dc_audits())->toHaveCount(3);
});

it('validates status and remark', function (array $body, string $field) {
    $this->actingAs($this->editor)->put('/forms/admin/424242', $body)->assertSessionHasErrors($field);
    expect($this->submission->fresh()->status)->toBe('new')->and(dc_audits())->toBe([]);
})->with([
    'missing status' => [[], 'status'],
    'unknown status' => [['status' => 'postponed'], 'status'],
    'numeric legacy status' => [['status' => 3], 'status'],
    'array status' => [['status' => ['accepted']], 'status'],
    'remark too long' => [['status' => 'accepted', 'decision_note' => str_repeat('x', 5001)], 'decision_note'],
    'array remark' => [['status' => 'accepted', 'decision_note' => ['x']], 'decision_note'],
]);

it('ignores ids and decider fields sent in the body', function () {
    $other = fs_submission(fs_type(), null, ['public_id' => '515151']);

    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'rejected', 'id' => $other->id, 'public_id' => '515151', 'decided_by_name' => 'Fake', 'personnel_id' => 1]);

    expect($this->submission->fresh()->status)->toBe('rejected')->and($this->submission->fresh()->decided_by_name)->toBe('Erika Bearbeiter')
        ->and($other->fresh()->status)->toBe('new');
});

it('decides a submission without a linked applicant without notifying anyone', function () {
    $orphan = fs_submission(fs_type(), null, ['public_id' => '616161']);

    $this->actingAs($this->editor)->put('/forms/admin/616161', ['status' => 'accepted'])->assertSessionHasNoErrors();

    expect($orphan->fresh()->status)->toBe('accepted')->and(DatabaseNotification::count())->toBe(0);
});

it('shows the processing page with the decide form for editors', function () {
    $this->actingAs($this->editor)->get('/forms/admin/424242')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Forms/Admin/Show')
        ->where('submission.public_id', '424242')->where('can.decide', true)->where('own', false)
        ->where('statuses', FormSubmission::STATUSES));
});

it('keeps a saved decision when a listener or the notification fails after the commit', function () {
    Event::listen(FormDecided::class, fn () => throw new RuntimeException('listener boom'));

    $this->actingAs($this->editor)->put('/forms/admin/424242', ['status' => 'accepted'])
        ->assertRedirect()->assertSessionHas('status', 'forms.decided');

    expect($this->submission->fresh()->status)->toBe('accepted')
        ->and(DatabaseNotification::query()->where('notifiable_id', $this->applicant->id)->count())->toBe(1);
});

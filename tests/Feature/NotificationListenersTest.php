<?php

use App\Events\DocumentIssued;
use App\Events\VehicleDefectReported;
use App\Listeners\NotifyDefectReported;
use App\Listeners\NotifyDocumentIssued;
use App\Models\Document;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Vehicles\DefectReporter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function nl_user(string ...$perms): User
{
    $role = Role::create(['name' => 'L'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

it('registers each listener exactly once', function () {
    $count = fn (string $event, string $listener) => collect(Event::getRawListeners()[$event] ?? [])
        ->filter(fn ($l) => is_string($l) && str_starts_with($l, $listener.'@'))->count();

    expect($count(DocumentIssued::class, NotifyDocumentIssued::class))->toBe(1)
        ->and($count(VehicleDefectReported::class, NotifyDefectReported::class))->toBe(1);
});

it('tells the account linked to the record about an issued document, not the issuer', function () {
    $person = Personnel::factory()->create();
    $recipient = User::factory()->create(['personnel_id' => $person->id]);
    $issuer = nl_user('personnel.documents.manage');
    $doc = Document::factory()->issued()->create(['personnel_id' => $person->id, 'issued_by' => $issuer->id, 'title' => 'Ernennung']);

    event(new DocumentIssued($doc));

    $n = DatabaseNotification::sole();
    expect($n->notifiable_id)->toBe($recipient->id)->and($n->type)->toBe('dokument')
        ->and($n->data['link'])->toBe("/documents/{$doc->id}")
        ->and($n->data['title'])->toBe(trans('notifications.document.title'))
        ->and($n->data['message'])->toContain('Ernennung')->toContain($doc->public_id);
});

it('sends nothing for a document issued to oneself, an unlinked record or a deactivated account', function () {
    $person = Personnel::factory()->create();
    $self = User::factory()->create(['personnel_id' => $person->id]);
    event(new DocumentIssued(Document::factory()->issued()->create(['personnel_id' => $person->id, 'issued_by' => $self->id])));

    event(new DocumentIssued(Document::factory()->issued()->create()));

    $gone = Personnel::factory()->create();
    User::factory()->inactive()->create(['personnel_id' => $gone->id]);
    event(new DocumentIssued(Document::factory()->issued()->create(['personnel_id' => $gone->id])));

    expect(DatabaseNotification::count())->toBe(0);
});

it('tells admin, vehicles.view and vehicles.manage about a reported defect, once each, never the reporter', function () {
    $reporter = nl_user('vehicles.view');
    $viewer = nl_user('vehicles.view');
    $manager = nl_user('vehicles.manage');
    $admin = nl_user('admin');
    $super = User::factory()->superAdmin()->create();
    nl_user('audit.view');
    $off = nl_user('vehicles.view');
    $off->forceFill(['is_active' => false])->save();
    $v = Vehicle::factory()->create(['name' => 'RTW 1']);

    $d = (new DefectReporter)->report($v, ['title' => 'Trage klemmt', 'is_operable' => false], $reporter);

    $rows = DatabaseNotification::all();
    expect($rows->pluck('notifiable_id')->sort()->values()->all())->toBe(collect([$viewer->id, $manager->id, $admin->id, $super->id])->sort()->values()->all())
        ->and($rows->pluck('type')->unique()->all())->toBe(['vehicle_defect']);
    expect($rows->first()->data)->toBe([
        'title' => trans('notifications.defect.title', ['title' => 'Trage klemmt']),
        'message' => trans('notifications.defect.message_blocking', ['vehicle' => 'RTW 1']),
        'link' => "/vehicles/defects?vehicle={$v->id}",
    ]);
    expect($d->id)->toBeInt();
});

it('sends no defect notification when the report rolls back', function () {
    nl_user('vehicles.view');
    $v = Vehicle::factory()->create();

    try {
        DB::transaction(function () use ($v) {
            (new DefectReporter)->report($v, ['title' => 'x'], null, 'Crew');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(DatabaseNotification::count())->toBe(0);
});

it('still reports the defect when sending the notification throws', function () {
    nl_user('vehicles.view');
    $reporter = nl_user('vehicles.view');
    $v = Vehicle::factory()->create();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('channel down'));

    $this->actingAs($reporter)->post("/vehicles/{$v->id}/defects", ['title' => 'Licht defekt', 'category' => 'lighting'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(VehicleDefect::where('title', 'Licht defekt')->exists())->toBeTrue();
});

it('still issues the document when sending the notification throws', function () {
    Storage::fake('documents');
    $person = Personnel::factory()->create();
    User::factory()->create(['personnel_id' => $person->id]);
    $doc = Document::factory()->create(['personnel_id' => $person->id]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('channel down'));

    $this->actingAs(nl_user('personnel.documents.manage'))->post("/documents/{$doc->id}/issue")->assertRedirect("/documents/{$doc->id}");
    expect($doc->fresh()->status)->toBe(Document::ISSUED);
});

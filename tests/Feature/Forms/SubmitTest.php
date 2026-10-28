<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forms\Events\FormDeleted;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormSubmission;
use Modules\Forms\Support\AutoFill;
use Modules\Forms\Support\PublicId;

require_once __DIR__.'/formsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function sb_type(array $over = [])
{
    return fs_type([
        ['key' => 'name_dn', 'label' => 'Name und Dienstnummer', 'readonly' => true, 'auto_fill' => 'fullname_dienstnr', 'width' => 'half'],
        ['key' => 'dienstgrad', 'label' => 'Dienstgrad', 'readonly' => true, 'auto_fill' => 'dienstgrad', 'width' => 'half'],
        ['key' => 'wache', 'label' => 'Wache', 'readonly' => true, 'default_value' => 'Wache 1'],
        ['key' => 'grund', 'label' => 'Grund', 'type' => 'textarea', 'required' => true],
        ['key' => 'ok', 'label' => 'Bestätigt', 'type' => 'checkbox'],
        ['key' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['Urlaub', 'Sonderurlaub']],
    ], $over);
}

it('stores the snapshot with readonly values from the record and redirects to the submission', function () {
    $user = fs_user([], fs_record(1, ['full_name' => 'Erika Muster', 'service_number' => 'BF-0042']));
    $type = sb_type();

    $r = $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => [
        'grund' => '  Familienfeier ', 'ok' => true, 'art' => 'Urlaub',
        'name_dn' => 'Gefälscht', 'dienstgrad' => 'General', 'wache' => 'Fake', 'admin_flag' => '1',
    ], 'status' => 'accepted', 'personnel_id' => 999, 'public_id' => '111111']);

    $s = FormSubmission::sole();
    $r->assertRedirect("/forms/{$s->public_id}")->assertSessionHas('status', 'forms.submitted');
    expect($s->public_id)->toMatch('/^[1-9]\d{5}$/')
        ->and($s->status)->toBe('new')
        ->and($s->personnel_id)->toBe($user->personnel_id)
        ->and($s->applicant_name)->toBe('Erika Muster (BF-0042)')
        ->and($s->applicant_rank)->toBe('Brandmeisterin')
        ->and($s->legacy_discord_id)->toBeNull()
        ->and($s->answers)->toBe([
            ['key' => 'name_dn', 'label' => 'Name und Dienstnummer', 'type' => 'text', 'width' => 'half', 'value' => 'Erika Muster (BF-0042)'],
            ['key' => 'dienstgrad', 'label' => 'Dienstgrad', 'type' => 'text', 'width' => 'half', 'value' => 'Brandmeisterin'],
            ['key' => 'wache', 'label' => 'Wache', 'type' => 'text', 'width' => 'full', 'value' => 'Wache 1'],
            ['key' => 'grund', 'label' => 'Grund', 'type' => 'textarea', 'width' => 'full', 'value' => 'Familienfeier'],
            ['key' => 'ok', 'label' => 'Bestätigt', 'type' => 'checkbox', 'width' => 'full', 'value' => true],
            ['key' => 'art', 'label' => 'Art', 'type' => 'select', 'width' => 'full', 'value' => 'Urlaub'],
        ]);
});

it('copies the rank by gender: male name, female name, neutral otherwise', function (int $gender, string $rank) {
    $user = fs_applicant([], $gender);
    $type = sb_type();

    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'x']])->assertRedirect();

    expect(FormSubmission::sole()->applicant_rank)->toBe($rank);
})->with([[0, 'Brandmeister'], [1, 'Brandmeisterin'], [2, 'Brandmeister/-in']]);

it('lets a record with an archived rank submit (legacy 1.3-2)', function () {
    $record = fs_record();
    $record->rank->update(['is_archive' => true]);
    $type = sb_type();

    $this->actingAs(fs_user([], $record))->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'x']])->assertRedirect();

    expect(FormSubmission::sole()->applicant_rank)->toBe('Brandmeister');
});

it('stores an empty rank when the record has none', function () {
    $record = fs_record();
    $record->setRelation('rank', null);
    expect(AutoFill::rank($record))->toBe('');
});

it('cuts the applicant name to the column length', function () {
    $user = fs_user([], fs_record(0, ['full_name' => str_repeat('ä', 255), 'service_number' => 'BF-0001']));
    $type = sb_type();

    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'x']])->assertRedirect();

    expect(FormSubmission::sole()->applicant_name)->toBe(str_repeat('ä', 255));
});

it('never renders raw HTML in the forms pages', function () {
    foreach ([...glob(resource_path('js/Pages/Forms/*.vue')), ...glob(resource_path('js/Pages/Forms/Admin/*.vue')), ...glob(resource_path('js/Pages/Me/*.vue')), ...glob(resource_path('js/components/forms/*.vue'))] as $f) {
        expect(file_get_contents($f))->not->toContain('v-html');
    }
});

it('refuses accounts without a linked record, also super admins', function () {
    $type = sb_type();

    $this->actingAs(fs_user())->get("/forms/new/{$type->id}")->assertForbidden();
    $this->actingAs(fs_user())->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'x']])->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create())->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'x']])->assertForbidden();
    expect(FormSubmission::count())->toBe(0);
});

it('answers 404 for inactive and unknown types', function () {
    $user = fs_applicant();
    $type = sb_type(['is_active' => false]);

    $this->actingAs($user)->get("/forms/new/{$type->id}")->assertNotFound();
    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'x']])->assertNotFound();
    $this->actingAs($user)->get('/forms/new/999999')->assertNotFound();
    $this->actingAs($user)->get('/forms/new/abc')->assertNotFound();
    expect(FormSubmission::count())->toBe(0);
});

it('returns per-field errors and stores nothing', function () {
    $user = fs_applicant();
    $type = sb_type();

    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => ['grund' => '', 'art' => 'Irgendwas']])->assertSessionHasErrors(['grund', 'art']);
    $this->actingAs($user)->postJson("/forms/new/{$type->id}", ['answers' => ['grund' => '', 'ok' => 'vielleicht']])
        ->assertStatus(422)->assertJsonValidationErrors(['grund', 'ok'])->assertJsonPath('errors.grund.0', 'Grund ist ein Pflichtfeld.');
    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => 'kaputt'])->assertSessionHasErrors(['grund']);
    expect(FormSubmission::count())->toBe(0);
});

it('rejects an absence ending before it starts on bis_datum', function () {
    $type = fs_type([['key' => 'von_datum', 'type' => 'date', 'required' => true], ['key' => 'bis_datum', 'type' => 'date', 'required' => true]], ['is_absence' => true]);
    $user = fs_applicant();

    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => ['von_datum' => '2026-11-10', 'bis_datum' => '2026-11-01']])->assertSessionHasErrors('bis_datum');
    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => ['von_datum' => '2026-11-01', 'bis_datum' => '2026-11-10']])->assertSessionHasNoErrors();
    expect(FormSubmission::count())->toBe(1);
});

it('retries a taken public id and gives up after a bounded number of attempts', function () {
    $type = sb_type();
    fs_submission($type, null, ['public_id' => '111111']);
    $ids = ['111111', '111111', '222222'];
    $this->app->instance(PublicId::class, new PublicId(function () use (&$ids) {
        return array_shift($ids);
    }));

    $this->actingAs(fs_applicant())->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'x']])->assertRedirect('/forms/222222');
    expect($ids)->toBe([]);

    $this->app->instance(PublicId::class, new PublicId(fn () => '111111'));
    $s = (new FormSubmission)->forceFill(['form_type_id' => $type->id, 'applicant_name' => 'x', 'status' => 'new', 'answers' => []]);
    expect(fn () => app(PublicId::class)->save($s))->toThrow(UniqueConstraintViolationException::class);
});

it('prefills the form from the record and shows readonly values', function () {
    $user = fs_user([], fs_record(0, ['service_number' => 'BF-0007']));
    $type = sb_type();

    $this->actingAs($user)->get("/forms/new/{$type->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Forms/Create')
        ->where('type.id', $type->id)
        ->where('fields.0.value', 'Max Muster (BF-0007)')->where('fields.0.readonly', true)
        ->where('fields.1.value', 'Brandmeister')
        ->where('fields.2.value', 'Wache 1')
        ->where('fields.3.value', '')
        ->where('fields.4.value', false)
        ->where('fields.5.options', ['Urlaub', 'Sonderurlaub']));
});

it('lists only active types for selection', function () {
    sb_type(['name' => 'Aktiv']);
    sb_type(['name' => 'Aus', 'is_active' => false]);

    $this->actingAs(fs_user())->get('/forms')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Forms/Select')
        ->where('types', fn ($types) => collect($types)->pluck('name')->contains('Aktiv') && ! collect($types)->pluck('name')->contains('Aus'))
        ->where('can.submit', false)->where('can.manage', false));
    $this->actingAs(fs_applicant(['application.view']))->get('/forms')->assertInertia(fn (Assert $p) => $p->where('can.submit', true)->where('can.manage', true));
});

it('keeps old answers visible after a field of the type is deleted (snapshot)', function () {
    $user = fs_applicant();
    $type = sb_type();
    $this->actingAs($user)->post("/forms/new/{$type->id}", ['answers' => ['grund' => 'Hochzeit', 'art' => 'Sonderurlaub']]);
    $s = FormSubmission::sole();

    FormField::where('form_type_id', $type->id)->where('key', 'grund')->delete();
    FormField::where('form_type_id', $type->id)->where('key', 'art')->update(['label' => 'Neu']);

    $this->actingAs($user)->get("/forms/{$s->public_id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Forms/Show')
        ->has('submission.answers', 6)
        ->where('submission.answers.3.label', 'Grund')->where('submission.answers.3.display', 'Hochzeit')
        ->where('submission.answers.5.label', 'Art'));
});

it('shows checkboxes as Ja/Nein from the stored bool and dates in German', function () {
    $owner = fs_applicant();
    $s = fs_submission(fs_type(), $owner->personnel, ['answers' => [
        ['key' => 'a', 'label' => 'A', 'type' => 'checkbox', 'width' => 'half', 'value' => true],
        ['key' => 'b', 'label' => 'B', 'type' => 'checkbox', 'width' => 'half', 'value' => false],
        ['key' => 'c', 'label' => 'C', 'type' => 'checkbox', 'width' => 'full', 'value' => 'off'],
        ['key' => 'd', 'label' => 'D', 'type' => 'date', 'width' => 'full', 'value' => '2026-10-21'],
        'kaputt',
        ['key' => 'e', 'type' => 'unbekannt', 'value' => ['x']],
    ]]);

    $this->actingAs($owner)->get("/forms/{$s->public_id}")->assertInertia(fn (Assert $p) => $p
        ->has('submission.answers', 5)
        ->where('submission.answers.0.display', 'Ja')->where('submission.answers.0.value', true)
        ->where('submission.answers.1.display', 'Nein')
        ->where('submission.answers.2.display', 'Nein')
        ->where('submission.answers.3.display', '21.10.2026')
        ->where('submission.answers.4.type', 'text')->where('submission.answers.4.display', '')->where('submission.answers.4.label', ''));
});

it('escapes script tags in answers in the rendered page', function () {
    $owner = fs_applicant();
    $type = fs_type([['key' => 'grund', 'label' => 'Grund', 'type' => 'textarea']]);
    $this->actingAs($owner)->post("/forms/new/{$type->id}", ['answers' => ['grund' => '<script>alert(1)</script>']]);
    $s = FormSubmission::sole();

    $html = $this->actingAs($owner)->get("/forms/{$s->public_id}")->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(1)')->and($html)->toContain('&lt;script&gt;alert(1)');
    expect($s->answers[0]['value'])->toBe('<script>alert(1)</script>');
});

it('fires FormDeleted when a submission is deleted', function () {
    Event::fake([FormDeleted::class]);
    $s = fs_submission(fs_type());

    $s->delete();

    Event::assertDispatched(FormDeleted::class, fn (FormDeleted $e) => $e->submissionId === $s->id && $e->formTypeId === $s->form_type_id);
});

it('has matching de and en labels', function () {
    $de = json_decode(file_get_contents(base_path('modules/Forms/lang/de.json')), true);
    $en = json_decode(file_get_contents(base_path('modules/Forms/lang/en.json')), true);

    expect(array_keys($de))->toEqualCanonicalizing(array_keys($en));
    foreach ([...FormSubmission::STATUSES, 'open'] as $status) {
        expect($de)->toHaveKey("forms.status.$status");
    }
});

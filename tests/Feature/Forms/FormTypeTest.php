<?php

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormSubmission;
use Modules\Forms\Models\FormType;
use Modules\Forms\Support\DefaultTypes;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ft_admin(): User
{
    return User::factory()->superAdmin()->create();
}

function ft_type(array $over = []): FormType
{
    $t = (new FormType)->forceFill($over + ['name' => 'Typ '.uniqid(), 'is_active' => true, 'is_absence' => false, 'sort_order' => 50]);
    $t->save();

    return $t;
}

function ft_field(FormType $t, string $key, string $type = 'text', int $sort = 1): FormField
{
    $f = (new FormField)->forceFill(['form_type_id' => $t->id, 'key' => $key, 'label' => $key, 'type' => $type, 'sort_order' => $sort]);
    $f->save();

    return $f;
}

/** @return array<string, mixed> */
function ft_body(array $over = []): array
{
    return $over + ['name' => 'Neu', 'description' => 'Text', 'icon' => 'file-text', 'is_active' => true, 'is_absence' => false];
}

it('seeds the two legacy types and does it only into an empty table', function () {
    expect(FormType::orderBy('sort_order')->pluck('name')->all())->toBe(['Beförderungsantrag', 'Urlaubsantrag']);
    $leave = FormType::where('name', 'Urlaubsantrag')->first();
    expect($leave->is_absence)->toBeTrue()
        ->and(FormType::where('name', 'Beförderungsantrag')->first()->fields()->count())->toBe(3)
        ->and($leave->fields()->pluck('key')->all())->toBe(['name_dn', 'dienstgrad', 'von_datum', 'bis_datum', 'grund'])
        ->and($leave->hasAbsenceFields())->toBeTrue();

    DefaultTypes::seed();
    expect(FormType::count())->toBe(2)->and(FormField::count())->toBe(8);
});

it('keeps application.view and application.edit in the catalog of the active module', function () {
    expect(Permissions::catalog())->toHaveKeys(['application.view', 'application.edit'])
        ->and(Permissions::moduleOf('application.edit'))->toBe('forms')
        ->and(Permissions::groups()['application'])->toBe(['application.view', 'application.edit']);
});

it('keeps the stored right of a role while the module is switched off and hides it', function () {
    $role = Role::where('name', 'SGL')->first();
    app(Settings::class)->set('modules.forms.enabled', false);

    expect(Permissions::catalog())->not->toHaveKey('application.edit')
        ->and(Permissions::groups())->not->toHaveKey('application')
        ->and(Permissions::known())->toContain('application.edit')
        ->and($role->fresh()->hasPermissionTo('application.edit'))->toBeTrue();
    $this->actingAs(ft_admin())->get('/settings/forms')->assertNotFound();
});

it('creates, updates, toggles and deletes a type and audits it', function () {
    $admin = ft_admin();
    $id = $this->actingAs($admin)->postJson('/settings/forms', ft_body(['name' => '  Gesuch  ']))->assertCreated()->json('id');
    $type = FormType::find($id);
    expect($type->name)->toBe('Gesuch')->and($type->created_by)->toBe($admin->id)->and($type->sort_order)->toBe(3);

    $this->putJson("/settings/forms/{$id}", ft_body(['name' => 'Anderes', 'icon' => null, 'description' => null, 'is_active' => false]))->assertOk();
    expect($type->fresh()->name)->toBe('Anderes')->and($type->fresh()->is_active)->toBeFalse()->and($type->fresh()->icon)->toBeNull();

    $this->patchJson("/settings/forms/{$id}/active", ['is_active' => true])->assertOk()->assertJson(['is_active' => true]);
    expect($type->fresh()->is_active)->toBeTrue();

    $this->deleteJson("/settings/forms/{$id}")->assertOk();
    expect(FormType::find($id))->toBeNull();

    $log = Activity::where('log_name', 'Anträge')->pluck('description')->all();
    expect($log)->toContain("Antragstyp erstellt [ID: {$id}]", "Antragstyp gelöscht [ID: {$id}]")
        ->and(Activity::where('description', "Antragstyp erstellt [ID: {$id}]")->first()->properties['context']['id'])->toBe($id);
});

it('validates the type form', function (array $over, string $field) {
    $this->actingAs(ft_admin())->postJson('/settings/forms', ft_body($over))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 256)], 'name'],
    'icon with spaces' => [['icon' => 'fa-solid fa-x'], 'icon'],
    'icon too long' => [['icon' => str_repeat('a', 51)], 'icon'],
    'absence without fields' => [['is_absence' => true], 'is_absence'],
]);

it('refuses to delete a type with submissions and keeps it', function () {
    $type = ft_type();
    DB::table('form_submissions')->insert(['public_id' => '123456', 'form_type_id' => $type->id, 'applicant_name' => 'A', 'status' => 'new', 'answers' => '[]', 'submitted_at' => now()]);

    $this->actingAs(ft_admin())->deleteJson("/settings/forms/{$type->id}")->assertStatus(409);
    expect(FormType::find($type->id))->not->toBeNull()->and(FormSubmission::count())->toBe(1);
});

it('adds and deletes fields with the key unique per type', function () {
    $a = ft_type();
    $b = ft_type();
    $this->actingAs(ft_admin());
    $id = $this->postJson("/settings/forms/{$a->id}/fields", ['key' => 'grund', 'label' => 'Grund', 'type' => 'textarea', 'required' => true, 'width' => 'half', 'auto_fill' => 'dienstgrad'])
        ->assertCreated()->json('id');
    $f = FormField::find($id);
    expect($f->required)->toBeTrue()->and($f->width)->toBe('half')->and($f->auto_fill)->toBe('dienstgrad')->and($f->options)->toBeNull()->and($f->sort_order)->toBe(1);

    $this->postJson("/settings/forms/{$a->id}/fields", ['key' => 'grund', 'label' => 'X', 'type' => 'text'])->assertUnprocessable()->assertJsonValidationErrors('key');
    $this->postJson("/settings/forms/{$b->id}/fields", ['key' => 'grund', 'label' => 'X', 'type' => 'text'])->assertCreated();

    $this->deleteJson("/settings/forms/{$b->id}/fields/{$id}")->assertNotFound();
    $this->deleteJson("/settings/forms/{$a->id}/fields/{$id}")->assertOk();
    expect(FormField::find($id))->toBeNull();
});

it('validates the field form', function () {
    foreach ([
        'upper case key' => [['key' => 'Grund'], 'key'],
        'key with digit first' => [['key' => '1abc'], 'key'],
        'key with dash' => [['key' => 'a-b'], 'key'],
        'key too long' => [['key' => 'a'.str_repeat('b', 100)], 'key'],
        'no label' => [['label' => ''], 'label'],
        'unknown type' => [['type' => 'file'], 'type'],
        'select without options' => [['type' => 'select'], 'options'],
        'select with empty list' => [['type' => 'select', 'options' => []], 'options'],
        'select duplicate options' => [['type' => 'select', 'options' => ['a', 'a']], 'options.1'],
        'unknown auto_fill' => [['auto_fill' => 'password'], 'auto_fill'],
        'unknown width' => [['width' => 'third'], 'width'],
    ] as [$body, $field]) {
        $t = ft_type();
        $this->actingAs(ft_admin())->postJson("/settings/forms/{$t->id}/fields", $body + ['key' => 'k', 'label' => 'L', 'type' => 'text'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
});

it('stores select options as a list and ignores options of other types', function () {
    $t = ft_type();
    $this->actingAs(ft_admin());
    $s = $this->postJson("/settings/forms/{$t->id}/fields", ['key' => 'wahl', 'label' => 'W', 'type' => 'select', 'options' => [' A ', 'B']])->assertCreated()->json('id');
    $x = $this->postJson("/settings/forms/{$t->id}/fields", ['key' => 'frei', 'label' => 'F', 'type' => 'text', 'options' => ['A']])->assertCreated()->json('id');

    expect(FormField::find($s)->options)->toBe(['A', 'B'])->and(FormField::find($x)->options)->toBeNull();
});

it('sorts types and fields', function () {
    $this->actingAs(ft_admin());
    $ids = FormType::orderBy('sort_order')->pluck('id')->all();
    $this->putJson('/settings/forms/order', ['ids' => array_reverse($ids)])->assertOk();
    expect(FormType::orderBy('sort_order')->pluck('id')->all())->toBe(array_reverse($ids));

    $t = ft_type();
    $a = ft_field($t, 'a', 'text', 1);
    $b = ft_field($t, 'b', 'text', 2);
    $other = ft_field(ft_type(), 'z');
    $this->putJson("/settings/forms/{$t->id}/fields/order", ['ids' => [$b->id, $a->id]])->assertOk();
    expect($t->fields()->pluck('key')->all())->toBe(['b', 'a']);
    $this->putJson("/settings/forms/{$t->id}/fields/order", ['ids' => [$other->id]])->assertUnprocessable();
    $this->putJson('/settings/forms/order', ['ids' => [999999]])->assertUnprocessable();
    $this->putJson('/settings/forms/order', ['ids' => [$ids[0], $ids[0]]])->assertUnprocessable();
});

it('allows is_absence only with von_datum and bis_datum of type date, and keeps them', function () {
    $this->actingAs(ft_admin());
    $t = ft_type();
    ft_field($t, 'von_datum', 'date', 1);
    $wrong = ft_field($t, 'bis_datum', 'text', 2);
    $this->putJson("/settings/forms/{$t->id}", ft_body(['is_absence' => true]))->assertUnprocessable()->assertJsonValidationErrors('is_absence');

    $wrong->delete();
    $bis = ft_field($t, 'bis_datum', 'date', 2);
    $this->putJson("/settings/forms/{$t->id}", ft_body(['is_absence' => true]))->assertOk();
    expect($t->fresh()->is_absence)->toBeTrue();

    $this->deleteJson("/settings/forms/{$t->id}/fields/{$bis->id}")->assertUnprocessable();
    expect(FormField::find($bis->id))->not->toBeNull();
    $other = ft_field($t, 'grund');
    $this->deleteJson("/settings/forms/{$t->id}/fields/{$other->id}")->assertOk();
    $this->putJson("/settings/forms/{$t->id}", ft_body(['is_absence' => false]))->assertOk();
    $this->deleteJson("/settings/forms/{$t->id}/fields/{$bis->id}")->assertOk();
});

it('renders the index and edit pages with counts', function () {
    $this->actingAs(ft_admin());
    $this->get('/settings/forms')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Forms/Settings/Index')
        ->has('types', 2)->where('types.0.fields_count', 3)->where('types.1.is_absence', true)->where('types.0.submissions_count', 0));
    $this->get('/settings/forms/create')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Forms/Settings/Edit')->where('type', null));
    $id = FormType::orderBy('sort_order')->first()->id;
    $this->get("/settings/forms/{$id}/edit")->assertOk()->assertInertia(fn (Assert $p) => $p->component('Forms/Settings/Edit')->has('fields', 3)->where('type.id', $id));
    $this->get('/settings/forms/999999/edit')->assertNotFound();
});

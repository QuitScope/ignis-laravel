<?php

use App\Models\Personnel;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormSubmission;
use Modules\Forms\Models\FormType;

function fs_user(array $perms = [], ?Personnel $record = null): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create(['personnel_id' => $record?->id]);
}

function fs_record(int $gender = 0, array $over = []): Personnel
{
    $rank = Rank::factory()->create(['name' => 'Brandmeister/-in', 'name_male' => 'Brandmeister', 'name_female' => 'Brandmeisterin']);

    return Personnel::factory()->create($over + ['full_name' => 'Max Muster', 'gender' => $gender, 'rank_id' => $rank->id, 'discord_id' => '123456789012345678']);
}

function fs_applicant(array $perms = [], int $gender = 0): User
{
    return fs_user($perms, fs_record($gender));
}

/** @param list<array<string, mixed>> $fields */
function fs_type(array $fields = [], array $over = []): FormType
{
    $type = (new FormType)->forceFill($over + ['name' => 'Typ '.uniqid(), 'icon' => 'file-text', 'is_active' => true, 'is_absence' => false, 'sort_order' => 50]);
    $type->save();
    foreach ($fields as $i => $f) {
        (new FormField)->forceFill($f + ['form_type_id' => $type->id, 'label' => $f['key'], 'type' => 'text', 'sort_order' => $i + 1])->save();
    }

    return $type;
}

function fs_submission(FormType $type, ?Personnel $record = null, array $over = []): FormSubmission
{
    $s = (new FormSubmission)->forceFill($over + [
        'public_id' => (string) random_int(100000, 999999), 'form_type_id' => $type->id, 'personnel_id' => $record?->id,
        'applicant_name' => 'Max Muster (BF-0001)', 'applicant_rank' => 'Brandmeister', 'status' => 'new', 'submitted_at' => now(),
        'answers' => [['key' => 'grund', 'label' => 'Grund', 'type' => 'text', 'width' => 'full', 'value' => 'Urlaub']],
    ]);
    $s->save();

    return $s;
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body `personnel_id` (link) or `unlink=1`. The target is authorised here (403 before 422): users.edit
 * and the rank rules of UserPolicy::linkPersonnel (update, plus a super admin's own account). No `exists` rule:
 * AccountLink reports a missing record itself, also when it disappears after validation.
 */
class LinkPersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('linkPersonnel', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unlink' => ['sometimes', 'boolean'],
            'personnel_id' => ['exclude_if:unlink,1,true', 'required', 'integer', 'min:1'],
        ];
    }
}

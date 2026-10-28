<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Only the role is editable (legacy parity); the target is authorised here (403 before 422), the new role in the controller. */
class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['required', 'integer', 'exists:roles,id']];
    }
}

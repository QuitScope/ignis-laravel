<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The full set of specialty ids; an empty or missing list removes all. */
class PersonnelSpecialtiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('personnel'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['integer', Rule::exists('specialties', 'id')],
        ];
    }
}

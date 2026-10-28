<?php

namespace App\Http\Requests\Settings;

use Illuminate\Validation\Rule;

class SpecialtyRequest extends LookupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'number' => ['required', 'integer', 'between:0,9999', Rule::unique('specialties', 'number')->ignore($this->ignoredId())],
            'name' => ['required', 'string', 'max:255'],
            'is_disabled' => ['boolean'],
        ];
    }
}

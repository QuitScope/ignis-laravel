<?php

namespace App\Http\Requests\Settings;

class FireQualificationRequest extends LookupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->priority() + [
            'short_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'name_male' => ['required', 'string', 'max:255'],
            'name_female' => ['required', 'string', 'max:255'],
            'is_none' => ['boolean'],
        ];
    }
}

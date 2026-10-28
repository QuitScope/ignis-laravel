<?php

namespace App\Http\Requests\Settings;

class EmsQualificationRequest extends LookupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->priority() + [
            'name' => ['required', 'string', 'max:255'],
            'name_male' => ['required', 'string', 'max:255'],
            'name_female' => ['required', 'string', 'max:255'],
            'abbreviation' => ['nullable', 'string', 'max:50'],
            'is_none' => ['boolean'],
            'is_trainable' => ['boolean'],
        ];
    }
}

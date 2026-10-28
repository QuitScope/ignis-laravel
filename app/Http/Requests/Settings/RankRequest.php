<?php

namespace App\Http\Requests\Settings;

class RankRequest extends LookupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->priority() + [
            'name' => ['required', 'string', 'max:255'],
            'name_male' => ['required', 'string', 'max:255'],
            'name_female' => ['required', 'string', 'max:255'],
            'badge' => ['nullable', 'string', 'max:255'],
            'is_archive' => ['boolean'],
        ];
    }
}

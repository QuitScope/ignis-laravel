<?php

namespace App\Http\Requests\Settings;

use App\Models\PersonnelTitle;

class PersonnelTitleRequest extends LookupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->priority() + [
            'name' => ['required', 'string', 'max:50', function ($attr, $value, $fail) {
                $taken = PersonnelTitle::whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                    ->when($this->ignoredId() !== null, fn ($q) => $q->whereKeyNot($this->ignoredId()))
                    ->exists();
                if ($taken) {
                    $fail(trans('validation.unique', ['attribute' => $attr]));
                }
            }],
        ];
    }
}

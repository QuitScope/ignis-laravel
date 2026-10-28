<?php

namespace Modules\FireTab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FireTab\Enums\AsuPosition;
use Modules\FireTab\Enums\AsuRole;

/** Create or edit a Trupp; members come as a list of places, an empty name frees the place. */
class TruppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('incident')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'role' => ['required', Rule::enum(AsuRole::class)],
            'task' => ['nullable', 'string', 'max:255'],
            'expected_minutes' => ['required', 'integer', 'between:5,120'],
            'members' => ['required', 'array', 'min:1', 'max:3'],
            'members.*.position' => ['required', 'distinct', Rule::enum(AsuPosition::class)],
            'members.*.name' => ['nullable', 'string', 'max:100'],
        ];
    }
}

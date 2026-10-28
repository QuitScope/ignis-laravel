<?php

namespace App\Http\Requests;

use App\Models\PersonnelLogEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** New note; legacy asked for personnel.update. The author is never read from the request. */
class PersonnelNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('personnel'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'integer', Rule::in(PersonnelLogEntry::NOTE_TYPES)],
            'content' => ['required', 'string', 'max:5000'],
        ];
    }
}

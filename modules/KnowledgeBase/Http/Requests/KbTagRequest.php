<?php

namespace Modules\KnowledgeBase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\KnowledgeBase\Models\KbEntry;

/** Tag form. The case-insensitive name check answers 409 in the controller, not 422. */
class KbTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('edit', KbEntry::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}

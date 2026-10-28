<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Drag-and-drop result: the target category and its item ids in the new order (at most 500). */
class ReorderLoadoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:loadout_categories,id'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1', 'max:999999999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return LoadoutItemRequest::messagesFor(['category_id' => 'loadouts.error.category', 'ids' => 'loadouts.error.ids', 'ids.*' => 'loadouts.error.ids']);
    }

    /** @return list<int> the ids without duplicates, in submitted order */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('ids'))));
    }
}

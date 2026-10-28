<?php

namespace App\Http\Requests;

use App\Models\LoadoutCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Loadout category (legacy add/edit_category): title 1-255, compartment 0-2, vehicle type up to 255 (empty = all
 * types), sort order. The ability is checked here so 403 comes before 422.
 */
class LoadoutCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, ['title', 'vehicle_type']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'compartment' => ['required', 'integer', Rule::in([LoadoutCategory::KIT, LoadoutCategory::INTERIOR, LoadoutCategory::EXTERIOR])],
            'vehicle_type' => ['nullable', 'string', 'max:255', CreateDefectRequest::utf8()],
            'sort_order' => ['nullable', 'integer', 'between:-100000,100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return LoadoutItemRequest::messagesFor([
            'title' => 'loadouts.error.title', 'compartment' => 'loadouts.error.compartment',
            'vehicle_type' => 'loadouts.error.vehicle_type', 'sort_order' => 'loadouts.error.sort_order',
        ]);
    }

    /** @return array{title: string, compartment: int, vehicle_type: ?string, sort_order?: int} */
    public function categoryData(): array
    {
        $data = $this->validated();

        $out = [
            'title' => $data['title'],
            'compartment' => (int) $data['compartment'],
            'vehicle_type' => $data['vehicle_type'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
        if (! $this->isMethod('POST') && ($data['sort_order'] ?? null) === null) {
            unset($out['sort_order']);
        }

        return $out;
    }
}

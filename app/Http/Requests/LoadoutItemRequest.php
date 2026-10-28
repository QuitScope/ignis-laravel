<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Loadout item (legacy add/edit_tile): title 1-255, quantity 0-100000, on edit optionally another category. PATCH is
 * the inline quantity edit and takes the quantity only. The ability is checked here so 403 comes before 422.
 */
class LoadoutItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, ['title']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $quantity = ['required', 'integer', 'between:0,100000'];
        if ($this->isMethod('PATCH')) {
            return ['quantity' => $quantity];
        }

        return [
            'title' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'quantity' => $quantity,
            'category_id' => ['nullable', 'integer', 'exists:loadout_categories,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::messagesFor(['title' => 'loadouts.error.title', 'quantity' => 'loadouts.error.quantity', 'category_id' => 'loadouts.error.category']);
    }

    /**
     * One translated message per field for every rule used here (the app has no validation lang files yet).
     *
     * @param  array<string, string>  $fields  field => translation key
     * @return array<string, string>
     */
    public static function messagesFor(array $fields): array
    {
        $messages = [];
        foreach ($fields as $field => $key) {
            foreach (['required', 'string', 'max', 'integer', 'in', 'between', 'exists', 'min', 'array'] as $rule) {
                $messages["$field.$rule"] = trans($key);
            }
        }

        return $messages;
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Apply to type": the vehicle type (free text, as on the vehicle) the template overwrites. The ability is checked
 * here so 403 comes before 422.
 */
class ApplyTacticalSymbolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, ['type']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['type' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return LoadoutItemRequest::messagesFor(['type' => 'symbols.error.type']);
    }
}

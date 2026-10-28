<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle.manage') === true && $this->user()->can('vehicle.view');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, ['resolution_note']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['resolution_note' => ['nullable', 'string', 'max:5000', CreateDefectRequest::utf8()]];
    }
}

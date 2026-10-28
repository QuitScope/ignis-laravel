<?php

namespace Modules\FireTab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** System vehicle by id (from the search) or external vehicle with a radio name. */
class VehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('incident')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'radio_name' => ['nullable', 'required_without:vehicle_id', 'string', 'max:255'],
        ];
    }
}

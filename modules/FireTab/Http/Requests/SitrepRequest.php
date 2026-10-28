<?php

namespace Modules\FireTab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SitrepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('incident')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'text' => ['required', 'string', 'max:2000'],
            'incident_vehicle_id' => ['required', 'integer', Rule::exists('fire_incident_vehicles', 'id')->where('fire_incident_id', $this->route('incident')->id)],
        ];
    }
}

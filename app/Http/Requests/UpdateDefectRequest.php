<?php

namespace App\Http\Requests;

use App\Models\VehicleDefect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Status, status note and assignment of a defect. `resolved` is reached only through the resolve route (it sets
 * the resolved_* columns); `assigned_to` takes an active account or null (removes the assignment).
 * Every status change names the status the dialog showed (`from_status`), so a stale dialog cannot reopen a defect
 * that was resolved meanwhile (checked against the locked row in the controller).
 */
class UpdateDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle.manage') === true && $this->user()->can('vehicle.view');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, ['status_note']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'string', Rule::in(array_diff(VehicleDefect::STATUSES, [VehicleDefect::RESOLVED]))],
            'from_status' => ['required_with:status', 'string', Rule::in(VehicleDefect::STATUSES)],
            'status_note' => ['nullable', 'string', 'max:5000', CreateDefectRequest::utf8()],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }
}

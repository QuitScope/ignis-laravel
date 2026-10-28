<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk action of the vehicle list. Malformed ids are dropped (as the legacy selection did); the controller
 * ignores unknown ones and answers 422 when none is left.
 */
class VehicleBulkRequest extends FormRequest
{
    public const ACTIONS = ['activate', 'deactivate', 'delete', 'fms_status'];

    public const MAX_IDS = 200;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'max:'.self::MAX_IDS],
            'fms_status' => ['exclude_unless:action,fms_status', 'required', Rule::in(array_map('strval', array_keys(Vehicle::FMS_LABELS)))],
        ];
    }

    /** @return list<int> distinct ids of the form 1..18 digits; everything else is dropped */
    public function ids(): array
    {
        $ids = [];
        foreach ((array) $this->input('ids', []) as $id) {
            if ((is_int($id) || is_string($id)) && preg_match('/^[1-9]\d{0,17}$/D', (string) $id) === 1) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }
}

<?php

namespace Modules\ManvBoard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;

/** Departure. No input: the transport rules are checked under lock in the controller. */
class DepartureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof ManvIncident && (new ManvIncidentPolicy)->canWrite($this->user(), $incident);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}

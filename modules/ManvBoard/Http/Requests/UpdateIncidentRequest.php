<?php

namespace Modules\ManvBoard\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\ManvBoard\Enums\ManvIncidentStatus;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;

/** Update. A closed or archived Lage takes the status only; an unknown status is a 422, never "active". */
class UpdateIncidentRequest extends StoreIncidentRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof ManvIncident && $this->user()?->can('update', $incident) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $status = Rule::enum(ManvIncidentStatus::class);
        $incident = $this->route('incident');
        if ($incident instanceof ManvIncident && ! (new ManvIncidentPolicy)->isWritable($incident)) {
            return ['status' => ['required', $status]];
        }

        return parent::rules() + ['status' => ['sometimes', 'required', $status]];
    }
}

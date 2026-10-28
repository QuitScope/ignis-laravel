<?php

namespace Modules\ManvBoard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ManvBoard\Enums\ResourceStatus;
use Modules\ManvBoard\Enums\ResourceType;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvResource;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;

/**
 * Create (stock vehicle via vehicle_id, or free) and update of a resource; delete only authorizes.
 * Writes need manage and an active Lage. For a stock vehicle, label/callsign/type come from the vehicle (see ResourceController).
 */
class ResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof ManvIncident && (new ManvIncidentPolicy)->canWrite($this->user(), $incident);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return [];
        }
        $incident = $this->route('incident');
        $incidentId = $incident instanceof ManvIncident ? $incident->id : 0;
        $resource = $this->route('resource');
        $label = ['string', 'max:255', Rule::unique('manv_resources', 'label')->where('manv_incident_id', $incidentId)
            ->ignore($resource instanceof ManvResource ? $resource->id : null)];
        $common = [
            'status' => ['nullable', Rule::enum(ResourceStatus::class)],
            'crew' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:65000'],
            'callsign' => ['nullable', 'string', 'max:255'],
            'vehicle_type' => ['nullable', 'string', 'max:255'],
        ];

        if ($resource instanceof ManvResource) {
            return $common + ['label' => ['required', ...$label, function (string $a, mixed $v, \Closure $fail) use ($resource) {
                if ($resource->departed_at !== null && $v !== $resource->label) {
                    $fail(trans('manvboard.resources.departed_locked'));
                }
            }]];
        }

        return $common + [
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('is_active', true),
                Rule::unique('manv_resources', 'vehicle_id')->where('manv_incident_id', $incidentId)],
            'label' => ['required_without:vehicle_id', 'nullable', ...$label],
            'type' => ['required_without:vehicle_id', 'nullable', Rule::enum(ResourceType::class)],
        ];
    }
}

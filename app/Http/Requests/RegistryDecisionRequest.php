<?php

namespace App\Http\Requests;

use App\Models\VehicleRegistryEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A decision on a registry entry (legacy ImportQueueItemRequest). `vehicle_id` is the vehicle to overwrite or merge
 * into; name, identifier, type, service and allowed_jobs optionally replace the entry's proposal (an empty field keeps
 * it); `ignore` validates nothing. The ability is checked here so 403 comes before 422.
 */
class RegistryDecisionRequest extends FormRequest
{
    private const TEXTS = ['name', 'identifier', 'type', 'allowed_jobs'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manageImport');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, self::TEXTS));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->route('action') === 'ignore') {
            return [];
        }
        $target = in_array($this->route('action'), ['overwrite', 'merge'], true);

        return [
            'vehicle_id' => [$target ? 'required' : 'nullable', 'integer', Rule::exists('vehicles', 'id')],
            'name' => ['nullable', 'string', 'max:255', CreateDefectRequest::utf8()],
            'identifier' => ['nullable', 'string', 'max:255', CreateDefectRequest::utf8()],
            'type' => ['nullable', 'string', 'max:255', CreateDefectRequest::utf8()],
            'service' => ['nullable', 'integer', Rule::in([0, 1, 2, 3])],
            'allowed_jobs' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $fields = ['vehicle_id', 'name', 'identifier', 'type', 'service', 'allowed_jobs'];

        return LoadoutItemRequest::messagesFor(array_combine($fields, array_map(fn (string $f) => 'vehicles.registry.error.'.$f, $fields)));
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('allowed_jobs') && is_string($this->input('allowed_jobs'))) {
                SaveVehicleRequest::checkJobs($validator, SaveVehicleRequest::parseJobs($this->input('allowed_jobs')));
            }
        }];
    }

    /**
     * The vehicle columns the entry proposes, with the form's overrides; the jobs come from the EMD's `job` text.
     *
     * @return array{name: string, identifier: string, type: string, service: int, allowed_jobs: ?list<string>}
     */
    public function proposal(VehicleRegistryEntry $entry): array
    {
        $data = $this->validated();
        $jobs = isset($data['allowed_jobs'])
            ? SaveVehicleRequest::parseJobs($data['allowed_jobs']) ?? []
            : SaveVehicleRequest::fitJobs(SaveVehicleRequest::parseJobs($entry->job));

        return [
            'name' => $data['name'] ?? $entry->name,
            'identifier' => $data['identifier'] ?? $entry->identifier ?? '',
            'type' => $data['type'] ?? $entry->type ?? '',
            'service' => (int) ($data['service'] ?? $entry->service),
            'allowed_jobs' => $jobs === [] ? null : $jobs,
        ];
    }
}

<?php

namespace Modules\ManvBoard\Http\Requests;

use App\Models\Poi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\ManvBoard\Enums\ResourceType;
use Modules\ManvBoard\Enums\Sex;
use Modules\ManvBoard\Enums\Triage;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;
use Modules\ManvBoard\Support\TransportRules;

/** Create a patient. Number, creator, snapshot and destination name are set by the server. */
class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof ManvIncident && (new ManvIncidentPolicy)->canWrite($this->user(), $incident);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $incident = $this->route('incident');
        $incidentId = $incident instanceof ManvIncident ? $incident->id : 0;

        return [
            'last_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'triage' => ['required', Rule::enum(Triage::class)],
            'manv_resource_id' => ['nullable', 'integer', $this->existing('manv_resource_id', Rule::exists('manv_resources', 'id')
                ->where('manv_incident_id', $incidentId)->where('type', ResourceType::Vehicle->value)->whereNull('departed_at'))],
            'destination_poi_id' => ['nullable', 'integer', $this->existing('destination_poi_id', Rule::exists('pois', 'id')
                ->whereIn('type', Poi::HOSPITAL_TYPES)->where('is_active', true))],
            'destination' => ['nullable', 'string', 'max:255', $this->destination()],
            'injuries' => ['nullable', 'string', 'max:65000'],
            'notes' => ['nullable', 'string', 'max:65000'],
        ];
    }

    private function destination(): \Closure
    {
        $patient = $this->route('patient');
        $stored = $patient instanceof ManvPatient ? $patient->destination : null;

        return function (string $attribute, mixed $value, \Closure $fail) use ($stored) {
            $poi = $this->integer('destination_poi_id');
            if ($value === TransportRules::NO_TRANSPORT || ($stored !== null && $value === $stored)
                || ($poi > 0 && Poi::query()->whereKey($poi)->value('name') === $value)) {
                return;
            }
            $fail(trans('manvboard.destination_invalid'));
        };
    }

    private function existing(string $field, Exists $rule): \Closure
    {
        $patient = $this->route('patient');
        $stored = $patient instanceof ManvPatient ? $patient->getAttribute($field) : null;

        return function (string $attribute, mixed $value, \Closure $fail) use ($stored, $rule) {
            if ($stored !== null && (int) $value === (int) $stored) {
                return;
            }
            if (validator([$attribute => $value], [$attribute => [$rule]])->fails()) {
                $fail(trans('validation.exists', ['attribute' => $attribute]));
            }
        };
    }
}

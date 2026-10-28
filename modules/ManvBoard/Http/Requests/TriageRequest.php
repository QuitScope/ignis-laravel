<?php

namespace Modules\ManvBoard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ManvBoard\Enums\Triage;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;

/** Quick triage (PATCH, replaces the legacy GET `quick_sk`). */
class TriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof ManvIncident && (new ManvIncidentPolicy)->canWrite($this->user(), $incident);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['triage' => ['required', Rule::enum(Triage::class)]];
    }
}

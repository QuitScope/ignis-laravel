<?php

namespace Modules\ManvBoard\Http\Requests;

use App\Models\Rank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ManvBoard\Models\ManvIncident;

/** Create. Only these fields are read: names, creator and status are set by the server. */
class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ManvIncident::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'incident_number' => ['required', 'string', 'max:50'],
            'location' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:65000'],
            'notes' => ['nullable', 'string', 'max:65000'],
            'started_at' => ['nullable', 'date'],
            'lna_personnel_id' => ['nullable', 'integer', $this->person('lna_personnel_id')],
            'orgl_personnel_id' => ['nullable', 'integer', $this->person('orgl_personnel_id')],
        ];
    }

    protected function person(string $field): \Closure
    {
        $incident = $this->route('incident');
        $stored = $incident instanceof ManvIncident ? $incident->getAttribute($field) : null;
        $active = Rule::exists('personnel', 'id')->where(fn ($q) => $q->whereIn('rank_id', Rank::query()->where('is_archive', false)->select('id')));

        return function (string $attribute, mixed $value, \Closure $fail) use ($stored, $active) {
            if ($stored !== null && (int) $value === (int) $stored) {
                return;
            }
            if (validator([$attribute => $value], [$attribute => [$active]])->fails()) {
                $fail(trans('validation.exists', ['attribute' => $attribute]));
            }
        };
    }
}

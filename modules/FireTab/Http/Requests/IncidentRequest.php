<?php

namespace Modules\FireTab\Http\Requests;

use App\Models\Personnel;
use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Federation\Support\FederatedPersonSearch;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Support\OrganizationTime;

/** Master data (create and update). Start date and time are wall time in the organisation zone (config ignis.timezone). */
class IncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof FireIncident
            ? $this->user()?->can('update', $incident) === true
            : $this->user()?->can('create', FireIncident::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $incident = $this->route('incident');

        return [
            'number' => ['nullable', 'string', 'max:50', Rule::unique('fire_incidents', 'number')->ignore($incident instanceof FireIncident ? $incident->id : null)],
            'location' => ['required', 'string', 'max:255'],
            'keyword' => ['required', 'string', 'max:255'],
            'started_date' => ['required', 'date_format:Y-m-d'],
            'started_time' => ['required', 'date_format:H:i'],
            'leader_personnel_id' => ['required', function (string $attr, mixed $v, Closure $fail) use ($incident) {
                $ok = match (true) {
                    is_int($v) || (is_string($v) && ctype_digit($v)) => Personnel::query()->whereKey((int) $v)->exists(),
                    $v === 'external' => $incident instanceof FireIncident && $incident->leader_external_name !== null,
                    is_string($v) && str_starts_with($v, 'fed:') => self::external($v) !== null,
                    default => false,
                };
                if (! $ok) {
                    $fail(__('validation.exists', ['attribute' => $attr]));
                }
            }],
            'caller_name' => ['nullable', 'string', 'max:255'],
            'caller_contact' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_contact' => ['nullable', 'string', 'max:255'],
            ...($incident instanceof FireIncident ? [] : ['notes' => ['nullable', 'string', 'max:20000']]),
        ];
    }

    /**
     * Validated fields as columns; start as UTC.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $d = $this->validated();
        $d['started_at'] = OrganizationTime::utc($d['started_date'], $d['started_time']);
        unset($d['started_date'], $d['started_time']);

        $leader = $d['leader_personnel_id'];
        unset($d['leader_personnel_id']);
        if ($leader !== 'external') {
            $external = is_string($leader) && str_starts_with($leader, 'fed:') ? self::external($leader) : null;
            $d['leader_personnel_id'] = $external === null ? (int) $leader : null;
            $d['leader_external_name'] = $external;
        }

        return $d;
    }

    private static function external(string $id): ?string
    {
        return ModuleRegistry::active('federation') ? FederatedPersonSearch::resolve($id) : null;
    }
}

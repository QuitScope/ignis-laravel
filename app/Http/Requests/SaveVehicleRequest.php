<?php

namespace App\Http\Requests;

use App\Models\Poi;
use App\Models\Vehicle;
use App\Vehicles\TacticalSymbol;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Vehicle form (legacy SaveVehicleRequest). The FMS status is not part of it; the tactical symbol is optional and only
 * stored when the form sends it, so a request without one keeps the stored symbol.
 * Empty texts arrive as null (ConvertEmptyStringsToNull); the ability is checked here so 403 comes before 422.
 */
class SaveVehicleRequest extends FormRequest
{
    private const TEXTS = ['name', 'type', 'identifier', 'license_plate', 'allowed_jobs', 'tactical_symbol_name'];

    private const MAX_SYMBOL_NAME = 100;

    private const MAX_JOBS = 50;

    private const MAX_JOB_LENGTH = 100;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manage');
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (self::TEXTS as $key) {
            if (is_string($this->input($key))) {
                $value = trim(str_replace("\0", '', $this->input($key)));
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        if (isset($clean['tactical_symbol_name'])) {
            $clean['tactical_symbol_name'] = trim(str_replace(']]>', '', $clean['tactical_symbol_name'])) ?: null;
        }
        if (is_array($this->input('tactical_symbol')) && ! TacticalSymbol::isList($this->input('tactical_symbol'))) {
            $clean['tactical_symbol'] = TacticalSymbol::clean($this->input('tactical_symbol'));
        }
        $this->merge($clean);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('vehicle') instanceof Vehicle ? $this->route('vehicle')->station_poi_id : null;

        return [
            'name' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'type' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'identifier' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'license_plate' => ['nullable', 'string', 'max:255', CreateDefectRequest::utf8()],
            'service' => ['required', 'integer', Rule::in([0, 1, 2, 3])],
            'sort_order' => ['nullable', 'integer', 'min:-2147483648', 'max:2147483647'],
            'is_active' => ['boolean'],
            'station_poi_id' => ['nullable', 'integer', Rule::exists('pois', 'id')->where(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->where('is_active', true)->whereIn('type', Poi::STATION_TYPES))
                ->when($current !== null, fn (Builder $w) => $w->orWhere('id', $current)))],
            'allowed_jobs' => ['nullable', 'string', 'max:500'],
            'tactical_symbol' => ['nullable', 'array', TacticalSymbol::shapeRule()],
            ...TacticalSymbol::rules('tactical_symbol'),
            'tactical_symbol_name' => ['nullable', 'string', 'max:'.self::MAX_SYMBOL_NAME, CreateDefectRequest::utf8()],
        ];
    }

    /** @return array<string, string> own messages for the symbol fields (the app has no validation lang files yet) */
    public function messages(): array
    {
        return TacticalSymbol::messages('tactical_symbol')
            + LoadoutItemRequest::messagesFor(['tactical_symbol' => 'symbols.error.shape', 'tactical_symbol_name' => 'symbols.error.symbol_name']);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('allowed_jobs')) {
                return;
            }
            self::checkJobs($validator, self::parseJobs($this->input('allowed_jobs')));
        }];
    }

    /**
     * Adds the `allowed_jobs` error when the parsed list is invalid, too long or has an overlong job.
     *
     * @param  ?list<string>  $jobs  parseJobs()
     */
    public static function checkJobs(Validator $validator, ?array $jobs): void
    {
        if ($jobs === null || count($jobs) > self::MAX_JOBS || array_filter($jobs, fn (string $j) => mb_strlen($j) > self::MAX_JOB_LENGTH) !== []) {
            $validator->errors()->add('allowed_jobs', trans('vehicles.error.allowed_jobs', ['count' => self::MAX_JOBS, 'length' => self::MAX_JOB_LENGTH]));
        }
    }

    /**
     * A parsed list cut to what checkJobs() accepts: overlong jobs dropped, at most MAX_JOBS kept. For job texts that
     * are not validated, e.g. the EMD's.
     *
     * @param  ?list<string>  $jobs  parseJobs()
     * @return list<string>
     */
    public static function fitJobs(?array $jobs): array
    {
        return array_slice(array_values(array_filter($jobs ?? [], fn (string $j) => mb_strlen($j) <= self::MAX_JOB_LENGTH)), 0, self::MAX_JOBS);
    }

    /**
     * The vehicle columns, explicitly.
     *
     * @return array<string, mixed>
     */
    public function vehicleData(): array
    {
        $data = $this->validated();
        $jobs = self::parseJobs($this->input('allowed_jobs')) ?? [];

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'identifier' => $data['identifier'],
            'license_plate' => $data['license_plate'] ?? null,
            'service' => (int) $data['service'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $this->boolean('is_active'),
            'station_poi_id' => isset($data['station_poi_id']) ? (int) $data['station_poi_id'] : null,
            'allowed_jobs' => $jobs === [] ? null : $jobs,
            ...$this->symbolData(),
        ];
    }

    /**
     * The tactical symbol columns the form sent: null when every field is empty.
     *
     * @return array<string, mixed>
     */
    private function symbolData(): array
    {
        $out = [];
        if ($this->has('tactical_symbol')) {
            $symbol = TacticalSymbol::validate((array) $this->input('tactical_symbol'));
            $out['tactical_symbol'] = $symbol === [] ? null : $symbol;
        }
        if ($this->has('tactical_symbol_name')) {
            $out['tactical_symbol_name'] = $this->validated('tactical_symbol_name');
        }

        return $out;
    }

    /**
     * `allowed_jobs` split on commas and line breaks, control characters removed, trimmed (NBSP too), duplicates
     * dropped case-insensitively (first spelling kept); null for text that is not valid UTF-8. Also reads the
     * registry's `job` text (VehicleRegistryController).
     *
     * @return ?list<string>
     */
    public static function parseJobs(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return [];
        }
        $parts = preg_split('/[,\r\n]+/u', $raw);
        if ($parts === false) {
            return null;
        }
        $jobs = [];
        foreach ($parts as $part) {
            $job = (string) preg_replace(['/\p{Cc}/u', '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u'], '', $part);
            if ($job !== '') {
                $jobs[mb_strtolower($job)] ??= $job;
            }
        }

        return array_values($jobs);
    }
}

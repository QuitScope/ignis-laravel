<?php

namespace App\Emd;

/**
 * One EMD sync body, normalised. Both shapes are accepted where the legacy controller and ef_bridge
 * disagree: `fallback_statuses` as a list or `{entries: [...]}`, `lagemeldungen` as `{number: [...]}` or
 * `{einsaetze: [{einsatznummer, lagemeldungen}]}`. V2 sends the vehicles in `dispatch_data`, V1 in `data`.
 */
final readonly class EmdPayload
{
    /**
     * @param  ?array<array-key, mixed>  $registry  `vehicle_registry` rows, null when not sent
     * @param  array<string, list<array<string, mixed>>>  $dispatches  dispatch number => its vehicle entries (value, dispatch, dispatch_data)
     * @param  array<string, array<string, mixed>>  $dispatchData  dispatch number => the first `dispatch_data` sent for it
     * @param  list<array<string, mixed>>  $statuses  `status_updates.statuses` (V1: top-level `statuses`)
     * @param  list<array<string, mixed>>  $fallbacks
     * @param  array<string, list<array<string, mixed>>>  $sitreps  dispatch number => top-level `lagemeldungen`
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?int $version,
        public ?string $type,
        public ?array $registry,
        public int $totalVehicles,
        public array $dispatches,
        public array $dispatchData,
        public array $statuses,
        public array $fallbacks,
        public array $sitreps,
        public array $raw,
    ) {}

    /** @param array<array-key, mixed> $json */
    public static function fromRequest(array $json): self
    {
        $vehicles = self::list(self::map($json['dispatch_data'] ?? $json['data'] ?? null)['vehicles'] ?? null);
        $dispatches = [];
        $dispatchData = [];
        foreach ($vehicles as $vehicle) {
            $number = self::number($vehicle['dispatch'] ?? null);
            if ($number === null) {
                continue;
            }
            $dispatches[$number][] = $vehicle;
            if (is_array($vehicle['dispatch_data'] ?? null) && ! isset($dispatchData[$number])) {
                $dispatchData[$number] = $vehicle['dispatch_data'];
            }
        }

        $fallbacks = self::map($json['fallback_statuses'] ?? null);
        $sitreps = [];
        $lage = self::map($json['lagemeldungen'] ?? null);
        foreach (array_key_exists('einsaetze', $lage) ? self::list($lage['einsaetze']) : array_map(fn ($entries, $number) => ['einsatznummer' => $number, 'lagemeldungen' => $entries], $lage, array_keys($lage)) as $group) {
            $number = self::number($group['einsatznummer'] ?? null);
            if ($number !== null) {
                $sitreps[$number] = [...$sitreps[$number] ?? [], ...self::list($group['lagemeldungen'] ?? null)];
            }
        }

        return new self(
            version: is_numeric($json['protocol_version'] ?? null) ? (int) $json['protocol_version'] : null,
            type: is_string($json['type'] ?? null) ? $json['type'] : null,
            registry: is_array($json['vehicle_registry'] ?? null) ? $json['vehicle_registry'] : null,
            totalVehicles: count($vehicles),
            dispatches: $dispatches,
            dispatchData: $dispatchData,
            statuses: self::list(($json['type'] ?? null) === 'status_updates' ? $json['statuses'] ?? null : self::map($json['status_updates'] ?? null)['statuses'] ?? null),
            fallbacks: self::list(array_key_exists('entries', $fallbacks) ? $fallbacks['entries'] : $fallbacks),
            sitreps: $sitreps,
            raw: array_filter($json, 'is_string', ARRAY_FILTER_USE_KEY),
        );
    }

    public function isV2(): bool
    {
        return $this->version === 2;
    }

    public function registryOnly(): bool
    {
        return $this->registry !== null && ! isset($this->raw['type']) && ! isset($this->raw['dispatch_data']) && ! isset($this->raw['data']);
    }

    public static function number(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if (is_float($value) && $value > 0 && $value < 1e18 && floor($value) === $value) {
            $value = (int) $value;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $value = ltrim((string) $value, '0');

            return $value === '' || strlen($value) > 18 ? null : $value;
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> only the entries that are objects */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}

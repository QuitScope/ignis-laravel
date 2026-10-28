<?php

namespace App\Models;

use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A vehicle. `is_active` is the admin switch only; `is_operable` is derived (active and no open blocking defect).
 * Nothing here flips `is_active` on its own.
 *
 * @property int $id
 * @property int $sort_order
 * @property string $identifier not unique
 * @property string $name not unique
 * @property string $type free text, key of the loadout lists
 * @property int $service SERVICE_*
 * @property ?string $license_plate
 * @property bool $is_active
 * @property ?list<string> $allowed_jobs null = all jobs
 * @property ?int $station_poi_id
 * @property ?array<string, mixed> $tactical_symbol
 * @property ?string $tactical_symbol_name
 * @property ?string $fms_status
 * @property ?Carbon $fms_status_at
 * @property ?string $fms_status_source manual, no_dispatch, dispatch, incident
 * @property-read bool $is_operable
 * @property-read int $blocking_defects_count
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 * @property-read ?Poi $station
 */
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    public const SERVICE_OTHER = 0;

    public const SERVICE_EMS_PHYSICIAN = 1;

    public const SERVICE_EMS = 2;

    public const SERVICE_FIRE = 3;

    public const FMS_LABELS = [
        0 => 'Dringender Sprechwunsch',
        1 => 'Einsatzbereit Funk',
        2 => 'Einsatzbereit Wache',
        3 => 'Einsatz übernommen',
        4 => 'Am Einsatzort',
        5 => 'Sprechwunsch',
        6 => 'Nicht einsatzbereit',
    ];

    protected $fillable = [
        'sort_order', 'identifier', 'name', 'type', 'service', 'license_plate', 'is_active', 'allowed_jobs',
        'station_poi_id', 'tactical_symbol', 'tactical_symbol_name', 'fms_status', 'fms_status_at', 'fms_status_source',
    ];

    protected function casts(): array
    {
        return [
            'service' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'allowed_jobs' => 'array',
            'tactical_symbol' => 'array',
            'fms_status_at' => 'datetime',
        ];
    }

    public static function fmsLabel(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return self::FMS_LABELS[$status] ?? 'Status '.$status;
    }

    /** @return BelongsTo<Poi, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Poi::class, 'station_poi_id');
    }

    /** @return HasMany<VehicleDefect, $this> */
    public function defects(): HasMany
    {
        return $this->hasMany(VehicleDefect::class);
    }

    /**
     * The loadout categories of this vehicle's type with their items (two queries).
     *
     * @return Builder<LoadoutCategory>
     */
    public function loadoutCategories(): Builder
    {
        return LoadoutCategory::query()->forType($this->type)->ordered()->with('items');
    }

    /** @return HasMany<VehicleDefect, $this> */
    public function blockingDefects(): HasMany
    {
        return $this->defects()->blocking();
    }

    /** @param Builder<Vehicle> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Active and without an open blocking defect.
     *
     * @param  Builder<Vehicle>  $query
     */
    public function scopeOperable(Builder $query): void
    {
        $query->where('is_active', true)->whereDoesntHave('blockingDefects');
    }

    /**
     * Adds `blocking_defects_count`, which the accessors below reuse instead of querying.
     *
     * @param  Builder<Vehicle>  $query
     */
    public function scopeWithBlockingDefectsCount(Builder $query): void
    {
        $query->withCount('blockingDefects');
    }

    /** @return Attribute<int, never> */
    protected function blockingDefectsCount(): Attribute
    {
        return Attribute::get(fn ($value, array $attributes) => (int) ($attributes['blocking_defects_count'] ?? $this->blockingDefects()->count()));
    }

    public function operabilityState(): string
    {
        return match (true) {
            $this->blocking_defects_count > 0 => 'blocked',
            ! $this->is_active => 'inactive',
            default => 'operable',
        };
    }

    /** @return Attribute<bool, never> */
    protected function isOperable(): Attribute
    {
        return Attribute::get(fn () => $this->is_active && $this->blocking_defects_count === 0);
    }
}

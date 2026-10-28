<?php

namespace App\Models;

use Database\Factories\PoiFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A point of interest; for now only what vehicles need (the station they are based at).
 *
 * @property int $id
 * @property string $name
 * @property ?string $street
 * @property ?string $house_number
 * @property string $city
 * @property ?string $district
 * @property ?string $type
 * @property ?string $legacy_identifier
 * @property bool $is_active
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class Poi extends Model
{
    /** @use HasFactory<PoiFactory> */
    use HasFactory;

    public const STATION_TYPES = ['Rettungswache', 'Feuerwache'];

    public const HOSPITAL_TYPES = ['Krankenhaus', 'Klinik'];

    protected $fillable = ['name', 'street', 'house_number', 'city', 'district', 'type', 'legacy_identifier', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'station_poi_id');
    }

    /**
     * Active stations a vehicle can be based at.
     *
     * @param  Builder<Poi>  $query
     */
    public function scopeStations(Builder $query): void
    {
        $query->where('is_active', true)->whereIn('type', self::STATION_TYPES);
    }

    /**
     * Active hospitals (both types).
     *
     * @param  Builder<Poi>  $query
     */
    public function scopeHospitals(Builder $query): void
    {
        $query->where('is_active', true)->whereIn('type', self::HOSPITAL_TYPES);
    }
}

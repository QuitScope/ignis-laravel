<?php

namespace App\Models;

use Database\Factories\LoadoutCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of the loadout list of one vehicle type (kit, interior or exterior compartment).
 *
 * @property int $id
 * @property string $title
 * @property int $compartment KIT, INTERIOR or EXTERIOR
 * @property int $sort_order
 * @property ?string $vehicle_type matches vehicles.type by text
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class LoadoutCategory extends Model
{
    /** @use HasFactory<LoadoutCategoryFactory> */
    use HasFactory;

    public const KIT = 0;

    public const INTERIOR = 1;

    public const EXTERIOR = 2;

    protected $fillable = ['title', 'compartment', 'sort_order', 'vehicle_type'];

    protected function casts(): array
    {
        return ['compartment' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return HasMany<LoadoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(LoadoutItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Categories of one vehicle type: exact text match, a null type applies to all.
     *
     * @param  Builder<LoadoutCategory>  $query
     */
    public function scopeForType(Builder $query, string $type): void
    {
        $query->where(fn (Builder $q) => $q->where('vehicle_type', $type)->orWhereNull('vehicle_type'));
    }

    /**
     * List order of the settings page and the vehicle tab.
     *
     * @param  Builder<LoadoutCategory>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('title')->orderBy('id');
    }
}

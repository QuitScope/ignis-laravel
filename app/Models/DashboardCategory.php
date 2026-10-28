<?php

namespace App\Models;

use Database\Factories\DashboardCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of quick links on /dashboard.
 *
 * @property int $id
 * @property string $title
 * @property int $sort_order
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class DashboardCategory extends Model
{
    /** @use HasFactory<DashboardCategoryFactory> */
    use HasFactory;

    protected $fillable = ['title', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return HasMany<DashboardLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(DashboardLink::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @param  Builder<DashboardCategory>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}

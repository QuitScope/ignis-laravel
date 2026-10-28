<?php

namespace App\Models;

use Database\Factories\LoadoutItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One item of a loadout category with its target quantity.
 *
 * @property int $id
 * @property int $loadout_category_id
 * @property string $title
 * @property int $quantity
 * @property int $sort_order
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class LoadoutItem extends Model
{
    /** @use HasFactory<LoadoutItemFactory> */
    use HasFactory;

    protected $fillable = ['loadout_category_id', 'title', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<LoadoutCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LoadoutCategory::class, 'loadout_category_id');
    }
}

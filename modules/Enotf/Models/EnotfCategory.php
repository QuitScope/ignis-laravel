<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quicklink category of the overview page.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 * @property bool $active
 */
class EnotfCategory extends Model
{
    protected $table = 'enotf_categories';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<EnotfQuicklink, $this> */
    public function quicklinks(): HasMany
    {
        return $this->hasMany(EnotfQuicklink::class, 'enotf_category_id');
    }
}

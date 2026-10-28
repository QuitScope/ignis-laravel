<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quicklink tile of the overview page.
 *
 * @property int $id
 * @property int $enotf_category_id
 * @property string $title
 * @property string $url
 * @property ?string $icon
 * @property int $sort_order
 * @property string $col_width
 * @property bool $active
 */
class EnotfQuicklink extends Model
{
    protected $table = 'enotf_quicklinks';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<EnotfCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EnotfCategory::class, 'enotf_category_id');
    }
}

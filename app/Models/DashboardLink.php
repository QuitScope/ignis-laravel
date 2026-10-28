<?php

namespace App\Models;

use Database\Factories\DashboardLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One quick link. `url` passes App\Dashboard\LinkTarget (the settings requests and the importer write it).
 *
 * @property int $id
 * @property int $dashboard_category_id
 * @property string $title
 * @property string $url
 * @property ?string $icon Font Awesome classes, mapped to Lucide by resources/js/lib/tileIcons.ts
 * @property int $sort_order
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class DashboardLink extends Model
{
    /** @use HasFactory<DashboardLinkFactory> */
    use HasFactory;

    protected $fillable = ['dashboard_category_id', 'title', 'url', 'icon', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<DashboardCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DashboardCategory::class, 'dashboard_category_id');
    }
}

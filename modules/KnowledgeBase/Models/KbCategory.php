<?php

namespace Modules\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tree node over `parent_id`. The legacy `slug` column was never read and is not carried over.
 *
 * @property int $id
 * @property ?int $parent_id
 * @property string $name
 * @property ?string $icon
 * @property int $sort_order
 */
class KbCategory extends Model
{
    protected $table = 'kb_categories';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public static function wouldCycle(int $id, ?int $parentId): bool
    {
        $seen = [];
        for ($p = $parentId; $p !== null; $p = self::whereKey($p)->value('parent_id')) {
            $p = (int) $p;
            if ($p === $id) {
                return true;
            }
            if (isset($seen[$p])) {
                return false;
            }
            $seen[$p] = true;
        }

        return false;
    }

    /** @return BelongsTo<KbCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<KbCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<KbEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(KbEntry::class, 'category_id');
    }
}

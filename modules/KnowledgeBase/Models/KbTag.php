<?php

namespace Modules\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name unique, compared case-insensitively by the controller
 * @property string $color #rrggbb
 */
class KbTag extends Model
{
    public const DEFAULT_COLOR = '#6c757d';

    protected $table = 'kb_tags';

    protected $guarded = ['*'];

    /** @return BelongsToMany<KbEntry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(KbEntry::class, 'kb_entry_tag', 'tag_id', 'entry_id');
    }
}

<?php

namespace Modules\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Link between two entries, stored once with entry_id < related_entry_id. Every writer (entry form, importer) passes
 * the pair through normalize(); MariaDB also enforces the order with a CHECK, SQLite does not.
 *
 * @property int $entry_id
 * @property int $related_entry_id
 */
class KbEntryRelation extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $table = 'kb_entry_relations';

    protected $guarded = ['*'];

    /** @return array{0: int, 1: int} [smaller id, larger id] */
    public static function normalize(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }
}

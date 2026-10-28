<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $personnel_id
 * @property int $type
 * @property string $content
 * @property string $author
 * @property ?array<string, mixed> $metadata
 * @property ?Carbon $created_at
 */
class PersonnelLogEntry extends Model
{
    public const NOTE = 0;

    public const POSITIVE = 1;

    public const NEGATIVE = 2;

    public const RANK_CHANGE = 4;

    public const MODIFICATION = 5;

    public const CREATED = 6;

    public const DOCUMENT = 7;

    public const NOTE_TYPES = [self::NOTE, self::POSITIVE, self::NEGATIVE];

    public const UPDATED_AT = null;

    protected $fillable = ['personnel_id', 'type', 'content', 'author', 'metadata'];

    protected function casts(): array
    {
        return ['type' => 'integer', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}

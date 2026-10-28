<?php

namespace App\Models;

use App\Models\Concerns\HasGenderedName;
use Database\Factories\RankFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $priority
 * @property string $name
 * @property string $name_male
 * @property string $name_female
 * @property ?string $badge
 * @property bool $is_archive
 */
class Rank extends Model
{
    /** @use HasFactory<RankFactory> */
    use HasFactory, HasGenderedName;

    protected $fillable = ['priority', 'name', 'name_male', 'name_female', 'badge', 'is_archive'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_archive' => 'boolean'];
    }

    public function isArchive(): bool
    {
        return $this->is_archive;
    }

    /** @return HasMany<Personnel, $this> */
    public function personnel(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }
}

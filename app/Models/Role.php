<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $priority
 * @property string $color
 * @property bool $is_default
 */
class Role extends SpatieRole
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_default' => 'boolean'];
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first();
    }

    public function save(array $options = []): bool
    {
        if (! $this->is_default) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            $saved = parent::save($options);
            static::where('is_default', true)->whereKeyNot($this->getKey())->update(['is_default' => false]);

            return $saved;
        });
    }
}

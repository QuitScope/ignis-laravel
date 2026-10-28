<?php

namespace App\Models;

use App\Models\Concerns\HasGenderedName;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $priority
 * @property string $name
 * @property string $name_male
 * @property string $name_female
 * @property ?string $abbreviation
 * @property bool $is_none
 * @property bool $is_trainable
 */
class EmsQualification extends Model
{
    use HasGenderedName;

    protected $fillable = ['priority', 'name', 'name_male', 'name_female', 'abbreviation', 'is_none', 'is_trainable'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_none' => 'boolean', 'is_trainable' => 'boolean'];
    }
}

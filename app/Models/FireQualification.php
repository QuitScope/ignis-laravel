<?php

namespace App\Models;

use App\Models\Concerns\HasGenderedName;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $priority
 * @property string $short_name
 * @property string $name
 * @property string $name_male
 * @property string $name_female
 * @property bool $is_none
 */
class FireQualification extends Model
{
    use HasGenderedName;

    protected $fillable = ['priority', 'short_name', 'name', 'name_male', 'name_female', 'is_none'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_none' => 'boolean'];
    }
}

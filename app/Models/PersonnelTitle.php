<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $priority
 */
class PersonnelTitle extends Model
{
    protected $fillable = ['name', 'priority'];

    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }
}

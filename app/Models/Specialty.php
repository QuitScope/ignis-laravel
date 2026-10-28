<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $number
 * @property string $name
 * @property bool $is_disabled
 */
class Specialty extends Model
{
    protected $fillable = ['number', 'name', 'is_disabled'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'is_disabled' => 'boolean'];
    }
}

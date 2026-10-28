<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

class SqlLike
{
    public const CLAUSE = "like ? escape '!'";

    /** Escapes LIKE wildcards for {@see self::CLAUSE}; returns the whole pattern `%value%`. */
    public static function contains(string $value): string
    {
        return '%'.strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }

    /**
     * `(a like %value% or b like %value% ...)` on $query, wildcards in $value taken literally.
     *
     * @param  Builder|EloquentBuilder<*>  $query
     * @param  list<string>  $columns  fixed column names, never input
     */
    public static function anyOf(Builder|EloquentBuilder $query, array $columns, string $value): void
    {
        $like = self::contains($value);
        $query->where(function ($q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhereRaw($column.' '.self::CLAUSE, [$like]);
            }
        });
    }
}

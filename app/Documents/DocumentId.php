<?php

namespace App\Documents;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The public 7-digit id of an editor document (`0483920`), random so it does not reveal how many
 * documents exist. The unique index is the only reliable guard: roll, insert, and on a collision
 * roll again (at most 3 attempts) instead of checking first.
 */
final class DocumentId
{
    private const MAX_ATTEMPTS = 3;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }

    /**
     * Runs $create with a fresh id until the insert does not hit the unique index.
     *
     * @template T
     *
     * @param  Closure(string): T  $create
     * @return T
     *
     * @throws UniqueConstraintViolationException after the last attempt
     */
    public static function create(Closure $create): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $create(self::generate()));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}

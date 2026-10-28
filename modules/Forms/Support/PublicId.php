<?php

namespace Modules\Forms\Support;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Forms\Models\FormSubmission;

/**
 * The 6-digit public number of a submission (legacy uniqueid, random_int). The unique index decides whether a number is
 * free: a taken one (also one taken by a concurrent request) is retried with a new number, at most ATTEMPTS times.
 */
class PublicId
{
    public const ATTEMPTS = 10;

    /** @param ?Closure(): string $random tests pass a fixed sequence */
    public function __construct(private ?Closure $random = null) {}

    public function save(FormSubmission $submission): void
    {
        for ($attempt = 1; ; $attempt++) {
            $submission->public_id = $this->random === null ? (string) random_int(100000, 999999) : ($this->random)();
            try {
                $submission->save();

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}

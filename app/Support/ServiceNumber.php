<?php

namespace App\Support;

use App\Models\Personnel;

class ServiceNumber
{
    public const PATTERN = '/^(?=.*[0-9])[A-Za-z0-9\-]+$/D';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function isAvailable(string $value, ?int $exceptId = null): bool
    {
        return ! Personnel::where('service_number', $value)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }
}

<?php

namespace App\Search;

use Normalizer;

/** Spelling-insensitive form for the typo correction (legacy emergencyforge/fuzzy-search Normalizer). */
final class SearchTextNormalizer
{
    public static function fold(string $s): string
    {
        $s = mb_strtolower(Normalizer::normalize($s, Normalizer::FORM_C) ?: $s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D) ?: $s);
    }

    /** @return list<string> the words of $s (letters and digits), unfolded */
    public static function tokens(string $s): array
    {
        return preg_split('/[^\p{L}\p{N}\p{M}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

<?php

namespace App\Search;

use Illuminate\Support\Facades\Cache;

/**
 * The words of a source's vocabulary, folded => original spellings, in the Laravel cache for ten minutes (legacy
 * storage/cache/search-<key>.php). Over 30 places write the tables directly, so a TTL is all the invalidation
 * there is, as before. The entry holds words only, the same for every account the source is allowed for.
 */
class VocabularyCache
{
    public const TTL = 600;

    public const MAX_WORDS = 5000;

    /** @return array<string, list<string>> folded word => up to two original spellings */
    public function words(SearchSource $source): array
    {
        return Cache::remember('search.vocabulary.'.$source->key(), self::TTL, function () use ($source) {
            $words = [];
            foreach ($source->vocabulary() ?? [] as $phrase) {
                foreach (SearchTextNormalizer::tokens($phrase) as $word) {
                    if (preg_match('/\p{L}/u', $word) !== 1) {
                        continue;
                    }
                    $folded = SearchTextNormalizer::fold($word);
                    if (! isset($words[$folded])) {
                        if (count($words) >= self::MAX_WORDS) {
                            return $words;
                        }
                        $words[$folded] = [$word];
                    } elseif (count($words[$folded]) < 2 && ! in_array($word, $words[$folded], true)) {
                        $words[$folded][] = $word;
                    }
                }
            }

            return $words;
        });
    }
}

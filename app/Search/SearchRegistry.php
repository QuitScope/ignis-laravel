<?php

namespace App\Search;

use App\Models\User;
use App\Modules\BelongsToModule;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sources of the global search (legacy App\Search\SearchRegistry): the core ones in a fixed order
 * (AppServiceProvider), modules append theirs with register(). search() asks every source the viewer is allowed,
 * at most LIMIT hits each, and leaves out empty groups; a source that throws drops out alone and is logged.
 *
 * A source with a vocabulary that returns fewer than LIMIT hits gets a typo correction: each query word is
 * compared with the vocabulary's words (folded, Levenshtein <= 1) and up to three corrected queries run through
 * the same search(), so rights and row filters never change. New hits are appended without duplicates (by href)
 * and flagged `approx`. The correction has its own try/catch, so a broken vocabulary keeps the exact hits. It only runs
 * for viewers the source allows it for (fuzzyAllowed): the shared vocabulary must come from rows each of them sees,
 * else an `approx` hit would tell that a word exists in a row the viewer may not see.
 */
class SearchRegistry
{
    public const LIMIT = 5;

    public const MAX_QUERY = 100;

    private const HITS_PER_TOKEN = 3;

    private const CHOICES_PER_TOKEN = 2;

    private const REPLACED_TOKENS = 2;

    private const MAX_CANDIDATES = 3;

    /** @var list<SearchSource> */
    private array $sources = [];

    public function __construct(private readonly VocabularyCache $vocabulary) {}

    public function register(SearchSource $source): void
    {
        foreach ($this->sources as $known) {
            if ($known->key() === $source->key()) {
                Log::warning('Search: duplicate source key '.$source->key().' ignored', ['class' => $source::class]);

                return;
            }
        }
        $this->sources[] = $source;
    }

    /** @return list<SearchSource> without those of switched-off modules */
    public function sources(): array
    {
        return array_values(array_filter($this->sources, fn (SearchSource $s) => ModuleRegistry::active($s instanceof BelongsToModule ? $s->module() : null)));
    }

    /**
     * @return array{q: string, results: list<array{key: string, label: string, items: list<array<string, mixed>>}>, scopes: list<array{key: string, label: string}>}
     */
    public function search(User $user, string $q, string $scope = 'all'): array
    {
        $q = mb_substr(trim($q), 0, self::MAX_QUERY);
        $allowed = [];
        $scopes = [];
        foreach ($this->sources() as $source) {
            try {
                if ($source->allowed($user)) {
                    $entry = ['key' => $source->key(), 'label' => $source->label()];
                    $scopes[] = $entry;
                    $allowed[] = [$source, $entry];
                }
            } catch (Throwable $e) {
                Log::error('Search: source '.$source::class.' failed', ['exception' => $e::class]);
            }
        }
        if (! in_array($scope, array_column($scopes, 'key'), true)) {
            $scope = 'all';
        }

        $results = [];
        if (mb_strlen($q) >= 2) {
            foreach ($allowed as [$source, $entry]) {
                if ($scope !== 'all' && $scope !== $entry['key']) {
                    continue;
                }
                try {
                    $items = array_slice($source->search($user, $q, self::LIMIT), 0, self::LIMIT);
                } catch (Throwable $e) {
                    Log::error('Search: source '.$entry['key'].' failed', ['exception' => $e::class]);

                    continue;
                }
                if (count($items) < self::LIMIT) {
                    try {
                        if ($source->fuzzyAllowed($user)) {
                            $items = $this->withFuzzyMatches($user, $source, $q, $items);
                        }
                    } catch (Throwable $e) {
                        Log::error('Search: fuzzy step of '.$entry['key'].' failed', ['exception' => $e::class]);
                    }
                }
                if ($items !== []) {
                    $results[] = [...$entry, 'items' => $items];
                }
            }
        }

        return ['q' => $q, 'results' => $results, 'scopes' => $scopes];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withFuzzyMatches(User $user, SearchSource $source, string $q, array $items): array
    {
        $seen = array_fill_keys(array_column($items, 'href'), true);
        foreach ($this->candidateQueries(SearchTextNormalizer::tokens($q), $this->vocabulary->words($source)) as $candidate) {
            foreach ($source->search($user, $candidate, self::LIMIT) as $hit) {
                if (count($items) >= self::LIMIT) {
                    return $items;
                }
                if (! isset($seen[$hit['href']])) {
                    $seen[$hit['href']] = true;
                    $items[] = [...$hit, 'approx' => true];
                }
            }
        }

        return $items;
    }

    /**
     * Each word replaced by up to two spellings of its nearest vocabulary words (exact fold first, then one edit;
     * the edit only for words of four or more characters), at most two words replaced, combined into at most three
     * queries. A query equal to the original one is not run again.
     *
     * @param  list<string>  $tokens
     * @param  array<string, list<string>>  $words
     * @return list<string>
     */
    private function candidateQueries(array $tokens, array $words): array
    {
        $options = [];
        $replaced = 0;
        foreach ($tokens as $token) {
            $choices = $replaced < self::REPLACED_TOKENS ? $this->spellings($token, $words) : [];
            $replaced += $choices === [] ? 0 : 1;
            $options[] = $choices === [] ? [$token] : $choices;
        }

        $queries = [''];
        foreach ($options as $choices) {
            $next = [];
            foreach ($queries as $prefix) {
                foreach ($choices as $choice) {
                    $next[] = ltrim($prefix.' '.$choice);
                }
            }
            $queries = array_slice($next, 0, self::MAX_CANDIDATES);
        }

        return $replaced === 0 ? [] : $queries;
    }

    /**
     * @param  array<string, list<string>>  $words
     * @return list<string> other spellings of $token from the vocabulary, best first
     */
    private function spellings(string $token, array $words): array
    {
        $folded = SearchTextNormalizer::fold($token);
        $hits = isset($words[$folded]) ? [$folded] : [];
        if (mb_strlen($folded) >= 4) {
            foreach (array_keys($words) as $word) {
                if (count($hits) >= self::HITS_PER_TOKEN) {
                    break;
                }
                if ($word !== $folded && abs(strlen($word) - strlen($folded)) <= 1 && levenshtein($word, $folded) <= 1) {
                    $hits[] = $word;
                }
            }
        }

        $spellings = [];
        foreach ($hits as $hit) {
            foreach ($words[$hit] as $original) {
                if (mb_strtolower($original) !== mb_strtolower($token)) {
                    $spellings[] = $original;
                }
            }
        }

        return array_slice(array_values(array_unique($spellings)), 0, self::CHOICES_PER_TOKEN);
    }
}

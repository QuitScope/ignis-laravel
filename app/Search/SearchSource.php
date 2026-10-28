<?php

namespace App\Search;

use App\Models\User;

/**
 * One group of the global search (legacy SearchSourceInterface + FuzzySearchSource). search() filters by the
 * viewer's rights and row visibility itself; the registry only calls it when allowed() says yes.
 */
interface SearchSource
{
    public function key(): string;

    public function label(): string;

    public function allowed(User $user): bool;

    /** @return list<array{label: string, sub: string, href: string}> at most $limit, href an internal path */
    public function search(User $user, string $q, int $limit): array;

    /**
     * Phrases for the typo correction, shared by every account the source is allowed for (one cache entry per
     * source): only text from rows each of them may see, never ids or numbers. null = no fuzzy step.
     *
     * @return ?list<string>
     */
    public function vocabulary(): ?array;

    /**
     * Whether $user gets the typo correction: only viewers who see every row the vocabulary is built from, else an
     * `approx` hit would reveal that a word exists in a row they may not see.
     */
    public function fuzzyAllowed(User $user): bool;
}

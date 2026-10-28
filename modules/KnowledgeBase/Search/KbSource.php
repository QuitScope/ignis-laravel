<?php

namespace Modules\KnowledgeBase\Search;

use App\Models\User;
use App\Modules\BelongsToModule;
use App\Search\SearchSource;
use App\Support\SqlLike;
use Illuminate\Support\Facades\DB;
use Modules\KnowledgeBase\Models\KbEntry;

/**
 * Lexicon entries in the global search. Every account may read the knowledge base (no kb.view), so the
 * source is open to all; archived entries never appear, whatever the viewer's rights (same as the default list).
 */
final class KbSource implements BelongsToModule, SearchSource
{
    private const SNIPPET_SIDE = 60;

    public function module(): string
    {
        return 'knowledgebase';
    }

    public function key(): string
    {
        return 'lexicon';
    }

    public function label(): string
    {
        return __('knowledgebase.search.source');
    }

    public function allowed(User $user): bool
    {
        return true;
    }

    public function search(User $user, string $q, int $limit): array
    {
        $query = KbEntry::query()->with('category:id,name')->where('is_archived', false)
            ->select(['id', 'type', 'title', 'subtitle', 'category_id', 'search_text'])
            ->where(function ($w) use ($q) {
                SqlLike::anyOf($w, ['title', 'subtitle', 'search_text'], $q);
                $tagged = DB::table('kb_entry_tag')->join('kb_tags', 'kb_tags.id', '=', 'kb_entry_tag.tag_id')->select('kb_entry_tag.entry_id');
                SqlLike::anyOf($tagged, ['kb_tags.name'], $q);
                $w->orWhereIn('kb_entries.id', $tagged);
            });

        return $query->orderByDesc('is_pinned')->orderBy('title')->orderBy('id')->limit($limit)->get()->map(function (KbEntry $e) use ($q) {
            $item = [
                'label' => $e->title,
                'sub' => implode(' · ', array_filter([__('knowledgebase.type.'.$e->type), $e->category?->name])),
                'href' => "/lexicon/{$e->id}",
            ];
            $snippet = $this->snippet($e->search_text, $q);

            return $snippet === null ? $item : [...$item, 'snippet' => $snippet];
        })->all();
    }

    public function vocabulary(): array
    {
        return KbEntry::query()->where('is_archived', false)->distinct()->limit(5000)->pluck('title')->all();
    }

    public function fuzzyAllowed(User $user): bool
    {
        return true;
    }

    private function snippet(string $text, string $q): ?string
    {
        $at = mb_stripos($text, $q);
        if ($at === false) {
            return null;
        }
        $start = max(0, $at - self::SNIPPET_SIDE);
        $part = mb_substr($text, $start, mb_strlen($q) + 2 * self::SNIPPET_SIDE);

        return ($start > 0 ? '…' : '').trim($part).($start + mb_strlen($part) < mb_strlen($text) ? '…' : '');
    }
}

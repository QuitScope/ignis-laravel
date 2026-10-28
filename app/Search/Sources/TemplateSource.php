<?php

namespace App\Search\Sources;

use App\Models\DocumentTemplate;
use App\Models\User;
use App\Search\SearchSource;
use App\Support\SqlLike;

/** Editor templates by name or category; document.manage (not admin only, unlike legacy). */
final class TemplateSource implements SearchSource
{
    public function key(): string
    {
        return 'templates';
    }

    public function label(): string
    {
        return __('search.source.templates');
    }

    public function allowed(User $user): bool
    {
        return $user->can('document.manage');
    }

    public function search(User $user, string $q, int $limit): array
    {
        $query = DocumentTemplate::query()->select(['id', 'name', 'category', 'is_active']);
        SqlLike::anyOf($query, ['name', 'category'], $q);

        return $query->orderBy('name')->orderBy('id')->limit($limit)->get()->map(fn (DocumentTemplate $t) => [
            'label' => $t->name,
            'sub' => implode(' · ', array_filter([(string) $t->category, $t->is_active ? '' : __('search.template_inactive')], fn ($s) => $s !== '')),
            'href' => "/settings/documents/editor-templates/{$t->id}",
        ])->all();
    }

    /** The name only, never the category. */
    public function vocabulary(): array
    {
        return DocumentTemplate::query()->distinct()->limit(5000)->pluck('name')->all();
    }

    public function fuzzyAllowed(User $user): bool
    {
        return true;
    }
}

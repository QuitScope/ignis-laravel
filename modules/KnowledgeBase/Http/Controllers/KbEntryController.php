<?php

namespace Modules\KnowledgeBase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\KnowledgeBase\Content\KbContentRenderer;
use Modules\KnowledgeBase\Http\Requests\KbEntryRequest;
use Modules\KnowledgeBase\Models\KbCategory;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbEntryRelation;
use Modules\KnowledgeBase\Models\KbTag;
use Modules\KnowledgeBase\Support\KbCompetency;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Entry list, detail and editing. List and detail are also open to guests while `knowledgebase.public_access` is on
 * (never archived entries, never names); everything else needs kb.edit / kb.archive / admin. The only HTML that
 * reaches the pages is KbContentRenderer::html() output built here, never stored JSON.
 */
class KbEntryController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): HttpResponse
    {
        $this->readable($request);
        $user = $request->user();
        $text = fn (string $key) => is_string($request->query($key)) ? trim($request->query($key)) : '';
        $int = fn (string $key) => ctype_digit($text($key)) && strlen($text($key)) < 10 ? (int) $text($key) : 0;
        $canArchive = $user?->can('archive', KbEntry::class) ?? false;
        $filters = [
            'type' => in_array($text('type'), KbEntry::TYPES, true) ? $text('type') : '',
            'category' => $int('category'),
            'tag' => $int('tag'),
            'q' => mb_substr($text('q'), 0, 100),
            'archived' => $canArchive && $text('archived') === '1',
        ];

        $categories = $this->categories();
        $options = $user === null ? $this->usedCategories($categories) : $categories;
        $query = KbEntry::query()
            ->select(['id', 'type', 'category_id', 'title', 'subtitle', 'competency_level', 'is_pinned', 'is_archived', 'created_at', 'updated_at'])
            ->with(['category:id,name', 'tags:id,name,color'])
            ->where('is_archived', $filters['archived'])
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('type', $filters['type']))
            ->when($filters['category'] > 0, fn (Builder $q) => $q->whereIn('category_id', $this->withDescendants($categories, $filters['category'])))
            ->when($filters['tag'] > 0, fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->where('kb_tags.id', $filters['tag'])))
            ->when($filters['q'] !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($filters) {
                SqlLike::anyOf($w, ['kb_entries.search_text', 'kb_entries.title', 'kb_entries.subtitle'], $filters['q']);
                $w->orWhereHas('tags', fn (Builder $t) => SqlLike::anyOf($t, ['kb_tags.name'], $filters['q']));
            }));

        $entries = $query->orderByDesc('is_pinned')->orderByDesc('updated_at')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (KbEntry $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'title' => $e->title,
                'subtitle' => $e->subtitle,
                'competency' => KbCompetency::info($e->competency_level),
                'category' => $e->category?->name,
                'tags' => $e->tags->map(fn (KbTag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all(),
                'is_pinned' => $e->is_pinned,
                'is_archived' => $e->is_archived,
                'updated_at' => $e->updated_at?->toIso8601String(),
            ]);

        return $this->page($request, 'KnowledgeBase/Index', [
            'entries' => $entries,
            'filters' => $filters,
            'types' => KbEntry::TYPES,
            'categories' => $this->categoryOptions($options),
            'tags' => KbTag::query()->when($user === null, fn (Builder $q) => $q->whereHas('entries', fn (Builder $e) => $e->where('is_archived', false)))
                ->orderByRaw('LOWER(name)')->orderBy('id')->get(['id', 'name', 'color'])->all(),
            'can' => ['edit' => $user?->can('edit', KbEntry::class) ?? false, 'archive' => $canArchive],
        ]);
    }

    public function show(Request $request, string $entry): HttpResponse
    {
        $user = $request->user();
        $model = KbEntry::query()->find((int) $entry);
        $this->readable($request, $model);
        abort_if($model === null, 404);

        $names = $user !== null && ! $model->hide_editor;
        $model->load($names ? ['tags:id,name,color', 'creator:id,name,username', 'editor:id,name,username'] : ['tags:id,name,color']);
        $sections = $model->sections ?? [];
        $keys = array_unique(array_merge(KbEntry::SECTIONS_BY_TYPE[$model->type] ?? [], KbEntry::SECTIONS));

        return $this->page($request, 'KnowledgeBase/Show', [
            'entry' => [
                'id' => $model->id,
                'type' => $model->type,
                'title' => $model->title,
                'subtitle' => $model->subtitle,
                'competency' => KbCompetency::info($model->competency_level),
                'active_ingredient' => $model->active_ingredient,
                'drug_class' => $model->drug_class,
                'is_pinned' => $model->is_pinned,
                'is_archived' => $model->is_archived,
                'hide_editor' => $user?->can('hideEditor', KbEntry::class) ? $model->hide_editor : null,
                'created_at' => $model->created_at?->toIso8601String(),
                'updated_at' => $model->updated_at?->toIso8601String(),
                'creator' => $names ? $this->name($model->creator) : null,
                'editor' => $names ? $this->name($model->editor) : null,
            ],
            'contentHtml' => $this->html($model->content),
            'sectionsHtml' => collect($keys)
                ->map(fn (string $k) => ['key' => $k, 'html' => $this->html($sections[$k] ?? null)])
                ->filter(fn (array $s) => $s['html'] !== '')->values()->all(),
            'categoryPath' => $this->categoryPath($this->categories(), $model->category_id),
            'tags' => $model->tags->map(fn (KbTag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all(),
            'related' => $this->related($model->id, false),
            'can' => $user === null ? null : [
                'edit' => $user->can('edit', KbEntry::class),
                'archive' => $user->can('archive', KbEntry::class),
                'hideEditor' => $user->can('hideEditor', KbEntry::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('edit', KbEntry::class);

        return $this->form(null);
    }

    public function edit(string $entry): Response
    {
        $this->authorize('edit', KbEntry::class);

        return $this->form($this->readableEntry($entry));
    }

    public function store(KbEntryRequest $request): RedirectResponse
    {
        $entry = (new KbEntry)->forceFill([
            'type' => $request->validated('type'), 'created_by' => $request->user()?->id,
            'is_pinned' => false, 'is_archived' => false, 'hide_editor' => false,
        ]);
        $this->persist($request, $entry);
        $this->audit($request->user(), 'Wissensdatenbank-Eintrag erstellt', $entry);

        return redirect('/lexicon/'.$entry->id)->with('status', 'knowledgebase.saved');
    }

    public function update(KbEntryRequest $request, string $entry): RedirectResponse
    {
        $model = $request->entry() ?? abort(404);
        $this->persist($request, $model);
        $this->audit($request->user(), 'Wissensdatenbank-Eintrag bearbeitet', $model);

        return redirect('/lexicon/'.$model->id)->with('status', 'knowledgebase.saved');
    }

    public function pin(Request $request, string $entry): RedirectResponse
    {
        $this->authorize('edit', KbEntry::class);

        return $this->set($request, $entry, 'is_pinned', 'Wissensdatenbank-Eintrag angepinnt', 'Wissensdatenbank-Eintrag losgelöst');
    }

    public function archive(Request $request, string $entry): RedirectResponse
    {
        $this->authorize('archive', KbEntry::class);

        return $this->set($request, $entry, 'is_archived', 'Wissensdatenbank-Eintrag archiviert', 'Wissensdatenbank-Eintrag wiederhergestellt');
    }

    public function hideEditor(Request $request, string $entry): RedirectResponse
    {
        $this->authorize('hideEditor', KbEntry::class);

        return $this->set($request, $entry, 'hide_editor', 'Wissensdatenbank-Eintrag Bearbeiter ausgeblendet', 'Wissensdatenbank-Eintrag Bearbeiter eingeblendet');
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('edit', KbEntry::class);
        $q = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $ex = $request->query('exclude');
        $exclude = is_string($ex) && ctype_digit($ex) && strlen($ex) < 10 ? (int) $ex : 0;

        $query = KbEntry::query()->where('is_archived', false)->when($exclude > 0, fn (Builder $b) => $b->whereKeyNot($exclude));
        if ($q !== '') {
            SqlLike::anyOf($query, ['title', 'subtitle'], mb_substr($q, 0, 100));
        }

        return response()->json($query->orderBy('title')->orderBy('id')->limit(10)->get(['id', 'title', 'type'])
            ->map(fn (KbEntry $e) => ['id' => $e->id, 'title' => $e->title, 'type' => $e->type])->all());
    }

    /**
     * Guests get the login redirect while the public switch is off; an archived entry is a 404 for everyone who may
     * not read it (a missing one is decided by the caller after this check, so there is no existence oracle).
     */
    private function readable(Request $request, ?KbEntry $entry = null): void
    {
        if (Gate::allows('read', $entry ?? KbEntry::class)) {
            return;
        }
        if ($request->user() === null && ! Gate::allows('read', KbEntry::class)) {
            throw new AuthenticationException;
        }
        abort(404);
    }

    /** @param array<string, mixed> $props */
    private function page(Request $request, string $component, array $props): HttpResponse
    {
        $guest = $request->user() === null;
        $response = Inertia::render($component, $props + ['noindex' => $guest])->toResponse($request);

        if ($guest) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    private function form(?KbEntry $entry): Response
    {
        return Inertia::render('KnowledgeBase/Form', [
            'entry' => $entry === null ? null : [
                'id' => $entry->id,
                'type' => $entry->type,
                'title' => $entry->title,
                'subtitle' => $entry->subtitle,
                'competency_level' => $entry->competency_level,
                'category_id' => $entry->category_id,
                'active_ingredient' => $entry->active_ingredient,
                'drug_class' => $entry->drug_class,
                'content' => $entry->content,
                'sections' => (object) ($entry->sections ?? []),
                'tags' => $entry->tags()->pluck('kb_tags.id')->all(),
                'related' => $this->related($entry->id, Gate::allows('archive', KbEntry::class)),
            ],
            'types' => KbEntry::TYPES,
            'competencyLevels' => collect(KbEntry::COMPETENCY_LEVELS)->map(fn (string $l) => ['key' => $l] + (KbCompetency::info($l) ?? []))->all(),
            'sectionsByType' => KbEntry::SECTIONS_BY_TYPE,
            'categories' => $this->categoryOptions($this->categories()),
            'tags' => KbTag::query()->orderByRaw('LOWER(name)')->orderBy('id')->get(['id', 'name', 'color'])->all(),
        ]);
    }

    private function persist(KbEntryRequest $request, KbEntry $entry): void
    {
        $values = $request->values();
        $sections = $values['sections'] ?? null;
        unset($values['sections']);
        if ($entry->type !== 'medication') {
            unset($values['active_ingredient'], $values['drug_class']);
        }
        $entry->forceFill($values);
        if ($sections !== null) {
            $merged = array_filter(array_merge($entry->sections ?? [], $sections), fn ($d) => $d !== null);
            $entry->sections = $merged === [] ? null : $merged;
        }
        $entry->forceFill(['search_text' => $entry->buildSearchText(), 'updated_by' => $request->user()?->id]);
        $tags = $request->ids('tags');
        $related = $request->ids('related');

        if ($related !== null && $entry->exists && ! Gate::allows('archive', KbEntry::class)) {
            $archived = array_filter($this->related($entry->id, true), fn (array $r) => $r['is_archived']);
            $related = [...$related, ...array_column($archived, 'id')];
        }

        DB::transaction(function () use ($entry, $tags, $related) {
            $entry->save();
            if ($tags !== null) {
                $entry->tags()->sync($tags);
            }
            if ($related !== null) {
                DB::table('kb_entry_relations')->where('entry_id', $entry->id)->orWhere('related_entry_id', $entry->id)->delete();
                $rows = [];
                foreach (array_unique($related) as $id) {
                    if ($id !== $entry->id) {
                        [$a, $b] = KbEntryRelation::normalize($entry->id, $id);
                        $rows[] = ['entry_id' => $a, 'related_entry_id' => $b, 'created_at' => now()];
                    }
                }
                DB::table('kb_entry_relations')->insert($rows);
            }
        });
    }

    /** An entry the account may read, else 404 (archived entries stay hidden from kb.edit-only accounts). */
    private function readableEntry(string $id): KbEntry
    {
        $entry = KbEntry::query()->find((int) $id);
        abort_if($entry === null || ! Gate::allows('read', $entry), 404);

        return $entry;
    }

    private function set(Request $request, string $id, string $column, string $on, string $off): RedirectResponse
    {
        $value = $request->validate(['value' => ['required', 'boolean']])['value'];
        $entry = $this->readableEntry($id);
        if ($entry->{$column} !== (bool) $value) {
            $entry->timestamps = false;
            $entry->forceFill([$column => (bool) $value])->save();
            $this->audit($request->user(), $value ? $on : $off, $entry);
        }

        return redirect($request->input('return') === 'list' ? '/lexicon' : '/lexicon/'.$entry->id)->with('status', 'knowledgebase.saved');
    }

    private function html(mixed $doc): string
    {
        try {
            return is_array($doc) && $doc !== [] ? KbContentRenderer::html($doc) : '';
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    private function name(?User $user): ?string
    {
        return $user === null ? null : ($user->name ?: $user->username);
    }

    /** @return Collection<int, KbCategory> */
    private function categories(): Collection
    {
        return KbCategory::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get(['id', 'parent_id', 'name']);
    }

    /**
     * For guests: categories of non-archived entries plus their ancestors (names of unused ones stay private).
     *
     * @param  Collection<int, KbCategory>  $all
     * @return Collection<int, KbCategory>
     */
    private function usedCategories(Collection $all): Collection
    {
        $byId = $all->keyBy('id');
        $keep = [];
        foreach (KbEntry::query()->where('is_archived', false)->whereNotNull('category_id')->distinct()->pluck('category_id') as $id) {
            for ($id = (int) $id; $byId->has($id) && ! isset($keep[$id]); $id = (int) $byId[$id]->parent_id) {
                $keep[$id] = true;
            }
        }

        return $all->filter(fn (KbCategory $c) => isset($keep[$c->id]))->values();
    }

    /**
     * The category and everything below it. Visited set: a loop already in the data ends the walk.
     *
     * @param  Collection<int, KbCategory>  $all
     * @return list<int>
     */
    private function withDescendants(Collection $all, int $id): array
    {
        $children = $all->groupBy(fn (KbCategory $c) => (int) $c->parent_id);
        $seen = [$id => true];
        $queue = [$id];
        while ($queue !== []) {
            foreach ($children->get(array_shift($queue), []) as $child) {
                if (! isset($seen[$child->id])) {
                    $seen[$child->id] = true;
                    $queue[] = $child->id;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Tree order with depth, for the select boxes. Rows no root reaches (a loop in the data) are appended at depth 0.
     *
     * @param  Collection<int, KbCategory>  $all
     * @return list<array{id: int, name: string, depth: int}>
     */
    private function categoryOptions(Collection $all): array
    {
        $ids = $all->pluck('id')->flip();
        $children = $all->groupBy(fn (KbCategory $c) => $c->parent_id !== null && $ids->has($c->parent_id) ? $c->parent_id : 0);
        $out = [];
        $seen = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, &$seen, $children) {
            foreach ($children->get($parent, []) as $c) {
                if (isset($seen[$c->id])) {
                    continue;
                }
                $seen[$c->id] = true;
                $out[] = ['id' => $c->id, 'name' => $c->name, 'depth' => $depth];
                $walk($c->id, $depth + 1);
            }
        };
        $walk(0, 0);
        foreach ($all as $c) {
            if (! isset($seen[$c->id])) {
                $seen[$c->id] = true;
                $out[] = ['id' => $c->id, 'name' => $c->name, 'depth' => 0];
                $walk($c->id, 1);
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, KbCategory>  $all
     * @return list<array{id: int, name: string}>
     */
    private function categoryPath(Collection $all, ?int $id): array
    {
        $byId = $all->keyBy('id');
        $path = [];
        for ($seen = []; $id !== null && $byId->has($id) && ! isset($seen[$id]); $id = $byId[$id]->parent_id) {
            $seen[$id] = true;
            array_unshift($path, ['id' => $id, 'name' => $byId[$id]->name]);
        }

        return $path;
    }

    /** @return list<array<string, mixed>> both directions; archived ones only for the form */
    private function related(int $id, bool $withArchived): array
    {
        $ids = KbEntryRelation::query()->where('entry_id', $id)->orWhere('related_entry_id', $id)->get(['entry_id', 'related_entry_id'])
            ->map(fn (KbEntryRelation $r) => $r->entry_id === $id ? $r->related_entry_id : $r->entry_id)->all();

        return KbEntry::query()->whereIn('id', $ids)->when(! $withArchived, fn (Builder $q) => $q->where('is_archived', false))
            ->orderBy('title')->orderBy('id')->get(['id', 'title', 'type', 'is_archived'])
            ->map(fn (KbEntry $e) => ['id' => $e->id, 'title' => $e->title, 'type' => $e->type] + ($withArchived ? ['is_archived' => $e->is_archived] : []))->all();
    }

    private function audit(?User $by, string $action, KbEntry $e): void
    {
        AuditLogger::log($by, $action.' [ID: '.$e->id.']', $e->title, KbTaxonomyController::MODULE, true, ['id' => $e->id]);
    }
}

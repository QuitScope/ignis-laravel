<?php

namespace Modules\KnowledgeBase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\KnowledgeBase\Http\Requests\KbCategoryRequest;
use Modules\KnowledgeBase\Http\Requests\KbTagRequest;
use Modules\KnowledgeBase\Models\KbCategory;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbTag;

/**
 * Categories and tags of the knowledge base (kb.edit). The page is Inertia; the writes answer JSON (the page uses
 * fetch), because a duplicate tag name is a 409 that Inertia's own visits would not handle.
 */
class KbTaxonomyController extends Controller
{
    public const MODULE = 'Wissensdatenbank';

    public function index(Request $request): Response
    {
        $this->authorize('edit', KbEntry::class);

        return Inertia::render('KnowledgeBase/Taxonomy', [
            'defaultColor' => KbTag::DEFAULT_COLOR,
            'categories' => KbCategory::query()->withCount(['children', 'entries'])->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()
                ->map(fn (KbCategory $c) => [
                    'id' => $c->id, 'parent_id' => $c->parent_id, 'name' => $c->name, 'icon' => $c->icon, 'sort_order' => $c->sort_order,
                    'children_count' => $c->children_count, 'entries_count' => $c->entries_count,
                ])->all(),
            'tags' => KbTag::query()->withCount('entries')->orderByRaw('LOWER(name)')->orderBy('id')->get()
                ->map(fn (KbTag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color, 'entries_count' => $t->entries_count])->all(),
        ]);
    }

    public function storeCategory(KbCategoryRequest $request): JsonResponse
    {
        $category = (new KbCategory)->forceFill($request->values() + ['sort_order' => 0]);
        $category->save();
        $this->audit($request->user(), 'Kategorie erstellt', $category->id, $category->name);

        return response()->json(['id' => $category->id], 201);
    }

    public function updateCategory(KbCategoryRequest $request, KbCategory $category): JsonResponse
    {
        $category->forceFill($request->values())->save();
        $this->audit($request->user(), 'Kategorie bearbeitet', $category->id, $category->name);

        return response()->json(['id' => $category->id]);
    }

    public function destroyCategory(Request $request, KbCategory $category): JsonResponse
    {
        $this->authorize('edit', KbEntry::class);
        DB::transaction(function () use ($category) {
            KbCategory::where('parent_id', $category->id)->update(['parent_id' => null]);
            KbEntry::where('category_id', $category->id)->update(['category_id' => null]);
            $category->delete();
        });
        $this->audit($request->user(), 'Kategorie gelöscht', $category->id, $category->name);

        return response()->json(['id' => $category->id]);
    }

    public function storeTag(KbTagRequest $request): JsonResponse
    {
        $tag = (new KbTag)->forceFill($this->tagValues($request));

        return $this->saveTag($tag, $request, 'Tag erstellt', 201);
    }

    public function updateTag(KbTagRequest $request, KbTag $tag): JsonResponse
    {
        $tag->forceFill($this->tagValues($request));

        return $this->saveTag($tag, $request, 'Tag bearbeitet', 200);
    }

    public function destroyTag(Request $request, KbTag $tag): JsonResponse
    {
        $this->authorize('edit', KbEntry::class);
        $tag->delete();
        $this->audit($request->user(), 'Tag gelöscht', $tag->id, $tag->name);

        return response()->json(['id' => $tag->id]);
    }

    /** @return array{name: string, color: string} */
    private function tagValues(KbTagRequest $request): array
    {
        return ['name' => $request->string('name')->toString(), 'color' => strtolower($request->string('color')->toString())];
    }

    private function saveTag(KbTag $tag, Request $request, string $action, int $status): JsonResponse
    {
        $duplicate = KbTag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($tag->name)])->when($tag->exists, fn ($q) => $q->whereKeyNot($tag->id))->exists();
        try {
            if (! $duplicate) {
                $tag->save();
            }
        } catch (UniqueConstraintViolationException) {
            $duplicate = true;
        }
        if ($duplicate) {
            return response()->json(['message' => __('knowledgebase.error.tag_exists')], 409);
        }
        $this->audit($request->user(), $action, $tag->id, $tag->name);

        return response()->json(['id' => $tag->id], $status);
    }

    private function audit(?User $by, string $action, int $id, string $name): void
    {
        AuditLogger::log($by, $action.' [ID: '.$id.']', $name, self::MODULE, true, ['id' => $id]);
    }
}

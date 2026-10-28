<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\EnotfCategory;
use Modules\Enotf\Models\EnotfQuicklink;

/** /settings/enotf/quicklinks (right enotf.settings): tiles of the overview page. Icons are Lucide names from a fixed list. */
class QuicklinkController extends Controller
{
    public const ICONS = ['link', 'book-open', 'file-text', 'clipboard-list', 'ambulance', 'stethoscope', 'syringe', 'pill', 'heart-pulse', 'activity', 'siren', 'phone', 'map', 'hospital', 'calculator', 'info'];

    public const WIDTHS = ['col-3', 'col-4', 'col-6', 'col-12'];

    public function index(): Response
    {
        $this->authorize('enotf.settings');

        return Inertia::render('Enotf/Settings/Quicklinks', [
            'links' => EnotfQuicklink::query()->orderBy('enotf_category_id')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (EnotfQuicklink $l) => ['id' => $l->id, 'category_id' => $l->enotf_category_id, 'title' => $l->title, 'url' => $l->url, 'icon' => $l->icon,
                    'sort_order' => $l->sort_order, 'col_width' => $l->col_width, 'active' => $l->active])->all(),
            'categories' => EnotfCategory::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->map->only(['id', 'name'])->all(),
            'icons' => self::ICONS, 'widths' => self::WIDTHS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $link = (new EnotfQuicklink)->forceFill($this->data($request));
        $link->save();
        AuditLogger::log($request->user(), 'eNOTF-Quicklink erstellt', $link->title, 'eNOTF', false, ['quicklink_id' => $link->id]);

        return back();
    }

    public function update(Request $request, EnotfQuicklink $quicklink): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $quicklink->forceFill($this->data($request))->save();
        AuditLogger::log($request->user(), 'eNOTF-Quicklink bearbeitet', $quicklink->title, 'eNOTF', false, ['quicklink_id' => $quicklink->id]);

        return back();
    }

    public function destroy(Request $request, EnotfQuicklink $quicklink): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $quicklink->delete();
        AuditLogger::log($request->user(), 'eNOTF-Quicklink gelöscht', $quicklink->title, 'eNOTF', false, ['quicklink_id' => $quicklink->id]);

        return back();
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $d = $request->validate([
            'enotf_category_id' => ['required', 'integer', Rule::exists('enotf_categories', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:500', self::url()],
            'icon' => ['nullable', Rule::in(self::ICONS)],
            'sort_order' => ['required', 'integer', 'between:-9999,9999'],
            'col_width' => ['required', Rule::in(self::WIDTHS)],
            'active' => ['required', 'boolean'],
        ]);
        $d['title'] = trim($d['title']);

        return $d;
    }

    /** Own pages (`/path`, not protocol-relative) or https URLs; javascript:, data: and the like never. */
    public static function url(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $ok = is_string($value) && preg_match('~^(/(?![/\\\\])|https://[^\s/])\S*$~i', $value) === 1;
            if (! $ok) {
                $fail(__('enotf.settings.url_invalid'));
            }
        };
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Documents\VariableCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentTemplateRequest;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Templates of the document editor (legacy EditorTemplateController). A template is only sections on top:
 * locked ones the issuer cannot touch, free ones to write in (DocumentValidator template mode). Documents keep
 * pointing at their template, so one with documents is deactivated, not deleted. documents.template_id is
 * nullOnDelete: the database would let the delete through and orphan the documents, so the rule lives here,
 * re-checked under a row lock.
 */
class DocumentTemplateController extends Controller
{
    private const LIST = '/settings/documents/editor-templates';

    public function index(): Response
    {
        $this->authorize('document.manage');

        return Inertia::render('Settings/Documents/Templates', [
            'templates' => DocumentTemplate::query()->withCount('documents')->orderBy('name')->orderBy('id')->get()
                ->map(fn (DocumentTemplate $t) => [
                    'id' => $t->id, 'name' => $t->name, 'category' => $t->category,
                    'is_active' => $t->is_active, 'documents' => $t->documents_count,
                ])->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('document.manage');

        $section = ['type' => 'docSection', 'attrs' => ['mode' => 'free', 'sectionId' => (string) Str::uuid()], 'content' => [['type' => 'paragraph']]];

        return $this->form(null, ['type' => 'doc', 'content' => [$section]], 0);
    }

    public function store(DocumentTemplateRequest $request): RedirectResponse
    {
        $template = DocumentTemplate::create([
            'name' => $request->validated('name'),
            'category' => $request->validated('category'),
            'content' => $request->document(),
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);
        $this->audit($request, 'Dokumentvorlage erstellt', $template);

        return redirect(self::LIST);
    }

    public function edit(DocumentTemplate $template): Response
    {
        $this->authorize('document.manage');

        $drafts = $template->documents()->where('status', Document::DRAFT)->count();

        return $this->form($template, $template->content, $drafts);
    }

    public function update(DocumentTemplateRequest $request, DocumentTemplate $template): RedirectResponse
    {
        $template->update([
            'name' => $request->validated('name'),
            'category' => $request->validated('category'),
            'content' => $request->document(),
        ]);
        $this->audit($request, 'Dokumentvorlage aktualisiert', $template);

        return redirect(self::LIST);
    }

    public function deactivate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        return $this->setActive($request, $template, false, 'Dokumentvorlage deaktiviert');
    }

    public function reactivate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        return $this->setActive($request, $template, true, 'Dokumentvorlage aktiviert');
    }

    public function destroy(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->authorize('document.manage');

        DB::transaction(function () use ($request, $template) {
            $locked = DocumentTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($locked->documents()->exists()) {
                throw ValidationException::withMessages(['delete' => trans('documents.template.error.in_use')]);
            }
            $locked->delete();
            $this->audit($request, 'Dokumentvorlage gelöscht', $locked);
        });

        return redirect(self::LIST);
    }

    private function setActive(Request $request, DocumentTemplate $template, bool $active, string $action): RedirectResponse
    {
        $this->authorize('document.manage');
        $template->update(['is_active' => $active]);
        $this->audit($request, $action, $template);

        return back();
    }

    /** @param array<string, mixed> $content */
    private function form(?DocumentTemplate $template, array $content, int $openDrafts): Response
    {
        return Inertia::render('Settings/Documents/TemplateEdit', [
            'template' => $template ? ['id' => $template->id, 'name' => $template->name, 'category' => $template->category, 'is_active' => $template->is_active] : null,
            'content' => $content,
            'openDrafts' => $openDrafts,
            'variables' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label],
                array_keys(VariableCatalog::catalog()), VariableCatalog::catalog()),
        ]);
    }

    private function audit(Request $request, string $action, DocumentTemplate $template): void
    {
        AuditLogger::log($request->user(), $action, 'Name: '.$template->name.' [ID: '.$template->id.']', 'Dokumente', true, ['template_id' => $template->id]);
    }
}

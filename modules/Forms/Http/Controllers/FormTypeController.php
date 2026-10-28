<?php

namespace Modules\Forms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forms\Http\Requests\FormFieldRequest;
use Modules\Forms\Http\Requests\FormTypeRequest;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormType;

/**
 * Application types and their fields (system.admin; legacy AntragSettingsController). The pages are Inertia, the writes
 * answer JSON (the pages use fetch). Fields are created, deleted and sorted, never edited.
 */
class FormTypeController extends Controller
{
    public const MODULE = 'Anträge';

    public function index(): Response
    {
        $this->authorize('system.admin');

        return Inertia::render('Forms/Settings/Index', [
            'types' => FormType::query()->withCount(['fields', 'submissions'])->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()
                ->map(fn (FormType $t) => [
                    'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'icon' => $t->icon, 'is_active' => $t->is_active,
                    'is_absence' => $t->is_absence, 'fields_count' => $t->fields_count, 'submissions_count' => $t->submissions_count,
                ])->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('system.admin');

        return Inertia::render('Forms/Settings/Edit', ['type' => null, 'fields' => [], 'options' => $this->options()]);
    }

    public function edit(FormType $type): Response
    {
        $this->authorize('system.admin');

        return Inertia::render('Forms/Settings/Edit', [
            'type' => ['id' => $type->id, 'name' => $type->name, 'description' => $type->description, 'icon' => $type->icon,
                'is_active' => $type->is_active, 'is_absence' => $type->is_absence, 'sort_order' => $type->sort_order],
            'fields' => $type->fields->map(fn (FormField $f) => [
                'id' => $f->id, 'key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'options' => $f->options, 'required' => $f->required,
                'placeholder' => $f->placeholder, 'hint' => $f->hint, 'width' => $f->width, 'default_value' => $f->default_value,
                'readonly' => $f->readonly, 'auto_fill' => $f->auto_fill,
            ])->all(),
            'options' => $this->options(),
        ]);
    }

    public function store(FormTypeRequest $request): JsonResponse
    {
        $type = (new FormType)->forceFill($request->values() + ['sort_order' => (int) FormType::max('sort_order') + 1, 'created_by' => $request->user()?->id]);
        $type->save();
        $this->audit($request->user(), 'Antragstyp erstellt', $type);

        return response()->json(['id' => $type->id], 201);
    }

    public function update(FormTypeRequest $request, FormType $type): JsonResponse
    {
        $type->forceFill($request->values())->save();
        $this->audit($request->user(), 'Antragstyp bearbeitet', $type);

        return response()->json(['id' => $type->id]);
    }

    public function active(Request $request, FormType $type): JsonResponse
    {
        $this->authorize('system.admin');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $type->forceFill(['is_active' => (bool) $data['is_active']])->save();
        $this->audit($request->user(), $type->is_active ? 'Antragstyp aktiviert' : 'Antragstyp deaktiviert', $type);

        return response()->json(['id' => $type->id, 'is_active' => $type->is_active]);
    }

    public function destroy(Request $request, FormType $type): JsonResponse
    {
        $this->authorize('system.admin');
        if ($type->submissions()->exists()) {
            return response()->json(['message' => __('forms.error.has_submissions')], 409);
        }
        $type->delete();
        $this->audit($request->user(), 'Antragstyp gelöscht', $type);

        return response()->json(['id' => $type->id]);
    }

    public function order(Request $request): JsonResponse
    {
        $this->authorize('system.admin');
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer', 'distinct', 'exists:form_types,id']])['ids'];
        $this->sort('form_types', $ids);
        AuditLogger::log($request->user(), 'Antragstyp Reihenfolge geändert', null, self::MODULE, true, ['count' => count($ids)]);

        return response()->json(['ok' => true]);
    }

    public function storeField(FormFieldRequest $request, FormType $type): JsonResponse
    {
        $field = (new FormField)->forceFill($request->values() + ['form_type_id' => $type->id, 'sort_order' => (int) $type->fields()->max('sort_order') + 1]);
        try {
            $field->save();
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => __('forms.error.key_exists')], 409);
        }
        $this->audit($request->user(), 'Antragstyp Feld hinzugefügt', $type, $field->key);

        return response()->json(['id' => $field->id], 201);
    }

    public function destroyField(Request $request, FormType $type, FormField $field): JsonResponse
    {
        $this->authorize('system.admin');
        abort_unless($field->form_type_id === $type->id, 404);
        if ($type->is_absence && in_array($field->key, ['von_datum', 'bis_datum'], true) && ! $type->hasAbsenceFields($field->id)) {
            return response()->json(['message' => __('forms.error.absence_fields_delete'), 'errors' => ['field' => [__('forms.error.absence_fields_delete')]]], 422);
        }
        $field->delete();
        $this->audit($request->user(), 'Antragstyp Feld gelöscht', $type, $field->key);

        return response()->json(['id' => $field->id]);
    }

    public function orderFields(Request $request, FormType $type): JsonResponse
    {
        $this->authorize('system.admin');
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', Rule::exists('form_fields', 'id')->where('form_type_id', $type->id)],
        ])['ids'];
        $this->sort('form_fields', $ids);
        $this->audit($request->user(), 'Antragstyp Felder Reihenfolge geändert', $type);

        return response()->json(['ok' => true]);
    }

    /**
     * Sort order = position in the list; the callers validate the ids (existing, of this type).
     *
     * @param  array<int, mixed>  $ids
     */
    private function sort(string $table, array $ids): void
    {
        DB::transaction(function () use ($table, $ids) {
            foreach (array_values($ids) as $i => $id) {
                DB::table($table)->where('id', (int) $id)->update(['sort_order' => $i + 1]);
            }
        });
    }

    /** @return array<string, list<string>> */
    private function options(): array
    {
        return ['types' => FormField::TYPES, 'widths' => FormField::WIDTHS, 'auto_fill' => FormField::AUTO_FILL];
    }

    private function audit(?User $by, string $action, FormType $type, ?string $detail = null): void
    {
        AuditLogger::log($by, $action.' [ID: '.$type->id.']', $detail === null ? $type->name : $type->name.' / '.$detail, self::MODULE, true, ['id' => $type->id]);
    }
}

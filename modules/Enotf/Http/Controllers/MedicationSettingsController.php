<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\Medication;
use Modules\Enotf\Support\MedicationSeeds;

/**
 * /settings/medications (right enotf.settings; legacy MedikamenteController): the medication catalog.
 * Deleting keeps given rows (name snapshot, medication_id set null).
 */
class MedicationSettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize('enotf.settings');

        return Inertia::render('Enotf/Settings/Medications', [
            'medications' => Medication::query()->orderBy('sort')->orderBy('substance')->get()
                ->map(fn (Medication $m) => ['id' => $m->id, 'substance' => $m->substance, 'brand' => $m->brand, 'doses' => $m->doses ?? [], 'sort' => $m->sort, 'is_active' => $m->is_active])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $medication = (new Medication)->forceFill($this->data($request));
        $medication->save();
        AuditLogger::log($request->user(), 'Medikament erstellt', $medication->substance, 'eNOTF', false, ['medication_id' => $medication->id]);

        return back();
    }

    public function update(Request $request, Medication $medication): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $medication->forceFill($this->data($request, $medication))->save();
        AuditLogger::log($request->user(), 'Medikament bearbeitet', $medication->substance, 'eNOTF', false, ['medication_id' => $medication->id]);

        return back();
    }

    public function destroy(Request $request, Medication $medication): RedirectResponse
    {
        $this->authorize('enotf.settings');
        $medication->delete();
        AuditLogger::log($request->user(), 'Medikament gelöscht', $medication->substance, 'eNOTF', false, ['medication_id' => $medication->id]);

        return back();
    }

    /** @return array{substance: string, brand: ?string, doses: list<string>, sort: int, is_active: bool} */
    private function data(Request $request, ?Medication $current = null): array
    {
        $d = $request->validate([
            'substance' => ['required', 'string', 'max:255', Rule::unique('enotf_medications', 'substance')->ignore($current?->id)],
            'brand' => ['nullable', 'string', 'max:255'],
            'doses' => ['nullable', 'string', 'max:1000'],
            'sort' => ['required', 'integer', 'between:-9999,9999'],
            'is_active' => ['required', 'boolean'],
        ]);
        $brand = trim((string) ($d['brand'] ?? ''));

        return [
            'substance' => trim($d['substance']), 'brand' => $brand === '' ? null : $brand,
            'doses' => MedicationSeeds::doses($d['doses'] ?? null), 'sort' => (int) $d['sort'], 'is_active' => (bool) $d['is_active'],
        ];
    }
}

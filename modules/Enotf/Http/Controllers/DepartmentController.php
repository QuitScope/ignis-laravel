<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Poi;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\HospitalAccessCode;
use Modules\Enotf\Models\HospitalDepartment;
use Modules\Enotf\Support\LocalDateTimeFormatter;

/**
 * Departments of a hospital and their availability: /settings/pois/{poi}/departments with poi.manage,
 * the read-only hospital view of the crews GET /enotf/hospitals. Audit "eNOTF" without patient data.
 */
class DepartmentController extends Controller
{
    public function index(Poi $poi): Response
    {
        $this->authorize('poi.manage');
        $this->hospital($poi);
        $code = HospitalAccessCode::query()->where('poi_id', $poi->id)->first();

        return Inertia::render('Enotf/Settings/Departments', [
            'hospital' => ['id' => $poi->id, 'name' => $poi->name],
            'departments' => HospitalPortalController::departments($poi->id),
            'statuses' => HospitalDepartment::STATUSES,
            'access_code' => $code === null ? null : ['rotated_at' => LocalDateTimeFormatter::at($code->rotated_at), 'last_used_at' => LocalDateTimeFormatter::at($code->last_used_at)],
        ]);
    }

    public function store(Request $request, Poi $poi): RedirectResponse
    {
        $this->authorize('poi.manage');
        $this->hospital($poi);
        $data = $this->data($request, $poi);
        $d = (new HospitalDepartment)->forceFill(['poi_id' => $poi->id, 'name' => $data['name'], 'sort_order' => $data['sort_order'] ?? 999]);
        $d->save();
        AuditLogger::log($request->user(), 'Klinik-Abteilung angelegt', $poi->name.': '.$d->name, 'eNOTF', false, ['poi_id' => $poi->id]);

        return back();
    }

    public function update(Request $request, Poi $poi, HospitalDepartment $department): RedirectResponse
    {
        $this->authorize('poi.manage');
        $this->owned($poi, $department);
        $data = $this->data($request, $poi, $department);
        $department->forceFill(['name' => $data['name'], 'sort_order' => $data['sort_order'] ?? 999])->save();
        $status = $data['status'] ?? $department->status;
        $note = HospitalPortalController::note($data['note'] ?? null);
        if ($status !== $department->status || $note !== $department->note) {
            $department->setStatus($status, $note, $request->user()->displayName());
        }
        AuditLogger::log($request->user(), 'Klinik-Abteilung bearbeitet', $poi->name.': '.$department->name, 'eNOTF', false, ['poi_id' => $poi->id]);

        return back();
    }

    public function destroy(Request $request, Poi $poi, HospitalDepartment $department): RedirectResponse
    {
        $this->authorize('poi.manage');
        $this->owned($poi, $department);
        $department->delete();
        AuditLogger::log($request->user(), 'Klinik-Abteilung gelöscht', $poi->name.': '.$department->name, 'eNOTF', false, ['poi_id' => $poi->id]);

        return back();
    }

    /** GET /enotf/hospitals: every active hospital with its departments, read only (crew session). */
    public function hospitals(): Response
    {
        $departments = HospitalDepartment::query()->orderBy('sort_order')->orderBy('name')->get()->groupBy('poi_id');

        return Inertia::render('Enotf/Hospitals', [
            'hospitals' => Poi::query()->hospitals()->orderBy('name')->get()->map(fn (Poi $p) => [
                'id' => $p->id, 'name' => $p->name, 'city' => $p->city,
                'departments' => ($departments[$p->id] ?? collect())->map(fn (HospitalDepartment $d) => $d->toView())->values()->all(),
            ])->all(),
        ]);
    }

    /** @return array{name: string, sort_order?: ?int, status?: string, note?: ?string} new departments start `not_staffed` */
    private function data(Request $request, Poi $poi, ?HospitalDepartment $department = null): array
    {
        /** @var array{name: string, sort_order?: ?int, status?: string, note?: ?string} */
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('enotf_hospital_departments', 'name')->where('poi_id', $poi->id)->ignore($department?->id)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => [$department === null ? 'prohibited' : 'required', Rule::in(HospitalDepartment::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function hospital(Poi $poi): void
    {
        abort_unless(in_array($poi->type, Poi::HOSPITAL_TYPES, true), 404);
    }

    private function owned(Poi $poi, HospitalDepartment $department): void
    {
        abort_unless($department->poi_id === $poi->id, 404);
    }
}

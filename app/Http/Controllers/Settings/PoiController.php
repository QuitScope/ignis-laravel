<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Poi;
use App\Modules\ModuleRegistry;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** /settings/pois (legacy abilities poi.view / poi.manage): points of interest with type and legacy identifier (hospitals, eNOTF targets). */
class PoiController extends Controller
{
    public function index(): Response
    {
        $this->authorize('poi.view');

        return Inertia::render('Settings/Pois', [
            'pois' => Poi::query()->orderBy('name')->orderBy('id')->get()->map(fn (Poi $p) => [
                'id' => $p->id, 'name' => $p->name, 'street' => $p->street, 'house_number' => $p->house_number, 'city' => $p->city,
                'district' => $p->district, 'type' => $p->type, 'legacy_identifier' => $p->legacy_identifier, 'is_active' => $p->is_active,
            ])->all(),
            'types' => array_values(array_unique([...Poi::STATION_TYPES, ...Poi::HOSPITAL_TYPES])),
            'can_manage' => Gate::allows('poi.manage'),
            'hospital_types' => Poi::HOSPITAL_TYPES,
            'departments' => ModuleRegistry::active('enotf'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('poi.manage');
        $poi = Poi::create($this->data($request));
        AuditLogger::log($request->user(), 'POI erstellt', $poi->name, 'POI', false, ['poi_id' => $poi->id]);

        return back();
    }

    public function update(Request $request, Poi $poi): RedirectResponse
    {
        $this->authorize('poi.manage');
        $poi->update($this->data($request, $poi));
        AuditLogger::log($request->user(), 'POI bearbeitet', $poi->name, 'POI', false, ['poi_id' => $poi->id]);

        return back();
    }

    public function destroy(Request $request, Poi $poi): RedirectResponse
    {
        $this->authorize('poi.manage');
        $poi->delete();
        AuditLogger::log($request->user(), 'POI gelöscht', $poi->name, 'POI', false, ['poi_id' => $poi->id]);

        return back();
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?Poi $poi = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
            'legacy_identifier' => ['nullable', 'string', 'max:100', Rule::unique('pois', 'legacy_identifier')->ignore($poi?->id)],
            'is_active' => ['boolean'],
        ]);
        foreach (['street', 'house_number', 'district', 'type', 'legacy_identifier'] as $key) {
            $data[$key] = isset($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        }
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}

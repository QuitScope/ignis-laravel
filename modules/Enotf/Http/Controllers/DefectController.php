<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDefectRequest;
use App\Models\VehicleDefect;
use App\Modules\ModuleRegistry;
use App\Support\AuditLogger;
use App\Vehicles\CrewSession;
use App\Vehicles\DefectReporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Support\LocalDateTimeFormatter;

/** Fahrzeuginfo (vehicle of the crew session) and the defect report through the DefectReporter. The vehicle never comes from the input. */
class DefectController extends Controller
{
    public function index(): Response
    {
        $vehicle = CrewSession::for('enotf')->vehicle() ?? abort(403);

        return Inertia::render('Enotf/VehicleInfo', [
            'vehicle' => ['identifier' => $vehicle->identifier, 'name' => $vehicle->name, 'type' => $vehicle->type, 'license_plate' => $vehicle->license_plate],
            'defects' => VehicleDefect::query()->where('vehicle_id', $vehicle->id)->where('status', '!=', VehicleDefect::RESOLVED)->inListOrder()->limit(20)->get()
                ->map(fn (VehicleDefect $d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status, 'blocking' => ! $d->is_operable, 'created_at' => LocalDateTimeFormatter::at($d->created_at)])->all(),
            'categories' => VehicleDefect::CATEGORIES,
            'logbook' => ModuleRegistry::active('logbook'),
        ]);
    }

    public function store(Request $request, DefectReporter $reporter): RedirectResponse
    {
        $vehicle = CrewSession::for('enotf')->vehicle() ?? abort(403);
        $data = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:200', CreateDefectRequest::utf8()],
            'description' => ['nullable', 'string', 'max:5000', CreateDefectRequest::utf8()],
            'category' => ['nullable', 'string', 'max:50'],
            'is_operable' => ['sometimes', 'boolean'],
        ])->validate();

        $defect = $reporter->report($vehicle, [
            'title' => trim($data['title']), 'description' => $data['description'] ?? null, 'category' => $data['category'] ?? null,
            'is_operable' => (bool) ($data['is_operable'] ?? true),
        ], $request->user());
        AuditLogger::log($request->user(), 'Defekt gemeldet [ID: '.$defect->id.']', 'Fahrzeug-ID: '.$vehicle->id.' | '.$defect->title, 'Fahrzeuge', true, ['id' => $vehicle->id, 'defect_id' => $defect->id]);

        return redirect('/enotf/fahrzeuginfo')->with('status', $defect->is_operable ? 'vehicles.defects.reported' : 'vehicles.defects.reported_blocking');
    }
}

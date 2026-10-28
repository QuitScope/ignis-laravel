<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyTacticalSymbolRequest;
use App\Http\Requests\TacticalSymbolRequest;
use App\Models\TacticalSymbolTemplate;
use App\Models\Vehicle;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tactical symbol templates (legacy Api\VehicleTzTemplatesController). The old `save` upserted by name; create and
 * update are separate requests now, and a taken name is a validation error. Templates are global, so audit entries
 * carry no vehicle id.
 */
class TacticalSymbolTemplateController extends Controller
{
    private const MODULE = 'Fahrzeuge';

    public function index(Request $request): Response
    {
        $this->authorize('vehicle.view');

        return Inertia::render('Settings/Vehicles/SymbolTemplates', [
            'templates' => self::all(),
            'types' => Vehicle::query()->distinct()->orderBy('type')->pluck('type')->all(),
            'can' => ['manage' => $request->user()->can('vehicle.manage')],
        ]);
    }

    /**
     * All templates by name, as the settings page and the vehicle form show them.
     *
     * @return list<array{id: int, name: string, symbol: array<string, mixed>}>
     */
    public static function all(): array
    {
        return TacticalSymbolTemplate::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'symbol'])
            ->map(fn (TacticalSymbolTemplate $t) => ['id' => $t->id, 'name' => $t->name, 'symbol' => $t->symbol])->all();
    }

    public function store(TacticalSymbolRequest $request): RedirectResponse
    {
        $template = $this->saving(fn () => TacticalSymbolTemplate::create($request->templateData() + ['created_by' => $request->user()->id]));
        $this->audit($request, 'TZ-Vorlage gespeichert: '.$template->name);

        return back();
    }

    public function update(TacticalSymbolRequest $request, TacticalSymbolTemplate $template): RedirectResponse
    {
        $this->saving(fn () => $template->update($request->templateData()));
        $this->audit($request, 'TZ-Vorlage gespeichert: '.$template->name);

        return back();
    }

    public function destroy(Request $request, TacticalSymbolTemplate $template): RedirectResponse
    {
        $this->authorize('vehicle.manage');

        $template->delete();
        $this->audit($request, 'TZ-Vorlage gelöscht: '.$template->name);

        return back();
    }

    public function apply(ApplyTacticalSymbolRequest $request, TacticalSymbolTemplate $template): RedirectResponse
    {
        $type = $request->validated('type');
        $count = DB::transaction(fn () => Vehicle::query()->where('type', $type)->update(['tactical_symbol' => json_encode($template->symbol, JSON_THROW_ON_ERROR)]));
        $this->audit($request, "TZ-Vorlage '{$template->name}' auf {$count} Fahrzeuge vom Typ '{$type}' angewendet");

        return back()->with('status', 'symbol_templates.applied')->with('status_params', ['count' => $count]);
    }

    private function saving(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => trans('symbols.error.name_taken')]);
        }
    }

    private function audit(Request $request, string $action): void
    {
        AuditLogger::log($request->user(), $action, null, self::MODULE, true);
    }
}

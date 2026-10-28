<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\Medication;
use Modules\Enotf\Models\MedicationGiven;
use Modules\Enotf\Support\CrewContext;

/**
 * GET/POST/DELETE /enotf/p/{enr}/medications[/{medication}] (legacy MedisApiController): every
 * administration is its own row (two at once = two rows, no read-modify-write of a JSON column). The substance must be an
 * active catalog entry (legacy check), route and unit from the legacy catalog, dose a number. Server time unless a
 * valid manual time is sent; DELETE is a soft delete. Every answer is the current list, newest first.
 */
class MedicationController extends Controller
{
    use WritesProtocolRows;

    public function index(EnotfProtocol $protocol): JsonResponse
    {
        $this->authorize('view', $protocol);

        return $this->list($protocol);
    }

    public function store(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        $this->authorize('view', $protocol);
        $in = $request->validate([
            'medication_id' => ['required', 'integer', Rule::exists('enotf_medications', 'id')->where('is_active', true)],
            'dose' => ['required', 'string', 'max:20', 'regex:/^\d{1,6}([.,]\d{1,4})?$/D'],
            'unit' => ['required', 'string', Rule::in(array_keys(MedicationGiven::UNITS))],
            'route' => ['required', 'string', Rule::in(MedicationGiven::ROUTES)],
            'given_at' => ['nullable', 'string', 'max:20'],
        ]);
        $at = $this->wallClock($in['given_at'] ?? null, 'given_at');
        $name = Medication::query()->whereKey($in['medication_id'])->value('substance');
        $row = [
            'medication_id' => (int) $in['medication_id'], 'name' => $name, 'dose' => $in['dose'],
            'unit' => $in['unit'], 'route' => $in['route'], 'given_at' => $at,
            'created_by_user_id' => $request->user()->id, 'personnel_id' => app(CrewContext::class)->personnelId(),
        ];

        return $this->writeLocked($request, $protocol, fn (EnotfProtocol $locked) => (new MedicationGiven)->forceFill($row + ['enotf_protocol_id' => $locked->id])->save())
            ?? $this->list($protocol, 201);
    }

    public function destroy(Request $request, EnotfProtocol $protocol, MedicationGiven $medication): JsonResponse
    {
        $this->authorize('view', $protocol);
        abort_if($medication->deleted_at !== null, 404);

        return $this->writeLocked($request, $protocol, fn () => $medication->forceFill(['deleted_at' => now(), 'deleted_by_user_id' => $request->user()->id])->save())
            ?? $this->list($protocol);
    }

    /**
     * Active catalog for the picker.
     *
     * @return list<array{id: int, substance: string, brand: ?string, doses: list<string>}>
     */
    public static function catalog(): array
    {
        return Medication::query()->where('is_active', true)->orderBy('sort')->orderBy('substance')->get()
            ->map(fn (Medication $m) => ['id' => $m->id, 'substance' => $m->substance, 'brand' => $m->brand, 'doses' => $m->doses ?? []])->all();
    }

    private function list(EnotfProtocol $protocol, int $status = 200): JsonResponse
    {
        return response()->json(['medications' => MedicationGiven::listFor($protocol)], $status);
    }
}

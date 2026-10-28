<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\VitalSign;
use Modules\Enotf\Support\CrewContext;
use Modules\Enotf\Support\VitalparameterCatalog;

/**
 * GET/POST/DELETE /enotf/p/{enr}/vitals[/{vital}] (legacy VitalsApiController). POST takes several
 * parameters for one point in time and stores one row each; the time is the server's unless a valid manual time
 * is sent. DELETE is a soft delete (who/when). Every answer is the current course, newest first.
 */
class VitalController extends Controller
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
            'measured_at' => ['nullable', 'string', 'max:20'],
            'values' => ['required', 'array', 'min:1', 'max:'.count(VitalparameterCatalog::PARAMETERS)],
            'values.*' => ['nullable', 'string', 'max:50'],
        ]);
        $unknown = array_diff(array_keys($in['values']), array_keys(VitalparameterCatalog::PARAMETERS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => __('enotf.vitals.unknown')]);
        }
        $bzUnit = VitalparameterCatalog::bzUnit();
        $rows = [];
        foreach ($in['values'] as $code => $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }
            $parsed = VitalparameterCatalog::parse($code, (string) $raw, $bzUnit)
                ?? throw ValidationException::withMessages(["values.{$code}" => __('enotf.vitals.invalid')]);
            $rows[] = [$code, ...$parsed];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['values' => __('enotf.vitals.empty')]);
        }
        $at = $this->wallClock($in['measured_at'] ?? null, 'measured_at');
        $userId = $request->user()->id;
        $personnelId = app(CrewContext::class)->personnelId();

        return $this->writeLocked($request, $protocol, function (EnotfProtocol $locked) use ($rows, $at, $userId, $personnelId) {
            foreach ($rows as [$code, $value, $text]) {
                (new VitalSign)->forceFill([
                    'enotf_protocol_id' => $locked->id,
                    'parameter' => $code,
                    'value' => $value,
                    'value_text' => $text,
                    'unit' => VitalparameterCatalog::PARAMETERS[$code]['unit'],
                    'measured_at' => $at,
                    'created_by_user_id' => $userId,
                    'personnel_id' => $personnelId,
                ])->save();
            }
        }) ?? $this->list($protocol, 201);
    }

    public function destroy(Request $request, EnotfProtocol $protocol, VitalSign $vital): JsonResponse
    {
        $this->authorize('view', $protocol);
        abort_if($vital->deleted_at !== null, 404);

        return $this->writeLocked($request, $protocol, fn () => $vital->forceFill(['deleted_at' => now(), 'deleted_by_user_id' => $request->user()->id])->save())
            ?? $this->list($protocol);
    }

    /**
     * Course for the protocol page and the print view: active rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function course(EnotfProtocol $protocol): array
    {
        $unit = VitalparameterCatalog::bzUnit();

        return VitalSign::query()->where('enotf_protocol_id', $protocol->id)->active()
            ->orderByDesc('measured_at')->orderByDesc('id')->get()->map(fn (VitalSign $v) => $v->toView($unit))->all();
    }

    private function list(EnotfProtocol $protocol, int $status = 200): JsonResponse
    {
        return response()->json(['bz_unit' => VitalparameterCatalog::bzUnit(), 'vitals' => self::course($protocol)], $status);
    }
}

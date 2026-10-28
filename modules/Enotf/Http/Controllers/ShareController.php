<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Vehicles\CrewSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ShareRequest;
use Modules\Enotf\Support\ShareService;

/**
 * Sharing a protocol with another vehicle's crew. JSON only, crew context + PIN lock.
 * Sender: policy `share` on the source (crew of its vehicle, open, never a super-admin). Request ids: cancel only by the
 * account that sent it, accept/reject only by a crew of the target vehicle (never the sender); anyone else 403.
 */
class ShareController extends Controller
{
    /** GET /enotf/share/vehicles: vehicles with an active crew, not the own one (at most 20). */
    public function vehicles(): JsonResponse
    {
        $own = $this->vehicle();
        $vehicles = Vehicle::query()
            ->whereIn('id', EnotfCrew::query()->whereNull('ended_at')->where('vehicle_id', '!=', $own->id)->select('vehicle_id'))
            ->orderBy('identifier')->limit(20)->get(['id', 'identifier', 'name']);

        return response()->json(['vehicles' => $vehicles->map(fn (Vehicle $v) => ['id' => $v->id, 'identifier' => $v->identifier, 'name' => $v->name])->all()]);
    }

    /** POST /enotf/p/{enr}/share {to_vehicle_id}: a pending request (30 min), the target crew gets a bell entry. */
    public function store(Request $request, EnotfProtocol $protocol, ShareService $shares): JsonResponse
    {
        $this->authorize('view', $protocol);
        $this->authorize('share', $protocol);
        $from = $this->vehicle();
        $in = $request->validate(['to_vehicle_id' => [
            'required', 'integer', Rule::notIn([$from->id]),
            Rule::exists('enotf_crews', 'vehicle_id')->whereNull('ended_at'),
        ]]);
        $share = $shares->request($request->user(), $protocol, $from, Vehicle::query()->findOrFail($in['to_vehicle_id']));

        return response()->json(['id' => $share->id, 'expires_at' => $share->expires_at->toIso8601String()], 201);
    }

    /** POST /enotf/share/{share}/accept {target_enr?, overwrite?}: merge into an own open protocol, or a new one without target_enr. */
    public function accept(Request $request, ShareRequest $share, ShareService $shares): JsonResponse
    {
        $vehicle = $this->target($request, $share);
        $in = $request->validate(['target_enr' => ['nullable', 'string', 'max:64'], 'overwrite' => ['boolean']]);
        $target = isset($in['target_enr']) ? EnotfProtocol::query()->where('enr', $in['target_enr'])->first() ?? abort(404) : null;
        if ($target === null) {
            $this->authorize('create', EnotfProtocol::class);
        }
        try {
            $result = $shares->accept($request->user(), $share, $vehicle, $target, $request->boolean('overwrite'));
        } catch (UniqueConstraintViolationException) {
            abort(409, __('enotf.create.enr_taken'));
        }

        return response()->json([
            'enr' => $result['protocol']->enr,
            'url' => '/enotf/p/'.rawurlencode($result['protocol']->enr),
            'applied' => $result['applied'],
            'kept' => $result['kept'],
        ]);
    }

    /** POST /enotf/share/{share}/reject */
    public function reject(Request $request, ShareRequest $share, ShareService $shares): JsonResponse
    {
        abort_unless($shares->reject($request->user(), $share, $this->target($request, $share)), 409, __('enotf.share.gone'));

        return response()->json(['status' => 'rejected']);
    }

    /** POST /enotf/share/{share}/cancel: only the account that sent it. */
    public function cancel(Request $request, ShareRequest $share, ShareService $shares): JsonResponse
    {
        abort_unless($share->requested_by_user_id === $request->user()->id && ! $request->user()->is_super_admin, 403);
        abort_unless($shares->cancel($request->user(), $share), 409, __('enotf.share.gone'));

        return response()->json(['status' => 'cancelled']);
    }

    private function target(Request $request, ShareRequest $share): Vehicle
    {
        $vehicle = $this->vehicle();
        abort_unless($share->to_vehicle_id === $vehicle->id && $share->requested_by_user_id !== $request->user()->id && ! $request->user()->is_super_admin, 403);

        return $vehicle;
    }

    private function vehicle(): Vehicle
    {
        return CrewSession::for('enotf')->vehicle() ?? abort(403);
    }
}

<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Events\ProtocolReleased;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\ConditionsService;
use Modules\Enotf\Support\ProtocolValues;

/**
 * POST /enotf/p/{enr}/release: atomic under the row lock and idempotent. A repeat (or a lost race)
 * gets 409 with the current state and no second event. Plausibility is enforced here; the real admin/edivi.edit right
 * (never super-admin) may override it (legacy QM correction), the protocol author (pfname) stays required always.
 * ProtocolReleased fires only after the commit (bell + Discord).
 */
class ReleaseController extends Controller
{
    public function __invoke(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($protocol, $user) {
            $locked = EnotfProtocol::query()->whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                Gate::authorize('view', $locked);

                return response()->json(['message' => __('enotf.protocol.closed'), 'status' => $locked->status->value, 'revision' => $locked->revision], 409);
            }
            Gate::authorize('release', $locked);

            $override = ! $user->is_super_admin && $user->hasAnyPermission(['admin', 'edivi.edit']);
            $violations = array_values(array_filter(
                ConditionsService::evaluate(ProtocolValues::from($locked)),
                fn (array $v) => ! $override || $v['rule'] === 'pfname',
            ));
            if ($violations !== []) {
                return response()->json([
                    'message' => __('enotf.release.blocked', ['count' => (string) count($violations)]),
                    'violations' => array_map(fn (array $v) => $v + ['message' => __($v['message_key'])], $violations),
                ], 422);
            }

            $data = $locked->data ?? [];
            $locked->forceFill([
                'status' => ProtocolStatus::Released,
                'released_at' => now(),
                'released_by' => $user->id,
                'released_by_name' => is_string($data['pfname'] ?? null) ? $data['pfname'] : null,
                'revision' => $locked->revision + 1,
            ])->save();
            AuditLogger::log($user, 'Protokoll freigegeben', $locked->enr, 'eNOTF', context: ['protocol_id' => $locked->id, 'override' => $override]);
            DB::afterCommit(fn () => ProtocolReleased::dispatch($locked->id, $user->id));

            return response()->json(['status' => ProtocolStatus::Released->value, 'revision' => $locked->revision]);
        });
    }
}

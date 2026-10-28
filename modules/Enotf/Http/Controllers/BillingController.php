<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;

/**
 * POST /api/enotf/billing (ef_bridge `api/enotf/billing.php`, alias `/api/enotf-billing`).
 * Request {intraRP_API_Key, timestamp: <unix>}; answer {success, count, protocols: [{name, birthdate, transport,
 * missionNumber, protocolType, vehicleCallsign}]}, errors as in the legacy controller (400/500 with success:false).
 *
 * At-most-once like legacy (ef_bridge never confirms): released, not hidden, not billed protocols created up to
 * `timestamp` are claimed in ONE transaction (row lock + UPDATE … WHERE billed_at IS NULL) under a new batch id, and
 * the answer is read back by that batch id, so two parallel calls always get disjoint sets. Fixes: withdrawn (crew-
 * deleted) and hidden protocols are never billed, `transport` comes from the destination (legacy was always false).
 */
class BillingController extends Controller
{
    private const LIMIT = 500;

    private const TRANSPORT_CODES = ['2', '21', '22'];

    public function __invoke(Request $request): JsonResponse
    {
        $timestamp = $request->json()->all()['timestamp'] ?? null;
        if (! is_numeric($timestamp) || (int) $timestamp <= 0) {
            return response()->json(['success' => false, 'error' => 'Erforderliche Felder fehlen', 'message' => 'timestamp ist erforderlich'], 400);
        }
        $cutoff = Carbon::createFromTimestamp((int) $timestamp)->setTimezone(config('app.timezone'));

        try {
            $batch = (string) Str::ulid();
            $protocols = DB::transaction(function () use ($cutoff, $batch) {
                $billable = fn (): Builder => EnotfProtocol::query()->where('status', ProtocolStatus::Released)
                    ->whereNull('hidden_at')->whereNull('billed_at')->where('created_at', '<=', $cutoff);
                $ids = $billable()->orderBy('created_at')->orderBy('id')->limit(self::LIMIT)->lockForUpdate()->pluck('id');
                if ($ids->isEmpty()) {
                    return new Collection;
                }
                $billable()->whereKey($ids)->toBase()->update(['billed_at' => now(), 'billing_batch' => $batch]);

                return EnotfProtocol::query()->whereKey($ids)->where('billing_batch', $batch)->with(['transportVehicle:id,name', 'naVehicle:id,name'])
                    ->orderBy('created_at')->orderBy('id')->get();
            });
        } catch (QueryException $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'Billing-Verarbeitungsfehler'], 500);
        }

        if ($protocols->isNotEmpty()) {
            AuditLogger::log(null, 'Protokolle abgerechnet', "{$protocols->count()} Protokolle", 'eNOTF', true, ['count' => $protocols->count(), 'batch' => $batch]);
        }

        return response()->json([
            'success' => true,
            'count' => $protocols->count(),
            'protocols' => $protocols->map(fn (EnotfProtocol $p) => [
                'name' => implode(', ', array_filter([$p->patient_last_name, $p->patient_first_name], fn ($v) => $v !== null && $v !== '')),
                'birthdate' => $p->patient_birthdate?->format('Y-m-d') ?? '',
                'transport' => in_array((string) $p->getAttribute('destination_code'), self::TRANSPORT_CODES, true) || $p->destination_poi_id !== null,
                'missionNumber' => $p->enr,
                'protocolType' => (int) ($p->data['prot_by'] ?? 0),
                'vehicleCallsign' => $p->transportVehicle->name ?? $p->vehicle_transport_label ?? $p->naVehicle->name ?? $p->vehicle_na_label ?? '',
            ])->values()->all(),
        ]);
    }
}

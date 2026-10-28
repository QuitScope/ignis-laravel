<?php

namespace Modules\Enotf\Http\Controllers;

use App\Realtime\Realtime;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Enotf\Models\EnotfProtocol;

/**
 * Child rows of a protocol (vital signs, given medication): written only while the protocol is open, under its row lock
 * (a release cannot slip in between), policy `update` checked on the locked row. Rows never touch field_revisions:
 * every entry is its own row, so two devices writing at once give two rows.
 */
trait WritesProtocolRows
{
    /** @param Closure(EnotfProtocol): mixed $write */
    private function writeLocked(Request $request, EnotfProtocol $protocol, Closure $write): ?JsonResponse
    {
        return DB::transaction(function () use ($request, $protocol, $write) {
            $locked = EnotfProtocol::query()->whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                return response()->json(['message' => __('enotf.protocol.closed'), 'status' => $locked->status->value], 409);
            }
            abort_unless($request->user()->can('update', $locked), 403);
            $write($locked);
            foreach (array_unique(array_filter([$locked->vehicle_transport_id, $locked->vehicle_na_id])) as $vehicleId) {
                Realtime::pulse('enotf.vehicle.'.$vehicleId, 'rows');
            }

            return null;
        });
    }

    private function wallClock(?string $manual, string $field): Carbon
    {
        if ($manual === null || $manual === '') {
            return now();
        }
        $tz = (string) config('ignis.timezone');
        $at = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/D', $manual) === 1
            ? Carbon::createFromFormat('Y-m-d H:i', str_replace('T', ' ', $manual), $tz) : null;
        if (! $at instanceof Carbon || $at->format('Y-m-d H:i') !== str_replace('T', ' ', $manual) || $at->isAfter(now()->addMinutes(5))) {
            throw ValidationException::withMessages([$field => __('enotf.vitals.bad_time')]);
        }

        return $at->setTimezone((string) config('app.timezone'));
    }
}

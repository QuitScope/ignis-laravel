<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\FireTab\Models\AsuProtocol;
use Modules\FireTab\Models\FireIncident;

/**
 * POST /api/asu/sync and /api/asu-sync (api.key, stateless): legacy FiveM ASU protocol, upserted per (incident, supervisor).
 * Like the legacy endpoint it also accepts finalized/archived incidents (the FiveM script has no way to react to a refusal).
 */
class AsuSyncController extends Controller
{
    private const MAX_BYTES = 200 * 1024;

    public function __invoke(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return $this->fail('Payload too large', 413);
        }
        $body = json_decode($request->getContent(), true);
        if (! is_array($body)) {
            return $this->fail('Ungültiges JSON');
        }
        if (($body['type'] ?? null) !== 'asu_protocol') {
            return $this->fail('Ungültiger oder fehlender Sync-Typ');
        }
        $data = $body['data'] ?? [];
        if (empty($data)) {
            return response()->json(['success' => true, 'message' => 'Keine ASU-Daten zu verarbeiten', 'processed' => 0]);
        }

        $number = $data['missionNumber'] ?? null;
        $supervisor = $data['supervisor'] ?? null;
        if (! is_array($data) || ! is_string($number) || $number === '' || ! is_string($supervisor) || $supervisor === ''
            || mb_strlen($supervisor) > 255 || mb_strlen($number) > 50
            || (isset($data['trupps']) && ! is_array($data['trupps']))
            || (isset($data['missionLocation']) && ! is_string($data['missionLocation']))) {
            return $this->fail('Erforderliche Felder fehlen');
        }

        $incident = FireIncident::where('number', $number)->first();
        if (! $incident) {
            return $this->fail('Einsatz nicht gefunden', 404);
        }

        $attributes = [
            'mission_location' => isset($data['missionLocation']) ? mb_substr($data['missionLocation'], 0, 255) : null,
            'mission_date' => self::date($data['missionDate'] ?? null),
            'recorded_at' => self::timestamp($data['timestamp'] ?? null),
            'payload' => $data,
        ];
        $save = function () use ($incident, $supervisor, $attributes) {
            $protocol = AsuProtocol::where('fire_incident_id', $incident->id)->where('supervisor', $supervisor)->first() ?? new AsuProtocol;
            $action = $protocol->exists ? 'updated' : 'created';
            $protocol->forceFill(['fire_incident_id' => $incident->id, 'supervisor' => $supervisor, ...$attributes])->save();

            return $action;
        };
        try {
            $action = $save();
        } catch (UniqueConstraintViolationException) {
            $action = $save();
        }

        return response()->json([
            'success' => true, 'message' => 'ASU-Protokoll erfolgreich verarbeitet', 'incident_id' => $incident->id,
            'mission_number' => $number, 'supervisor' => $supervisor, 'action' => $action,
        ]);
    }

    private function fail(string $error, int $status = 400): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $error], $status);
    }

    private static function date(mixed $v): ?Carbon
    {
        if (! is_string($v) || ! preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($v), $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1]);
    }

    private static function timestamp(mixed $v): Carbon
    {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $v)) {
            try {
                return Carbon::parse($v)->utc();
            } catch (\Throwable) {
            }
        }

        return now();
    }
}

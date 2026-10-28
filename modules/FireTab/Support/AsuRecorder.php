<?php

namespace Modules\FireTab\Support;

use App\Vehicles\CrewSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FireTab\Enums\AsuEventType;
use Modules\FireTab\Events\AsuEventRecorded;
use Modules\FireTab\Models\AsuTrupp;
use Modules\FireTab\Models\FireIncident;
use RuntimeException;

/** Stores one ASU event idempotently: unique (incident, client_uuid) + insertOrIgnore, so a resend or a parallel duplicate stays one row. */
final class AsuRecorder
{
    /**
     * @param  array<string, mixed>  $d  validated by EventRequest (trupp_id, type, client_uuid; member_id, bar, note, occurred_at optional)
     * @return array{event_id: int, duplicate: bool}
     */
    public static function record(FireIncident $incident, array $d): array
    {
        $received = CarbonImmutable::now();
        $occurred = isset($d['occurred_at']) ? CarbonImmutable::createFromTimestampMs((int) $d['occurred_at']) : null;
        $type = AsuEventType::from((string) $d['type']);
        $uuid = strtolower((string) $d['client_uuid']);
        $crew = CrewSession::for('firetab');
        $device = $crew->vehicle()?->name;

        return DB::transaction(function () use ($incident, $d, $type, $uuid, $received, $occurred, $crew, $device) {
            $trupp = AsuTrupp::query()->where('fire_incident_id', $incident->id)->whereKey((int) $d['trupp_id'])->lockForUpdate()->first();
            if ($trupp === null) {
                throw ValidationException::withMessages(['trupp_id' => __('firetab.asu.trupp_gone')]);
            }
            $memberId = isset($d['member_id']) ? (int) $d['member_id'] : null;
            if ($memberId !== null && ! $trupp->members()->whereKey($memberId)->exists()) {
                throw ValidationException::withMessages(['member_id' => __('firetab.asu.member_foreign')]);
            }

            $inserted = DB::table('fire_asu_events')->insertOrIgnore([
                'fire_incident_id' => $incident->id,
                'trupp_id' => $trupp->id,
                'member_id' => $memberId,
                'type' => $type->value,
                'bar' => $d['bar'] ?? null,
                'note' => $d['note'] ?? null,
                'client_uuid' => $uuid,
                'device' => $device !== null ? mb_substr($device, 0, 100) : null,
                'occurred_at' => AsuClock::resolve($occurred, $received),
                'received_at' => $received,
            ]);
            // Unique per incident: a UUID of another incident is simply a new event here and reveals nothing.
            $id = (int) DB::table('fire_asu_events')->where('fire_incident_id', $incident->id)->where('client_uuid', $uuid)->value('id');
            if ($id === 0) {
                throw new RuntimeException('ASU event neither inserted nor found.');
            }
            if ($inserted === 0) {
                return ['event_id' => $id, 'duplicate' => true];
            }

            if ($type->logged()) {
                FireLog::record($incident, 'asu_'.$type->value, $trupp->name, $crew->current()['vehicle_id'] ?? null, system: true);
            }
            DB::afterCommit(fn () => AsuEventRecorded::dispatch($incident->id, $id));

            return ['event_id' => $id, 'duplicate' => false];
        });
    }
}

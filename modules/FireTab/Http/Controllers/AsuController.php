<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Realtime\Realtime;
use App\Vehicles\CrewSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FireTab\Enums\AsuPosition;
use Modules\FireTab\Http\Requests\FireEventRequest;
use Modules\FireTab\Http\Requests\TruppRequest;
use Modules\FireTab\Models\AsuEvent;
use Modules\FireTab\Models\AsuMember;
use Modules\FireTab\Models\AsuTrupp;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Support\AsuRecorder;

/** Atemschutzüberwachung JSON endpoints. Reads: policy `view`; writes: `update` (assigned crew, open incident). */
class AsuController extends Controller
{
    /**
     * GET …/asu/state?since=<last event id>&trupps_etag=<etag>: events after `since`, Trupps only when changed,
     * ids of all voided events (a void may hit an event older than `since`), `now` = server clock in ms.
     */
    public function state(Request $request, FireIncident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $trupps = $incident->asuTrupps()->with(['members' => fn ($q) => $q->orderBy('position')])->orderBy('id')->get()
            ->map(fn (AsuTrupp $t) => [
                'id' => $t->id, 'name' => $t->name, 'role' => $t->role->value, 'task' => $t->task, 'expected_minutes' => $t->expected_minutes,
                'members' => $t->members->map(fn (AsuMember $m) => ['id' => $m->id, 'position' => $m->position->value, 'name' => $m->name])->all(),
            ])->all();
        $etag = md5((string) json_encode($trupps));

        $events = $incident->asuEvents()->where('id', '>', max(0, $request->integer('since')))->orderBy('id')->get()
            ->map(fn (AsuEvent $e) => [
                'id' => $e->id, 'trupp_id' => $e->trupp_id, 'member_id' => $e->member_id, 'type' => $e->type->value,
                'bar' => $e->bar, 'note' => $e->note, 'device' => $e->device, 'client_uuid' => $e->client_uuid,
                'at' => $e->occurred_at->getTimestampMs(), 'received_at' => $e->received_at->getTimestampMs(), 'voided' => $e->voided_at !== null,
            ])->all();

        return response()->json([
            'now' => now()->getTimestampMs(),
            'writable' => $request->user()?->can('update', $incident) === true,
            'trupps_etag' => $etag,
            'trupps' => $request->query('trupps_etag') === $etag ? null : $trupps,
            'events' => $events,
            'voided' => $incident->asuEvents()->whereNotNull('voided_at')->orderBy('id')->pluck('id')->all(),
        ]);
    }

    public function storeTrupp(TruppRequest $request, FireIncident $incident): JsonResponse
    {
        $trupp = (new AsuTrupp)->forceFill(['fire_incident_id' => $incident->id, 'vehicle_id' => CrewSession::for('firetab')->current()['vehicle_id'] ?? null]);

        return response()->json(['trupp_id' => $this->saveTrupp($trupp, $request->validated())], 201);
    }

    public function updateTrupp(TruppRequest $request, FireIncident $incident, AsuTrupp $asuTrupp): JsonResponse
    {
        return response()->json(['trupp_id' => $this->saveTrupp($asuTrupp, $request->validated())]);
    }

    public function destroyTrupp(FireIncident $incident, AsuTrupp $asuTrupp): JsonResponse
    {
        $this->authorize('update', $incident);

        DB::transaction(function () use ($asuTrupp, $incident) {
            Realtime::pulse('firetab.incident.'.$incident->id, 'asu');
            $locked = AsuTrupp::query()->whereKey($asuTrupp->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            if ($locked->events()->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['trupp' => __('firetab.asu.trupp_has_events')]);
            }
            $locked->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function storeEvent(FireEventRequest $request, FireIncident $incident): JsonResponse
    {
        return response()->json(AsuRecorder::record($incident, $request->validated()));
    }

    public function voidEvent(Request $request, FireIncident $incident, AsuEvent $asuEvent): JsonResponse
    {
        $this->authorize('update', $incident);

        if (AsuEvent::query()->whereKey($asuEvent->id)->whereNull('voided_at')->update(['voided_at' => now(), 'voided_by' => $request->user()?->id]) === 1) {
            Realtime::pulse('firetab.incident.'.$incident->id, 'asu');
        }

        return response()->json(['ok' => true]);
    }

    /** @param array<string, mixed> $d */
    private function saveTrupp(AsuTrupp $trupp, array $d): int
    {
        return DB::transaction(function () use ($trupp, $d) {
            if ($trupp->exists) {
                $trupp = AsuTrupp::query()->whereKey($trupp->id)->lockForUpdate()->firstOrFail();
            }
            $trupp->forceFill(['name' => $d['name'], 'role' => $d['role'], 'task' => $d['task'] ?? null, 'expected_minutes' => (int) $d['expected_minutes']])->save();
            $trupp->touch();
            Realtime::pulse('firetab.incident.'.$trupp->fire_incident_id, 'asu');

            $names = array_column($d['members'], 'name', 'position');
            foreach (AsuPosition::cases() as $position) {
                $name = $names[$position->value] ?? null;
                $where = ['trupp_id' => $trupp->id, 'position' => $position->value];
                if ($name === null || $name === '') {
                    AsuMember::query()->where($where)->delete();
                } else {
                    AsuMember::query()->updateOrInsert($where, ['name' => $name]);
                }
            }

            return $trupp->id;
        });
    }
}

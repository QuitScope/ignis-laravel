<?php

namespace App\Emd;

use App\Models\User;
use App\Models\Vehicle;
use App\Modules\ModuleRegistry;
use App\Notifications\InboxNotification;
use App\Support\Settings;
use App\Vehicles\FmsStatus;
use App\Vehicles\IntakeResult;
use App\Vehicles\VehicleRegistryIntake;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Enotf\Support\DispatchProtocols;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Support\DispatchIncidents;
use Modules\FireTab\Support\FireSitreps;
use Modules\FireTab\Support\OrganizationTime;
use Modules\FireTab\Support\StatusQueue;
use Throwable;

/**
 * One EMD sync (POST api/emd/sync). The controller holds the global lock; this runs the body: registry,
 * V1 types, dispatches (fire incidents, sitreps, protocols), then statuses and fallback statuses, the answer in the legacy shape ef_bridge reads.
 */
final class EmdSync
{
    private const RETRY_MINUTES = 10;

    private const SITREP_TYPES = ['control_dispatch_form_entry_type_situation_report' => false, 'control_dispatch_form_entry_type_situation_report_important' => true];

    private const REGISTRY_PERMISSIONS = ['admin', 'vehicles.manage'];

    /** @var array<string, int> */
    private array $stats = ['processed_dispatches' => 0, 'created_entries' => 0, 'skipped_dispatches' => 0, 'created_fire_incidents' => 0, 'new_sitreps_from_dispatch' => 0];

    /** @var array<string, FireIncident> fire incidents of this sync's dispatches (their local sitreps go back) */
    private array $incidents = [];

    public function __construct(private readonly Settings $settings, private readonly VehicleRegistryIntake $intake) {}

    public function run(EmdPayload $payload): JsonResponse
    {
        $this->touchLastSync();

        if ($payload->registry !== null) {
            $result = $this->intake->accept($payload->registry);
            if ($result->error !== null) {
                $this->registryRejected($result);

                return response()->json(['success' => false, 'error' => $result->error], 422);
            }
            if ($payload->registryOnly()) {
                return response()->json(['success' => true, 'message' => "Vehicle Registry empfangen: {$result->stored} Fahrzeuge zur Prüfung bereit", 'vehicle_registry_received' => $result->stored]);
            }
        }

        $vehicles = $this->vehicles($payload);
        if ($payload->type !== null) {
            return $this->v1($payload, $vehicles);
        }

        foreach ($payload->dispatches as $number => $entries) {
            // One bad dispatch must not fail the whole sync (ef_bridge resends it every tick); its transaction rolled back.
            try {
                $this->dispatch((string) $number, $entries, $vehicles, $payload);
            } catch (Throwable $e) {
                unset($this->incidents[(string) $number]);
                $this->stats['skipped_dispatches']++;
                Log::warning('EMD sync dispatch failed', ['exception' => $e::class]);
            }
        }
        // Statuses after the dispatches, a status of a dispatch created in this sync applies at once.
        // Like legacy, only V2 carries statuses and fallbacks in the unified request.
        [$acked, $notFound] = $payload->isV2() ? $this->statuses($payload->statuses, $vehicles) : [[], 0];
        $fallbacks = 0;
        foreach ($payload->isV2() ? $payload->fallbacks : [] as $entry) {
            $fallbacks += (int) ($this->fallback($entry, $vehicles) !== null);
        }

        $counts = $this->stats + ['statuses_acked' => count($acked), 'statuses_not_found' => $notFound, 'fallbacks' => $fallbacks];
        if (array_filter($counts) !== []) {
            Log::info('EMD sync', $counts + ['vehicles' => $payload->totalVehicles, 'dispatches' => count($payload->dispatches)]);
        }

        // Return channel, at most once each: every row is marked in the transaction that reads it.
        $sitreps = $this->localSitreps();
        $patients = ModuleRegistry::active('enotf') ? DispatchProtocols::pendingPatients(100) : [];
        $statusChanges = ModuleRegistry::active('firetab') ? StatusQueue::deliver(100) : [];

        $response = [
            'success' => true,
            'message' => 'Synchronisation erfolgreich abgeschlossen',
            'statistics' => ['total_vehicles' => $payload->totalVehicles, 'unique_dispatches' => count($payload->dispatches)] + $this->stats,
        ];
        // Objects keyed by dispatch number / ENR (a list would encode as a JSON array); empty parts are left out.
        if ($sitreps !== []) {
            $response['situation_reports'] = (object) $sitreps;
        }
        if ($patients !== []) {
            $response['patient_updates'] = (object) $patients;
        }
        if ($statusChanges !== []) {
            $response['status_changes'] = $statusChanges;
        }
        if ($payload->isV2()) {
            $response['status_poll'] = ['status_changes' => $statusChanges];
            $response['status_ack'] = ['successful_ids' => $acked];
        }
        if ($this->settings->has('vehicles.registry_requested_at')) {
            $response['request_vehicle_registry'] = true;
        }

        return response()->json($response);
    }

    /**
     * One dispatch in one transaction: fire vehicles -> incident (+ sitreps of both origins), EMS vehicles -> protocols.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  Collection<string, Vehicle>  $vehicles
     */
    private function dispatch(string $number, array $entries, Collection $vehicles, EmdPayload $payload): void
    {
        $own = collect($entries)->map(fn (array $e) => is_string($e['value'] ?? null) ? $vehicles->get($e['value']) : null)->filter()->unique('id');
        $ems = $own->filter(fn (Vehicle $v) => in_array($v->service, [Vehicle::SERVICE_EMS, Vehicle::SERVICE_EMS_PHYSICIAN], true))->values();
        $fire = $own->filter(fn (Vehicle $v) => $v->service === Vehicle::SERVICE_FIRE)->values();
        if ($ems->isEmpty() && $fire->isEmpty()) {
            $this->stats['skipped_dispatches']++;

            return;
        }
        $data = $payload->dispatchData[$number] ?? [];

        DB::transaction(function () use ($number, $ems, $fire, $data, $payload) {
            if ($fire->isNotEmpty() && ModuleRegistry::active('firetab')) {
                $incident = DispatchIncidents::ensure(['number' => $number, 'vehicles' => $fire->pluck('name')->all()] + array_intersect_key($data, array_flip([
                    'postal', 'location_x', 'location_y', 'dispatch_code', 'dispatch_issue', 'caller_name', 'caller_phonenumber',
                ])));
                if ($incident !== null) {
                    $this->stats['created_fire_incidents'] += (int) $incident->wasRecentlyCreated;
                    $this->incidents[$number] = $incident;
                    $sitreps = [...(is_array($data['lagemeldungen'] ?? null) ? $data['lagemeldungen'] : []), ...$payload->sitreps[$number] ?? []];
                    foreach ($sitreps as $sitrep) {
                        $important = is_array($sitrep) && is_string($sitrep['type'] ?? null) ? (self::SITREP_TYPES[$sitrep['type']] ?? null) : null;
                        $at = $important === null ? null : self::wallTime($sitrep['date'] ?? null, $sitrep['time'] ?? null);
                        if ($at !== null && is_string($sitrep['text'] ?? null)
                            && FireSitreps::importDispatchSitrep($incident, $sitrep['text'], $at, $important) !== null) {
                            $this->stats['new_sitreps_from_dispatch']++;
                        }
                    }
                }
            }
            if ($ems->isNotEmpty() && ModuleRegistry::active('enotf')) {
                $this->stats['created_entries'] += DispatchProtocols::ensure($number, $ems, $data);
            }
        });
        $this->stats['processed_dispatches']++;
    }

    /**
     * Local, unsent sitreps of this sync's fire incidents (legacy collectLocalSitreps), read and marked in one transaction.
     *
     * @return array<string, list<array{text: string, time: string, date: string, sender: string}>>
     */
    private function localSitreps(): array
    {
        $out = [];
        foreach ($this->incidents as $number => $incident) {
            $rows = DB::transaction(function () use ($incident) {
                $rows = FireSitreps::pendingFor($incident);
                FireSitreps::markSynced($rows->pluck('id')->all());

                return $rows;
            });
            foreach ($rows as $r) {
                $at = OrganizationTime::local($r->reported_at);
                $out[(string) $number][] = ['text' => $r->text, 'time' => $at?->format('H:i') ?? '', 'date' => $at?->format('d.m.Y') ?? '', 'sender' => $r->reporter_name ?? 'Unbekannt'];
            }
        }

        return $out;
    }

    /**
     * V1 bodies with `type`: same services as V2, legacy answers.
     *
     * @param  Collection<string, Vehicle>  $vehicles
     */
    private function v1(EmdPayload $payload, Collection $vehicles): JsonResponse
    {
        $raw = $payload->raw;
        switch ($payload->type) {
            case 'dispatch_logs': // legacy: only counted, the statuses come through status_updates
                $count = is_array($raw['missions'] ?? null) ? count($raw['missions']) : 0;

                return response()->json($count === 0 ? ['success' => true, 'message' => 'Keine Einsätze zu verarbeiten', 'processed' => 0] : [
                    'success' => true, 'type' => 'dispatch_logs', 'processed' => $count, 'total_received' => $count,
                    'message' => 'Dispatch-Logs empfangen, Statusmeldungen werden über Status-Sync verarbeitet',
                ]);
            case 'status_updates':
                if ($payload->statuses === []) {
                    return response()->json(['success' => true, 'message' => 'Keine Statusmeldungen zu verarbeiten', 'processed' => 0]);
                }
                [$acked, $notFound] = $this->statuses($payload->statuses, $vehicles);

                return response()->json(['success' => true, 'type' => 'status_updates', 'updated_edivi' => count($acked), 'not_found' => $notFound,
                    'successful_ids' => $acked, 'total_received' => count($payload->statuses)]);
            case 'status_no_dispatch':
                if (! is_string($raw['vehicle_name'] ?? null) || $raw['vehicle_name'] === '' || self::code($raw['status'] ?? null) === null) {
                    return response()->json(['success' => false, 'error' => 'Fehlende Pflichtfelder (vehicle_name, status)'], 400);
                }
                $vehicle = $this->fallback($raw, $vehicles);

                return $vehicle === null
                    ? response()->json(['success' => false, 'error' => 'Fahrzeug nicht gefunden'], 404)
                    : response()->json(['success' => true, 'vehicle_id' => $vehicle->id, 'new_status' => $vehicle->fms_status, 'source' => 'no_dispatch']);
            default:
                return response()->json(['success' => false, 'error' => 'Unbekannter Sync-Typ'], 400);
        }
    }

    /**
     * Mission statuses `{id, mission_number, sender, status, timestamp}`.
     *
     * ef_bridge keeps only the highest acknowledged id and re-reads `id > highest` (server/emd_sync.lua), so a skipped
     * lower id would be lost. Only the unbroken run of ids below the first retryable status is acknowledged; everything
     * from there on comes again (re-applying is idempotent). Retryable = the mission target (protocol / incident vehicle)
     * does not exist yet and the status is younger than RETRY_MINUTES. Final (acknowledged): unknown vehicle, malformed
     * row, closed target, too old, applied.
     *
     * @param  list<array<string, mixed>>  $statuses
     * @param  Collection<string, Vehicle>  $vehicles
     * @return array{list<int>, int} acknowledged ids, not found (unknown vehicle or missing target)
     */
    private function statuses(array $statuses, Collection $vehicles): array
    {
        $done = [];
        $firstRetry = null;
        $notFound = 0;
        foreach ($statuses as $s) {
            $id = self::statusId($s['id'] ?? null);
            if ($id === null) {
                continue; // never echo a non-integer: ef_bridge compares the ids with a number
            }
            $number = EmdPayload::number($s['mission_number'] ?? null);
            $at = is_string($s['timestamp'] ?? null) && preg_match('/^(\S+) (\S+)$/D', trim($s['timestamp']), $m) === 1 ? self::wallTime($m[1], $m[2]) : null;
            $vehicle = is_string($s['sender'] ?? null) ? $vehicles->get($s['sender']) : null;
            if ($number !== null && $at !== null && $vehicle === null) {
                $notFound++;
            }
            if ($number !== null && $at !== null && $vehicle !== null
                && $this->status($vehicle, $number, is_scalar($s['status'] ?? null) ? strtoupper(trim((string) $s['status'])) : '', $at)
                && $at->gte(now()->subMinutes(self::RETRY_MINUTES))) {
                $notFound++;
                $firstRetry = min($firstRetry ?? $id, $id);

                continue;
            }
            $done[] = $id;
        }

        return [$firstRetry === null ? $done : array_values(array_filter($done, fn (int $id) => $id < $firstRetry)), $notFound];
    }

    private static function statusId(mixed $id): ?int
    {
        $id = is_string($id) && ctype_digit($id) ? (int) $id : $id;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * One mission status: protocol time (EMS), incident vehicle (fire), vehicle FMS.
     *
     * @return bool retry: the mission target (open protocol / incident vehicle) does not exist yet
     */
    private function status(Vehicle $vehicle, string $number, string $code, CarbonImmutable $at): bool
    {
        $retry = false;
        $column = $code === 'C' ? 'salarm' : (in_array($code, ['1', '2', '3', '4', '7', '8'], true) ? 's'.$code : null);
        if ($column !== null && in_array($vehicle->service, [Vehicle::SERVICE_EMS, Vehicle::SERVICE_EMS_PHYSICIAN], true) && ModuleRegistry::active('enotf')) {
            $retry = DispatchProtocols::applyStatus($vehicle, $number, $column, $at) === null;
        }
        $fms = in_array($code, FmsStatus::CODES, true);
        if ($fms && $vehicle->service === Vehicle::SERVICE_FIRE && ModuleRegistry::active('firetab')) {
            $retry = DispatchIncidents::applyStatus($vehicle, $number, $code, $at) === null;
        }
        if ($fms) {
            FmsStatus::set($vehicle, $code, 'dispatch', $at); // never into StatusQueue: it would echo back to FiveM
        }

        return $retry;
    }

    /**
     * A status without mission (`fallback_statuses` of both shapes, V1 status_no_dispatch): vehicle FMS, source no_dispatch.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  Collection<string, Vehicle>  $vehicles
     * @return ?Vehicle the vehicle set, null for an unknown vehicle or code
     */
    private function fallback(array $entry, Collection $vehicles): ?Vehicle
    {
        $vehicle = is_string($entry['vehicle_name'] ?? null) ? $vehicles->get($entry['vehicle_name']) : null;
        $code = self::code($entry['status'] ?? null);
        if ($vehicle === null || $code === null) {
            return null;
        }
        FmsStatus::set($vehicle, $code, 'no_dispatch', self::wallTime($entry['date'] ?? null, $entry['time'] ?? null));

        return $vehicle;
    }

    private static function code(mixed $status): ?string
    {
        $code = is_scalar($status) ? strtoupper(trim((string) $status)) : '';

        return in_array($code, FmsStatus::CODES, true) ? $code : null;
    }

    /**
     * Every named vehicle of this sync (dispatches, status senders, fallbacks), loaded once; names are not unique, the first row per name wins (like legacy).
     *
     * @return Collection<string, Vehicle>
     */
    private function vehicles(EmdPayload $payload): Collection
    {
        $names = collect($payload->dispatches)->flatten(1)->pluck('value')->merge(array_column($payload->statuses, 'sender'))
            ->merge(array_column($payload->fallbacks, 'vehicle_name'))->push($payload->raw['vehicle_name'] ?? null)
            ->filter(fn ($v) => is_string($v) && $v !== '')->unique()->values();

        return $names->isEmpty() ? collect() : Vehicle::query()->whereIn('name', $names->all())->orderBy('id')->get()->unique('name')->keyBy('name');
    }

    private static function wallTime(mixed $date, mixed $time): ?CarbonImmutable
    {
        if (! is_string($date) || ! is_string($time) || preg_match('/^\d{2}\.\d{2}\.\d{4}$/D', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/D', $time) !== 1) {
            return null;
        }

        return rescue(fn () => CarbonImmutable::parse(OrganizationTime::utc($date, $time), 'UTC'), null, false);
    }

    private function touchLastSync(): void
    {
        $last = $this->settings->get('emd.last_sync_at');
        if (! is_string($last) || rescue(fn () => CarbonImmutable::parse($last)->lt(now()->subMinute()), true, false)) {
            $this->settings->set('emd.last_sync_at', now()->toIso8601String());
        }
    }

    /** An oversized registry clears the request (ef_bridge would resend it every sync) and tells the admins. */
    private function registryRejected(IntakeResult $result): void
    {
        $this->settings->forget('vehicles.registry_requested_at');
        Log::warning('EMD vehicle registry rejected', ['error' => $result->error, 'received' => $result->received]);
        rescue(function () use ($result) {
            $granted = fn (Builder $q) => $q->whereIn('name', self::REGISTRY_PERMISSIONS);
            $recipients = User::active()->where(fn (Builder $q) => $q->where('is_super_admin', true)
                ->orWhereHas('roles.permissions', $granted)->orWhereHas('permissions', $granted))->get();
            // Inbox only: no Discord DM while the global sync lock is held.
            Notification::sendNow($recipients, new InboxNotification('system', trans('notifications.registry_too_large.title'),
                trans('notifications.registry_too_large.message', ['count' => $result->received, 'max' => VehicleRegistryIntake::MAX_ROWS]), '/vehicles/registry'), ['database']);
        });
    }
}

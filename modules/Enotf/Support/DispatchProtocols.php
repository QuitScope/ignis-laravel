<?php

namespace Modules\Enotf\Support;

use App\Models\Poi;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Enotf\Enums\PatientSync;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;

/**
 * Protocols from the dispatch (EMD sync). Port of the legacy EmdSyncController::createEnotfEntries: one protocol
 * per EMS vehicle (no shared sheet for RTW + NEF), ENR = dispatch number, then N_1, N_2, ...; a vehicle that already
 * sits in its slot of a protocol with that base ENR (any status) gets none.
 */
final class DispatchProtocols
{
    /**
     * @param  Collection<int, Vehicle>  $vehicles  EMS vehicles only (service 1/2); the caller sorts out fire and others
     * @param  array<string, mixed>  $dispatchData  the dispatch's `dispatch_data` (patienten, bluelight)
     * @return int protocols created
     */
    public static function ensure(string $number, Collection $vehicles, array $dispatchData): int
    {
        if ($number === '' || $vehicles->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($number, $vehicles, $dispatchData) {
            // `_` is a LIKE wildcard: the PHP filter keeps only N and N_<anything>, as the legacy escaped prefix did.
            $existing = self::base(EnotfProtocol::query(), $number)
                ->lockForUpdate()->get(['id', 'enr', 'vehicle_transport_id', 'vehicle_na_id'])
                ->filter(fn (EnotfProtocol $p) => self::isOfBase($p->enr, $number));
            $taken = $existing->pluck('enr')->flip()->all();
            $creator = app(ProtocolCreator::class);
            $created = 0;

            foreach ($vehicles as $vehicle) {
                $slot = ProtocolCreator::slot($vehicle);
                if ($existing->contains(fn (EnotfProtocol $p) => $p->{"vehicle_{$slot}_id"} === $vehicle->id)) {
                    continue;
                }
                $enr = $number;
                for ($n = 1; isset($taken[$enr]); $n++) {
                    $enr = "{$number}_{$n}";
                }
                $taken[$enr] = true;
                try {
                    $protocol = DB::transaction(fn () => self::create($enr, $vehicle, $slot, $dispatchData));
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
                $creator->copyCrewOfVehicle($protocol, $vehicle, $slot);
                $existing->push($protocol);
                $created++;
            }

            return $created;
        });
    }

    /**
     * A dispatch status time (salarm, s1..s8) on every open protocol of the dispatch that has the vehicle in a slot.
     * Overwrites like legacy, never a released/withdrawn/hidden one. Through ProtocolMerger: the revision rises,
     * tablets pick the change up via /enotf/status.
     *
     * @return ?bool null = the vehicle has no protocol of this dispatch yet (retried), false = only closed/hidden ones (final),
     *               true = written or already that time
     */
    public static function applyStatus(Vehicle $vehicle, string $number, string $column, CarbonImmutable $at): ?bool
    {
        return DB::transaction(function () use ($vehicle, $number, $column, $at) {
            $protocols = self::base(EnotfProtocol::query(), $number)->where('status', ProtocolStatus::Open)->whereNull('hidden_at')
                ->where(fn ($q) => $q->where('vehicle_transport_id', $vehicle->id)->orWhere('vehicle_na_id', $vehicle->id))
                ->orderBy('id')->lockForUpdate()->get()->filter(fn (EnotfProtocol $p) => self::isOfBase($p->enr, $number));
            if ($protocols->isEmpty()) {
                $any = self::base(EnotfProtocol::query(), $number)->where(fn ($q) => $q->where('vehicle_transport_id', $vehicle->id)->orWhere('vehicle_na_id', $vehicle->id))
                    ->pluck('enr')->contains(fn (string $enr) => self::isOfBase($enr, $number));

                return $any ? false : null;
            }
            $wall = $at->setTimezone((string) config('ignis.timezone'))->format('Y-m-d\TH:i');
            $values = FieldSchema::validate([$column => $at->utc()->format('Y-m-d\TH:i:s\Z')])['values'];
            foreach ($protocols as $protocol) {
                if (ProtocolMerger::values($protocol)[$column] !== $wall) {
                    app(ProtocolMerger::class)->apply($protocol, $protocol->revision, $values);
                }
            }

            return true;
        });
    }

    /**
     * Patient data the crews handed to dispatch (POST /enotf/p/{enr}/patient-sync), at most once: up to
     * $limit `pending` rows under lock, then `pending -> synced` in the same transaction. Any protocol status (the crew
     * handed it over on purpose). A lost answer loses them (contract, like legacy).
     *
     * @return array<string, array{vorname: string, nachname: string, alter: ?int, funkrufname: ?string, transportziel: mixed}> by ENR
     */
    public static function pendingPatients(int $limit = 100): array
    {
        return DB::transaction(function () use ($limit) {
            $rows = EnotfProtocol::query()->where('patient_sync', PatientSync::Pending)->orderBy('id')->limit($limit)->lockForUpdate()
                ->with(['transportVehicle:id,name', 'naVehicle:id,name'])->get();
            if ($rows->isEmpty()) {
                return [];
            }
            EnotfProtocol::query()->whereKey($rows->modelKeys())->where('patient_sync', PatientSync::Pending)->update(['patient_sync' => PatientSync::Synced]);
            $pois = Poi::query()->whereKey($rows->pluck('destination_poi_id')->filter()->unique()->all())->pluck('name', 'id');
            $tz = (string) config('ignis.timezone');
            $out = [];
            foreach ($rows as $p) {
                $out[$p->enr] = [
                    'vorname' => $p->patient_first_name ?? 'Unbekannt',
                    'nachname' => $p->patient_last_name ?? 'Unbekannt',
                    'alter' => $p->patient_birthdate === null ? null
                        : (int) CarbonImmutable::parse($p->patient_birthdate->format('Y-m-d'), $tz)->diffInYears(CarbonImmutable::now($tz)),
                    'funkrufname' => $p->transportVehicle->name ?? $p->naVehicle->name ?? null,
                    'transportziel' => $pois[$p->destination_poi_id] ?? $p->data['ziel_poi'] ?? null,
                ];
            }

            return $out;
        });
    }

    /**
     * @param  Builder<EnotfProtocol>  $query
     * @return Builder<EnotfProtocol> enr = N or LIKE N_% (`_` is a wildcard: callers filter with isOfBase)
     */
    private static function base(Builder $query, string $number): Builder
    {
        return $query->where(fn ($q) => $q->where('enr', $number)->orWhere('enr', 'like', $number.'_%'));
    }

    public static function isOfBase(string $enr, string $number): bool
    {
        return $enr === $number || str_starts_with($enr, $number.'_');
    }

    /** @param array<string, mixed> $dispatchData */
    private static function create(string $enr, Vehicle $vehicle, string $slot, array $dispatchData): EnotfProtocol
    {
        $local = CarbonImmutable::now((string) config('ignis.timezone'));
        $patient = is_array($dispatchData['patienten'][0] ?? null) ? $dispatchData['patienten'][0] : [];
        $name = fn (string $key) => is_scalar($patient[$key] ?? null) && trim((string) $patient[$key]) !== '' ? mb_substr(trim((string) $patient[$key]), 0, 255) : null;
        $age = $patient['alter'] ?? null;

        $protocol = (new EnotfProtocol)->forceFill([
            'enr' => $enr,
            'origin' => ProtocolOrigin::Dispatch,
            "vehicle_{$slot}_id" => $vehicle->id,
            "vehicle_{$slot}_label" => $vehicle->identifier,
            'mission_date' => $local->format('Y-m-d'),
            'mission_time' => $local->format('H:i'),
            'patient_first_name' => $name('vorname'),
            'patient_last_name' => $name('nachname'),
            'patient_birthdate' => is_numeric($age) && (int) $age >= 0 && (int) $age <= 150 ? ($local->year - (int) $age).'-01-01' : null,
            'data' => ['prot_by' => $slot === 'na' ? 1 : 0, 'sonderrechte_anfahrt' => ($dispatchData['bluelight'] ?? null) === 'yes' ? 'ja' : 'nein'],
            'created_by_user_id' => null,
        ]);
        $protocol->save();

        return $protocol;
    }
}

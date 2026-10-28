<?php

namespace Modules\Enotf\Support;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Models\CrewMember;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ProtocolCrew;

/**
 * Creating a protocol for a crew vehicle (POST /enotf/protocols, accepting a share request as a new protocol). The vehicle's
 * service picks the slot: EMS physician (NEF/NA) -> na, otherwise transport (legacy rd_type).
 */
final class ProtocolCreator
{
    public static function slot(Vehicle $vehicle): string
    {
        return $vehicle->service === Vehicle::SERVICE_EMS_PHYSICIAN ? 'na' : 'transport';
    }

    public function create(string $enr, int $protBy, User $user, Vehicle $vehicle): EnotfProtocol
    {
        $slot = self::slot($vehicle);
        $local = now()->setTimezone((string) config('ignis.timezone'));
        $protocol = (new EnotfProtocol)->forceFill([
            'enr' => $enr,
            'origin' => ProtocolOrigin::Manual,
            "vehicle_{$slot}_id" => $vehicle->id,
            "vehicle_{$slot}_label" => $vehicle->identifier,
            'mission_date' => $local->format('Y-m-d'),
            'mission_time' => $local->format('H:i'),
            'data' => ['prot_by' => $protBy],
            'created_by_user_id' => $user->id,
        ]);
        $protocol->save();
        $this->copyCrew($protocol, $user, $slot);

        return $protocol;
    }

    public function copyCrew(EnotfProtocol $protocol, User $user, string $slot): void
    {
        $this->snapshot($protocol, app(CrewService::class)->member($user)?->crew, $slot);
    }

    public function copyCrewOfVehicle(EnotfProtocol $protocol, Vehicle $vehicle, string $slot): void
    {
        $this->snapshot($protocol, EnotfCrew::query()->where('vehicle_id', $vehicle->id)->whereNull('ended_at')->latest('id')->first(), $slot);
    }

    private function snapshot(EnotfProtocol $protocol, ?EnotfCrew $crew, string $slot): void
    {
        $members = $crew?->members()->whereNotNull('active_key')
            ->with(['personnel.title', 'personnel.emsQualification'])->get() ?? collect();
        ProtocolCrew::query()->insertOrIgnore($members->map(fn (CrewMember $m) => [
            'enotf_protocol_id' => $protocol->id,
            'slot' => $slot,
            'position' => $m->position,
            'personnel_id' => $m->personnel_id,
            'name' => $m->personnel->displayName(),
            'qualification' => $m->personnel->emsQualification?->name,
        ])->all());
    }

    public function nextFreeEnr(string $enr): string
    {
        for ($n = 1; ; $n++) {
            $candidate = "{$enr}_{$n}";
            if (mb_strlen($candidate) > 64) {
                throw ValidationException::withMessages(['enr' => __('enotf.create.enr_taken')]);
            }
            if (! EnotfProtocol::query()->where('enr', $candidate)->exists()) {
                return $candidate;
            }
        }
    }
}

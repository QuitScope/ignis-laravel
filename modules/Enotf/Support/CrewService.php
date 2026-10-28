<?php

namespace Modules\Enotf\Support;

use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use App\Vehicles\CrewSession;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Enotf\Models\CrewMember;
use Modules\Enotf\Models\EnotfCrew;

/**
 * Crew life cycle: open (vehicle login), join, leave, end. One active crew per person:
 * enotf_crew_members.active_key (= personnel_id while active, NULL after) is unique, so a lost race fails on the index
 * and becomes a validation error. Free positions are checked under lockForUpdate on the crew row. The own record always
 * moves (signing in elsewhere leaves the old crew); naming a record moves it too unless it drives another crew (9a rule:
 * naming someone never locks them out).
 *
 * Session PIN: chosen at the vehicle login while enotf.pin_enabled, stored as Hash::make, valid for every
 * device of the crew. The crew locks after enotf.pin_timeout minutes without activity (any request but the status poll,
 * plus the client heartbeat) and stays locked while locked_at is set; 5 wrong PINs end the crew.
 */
class CrewService
{
    use DetectsConcurrencyErrors;

    private const MEMO = 'enotf.crew_member';

    public const MAX_ATTEMPTS = 5;

    public function member(User $user): ?CrewMember
    {
        $vehicleId = CrewSession::for('enotf')->current()['vehicle_id'] ?? null;
        if ($vehicleId === null || $user->personnel_id === null) {
            return null;
        }
        $key = $user->id.':'.$vehicleId.':'.$user->personnel_id;
        $memo = request()->attributes->get(self::MEMO);
        if (! is_array($memo) || $memo[0] !== $key) {
            $memo = [$key, CrewMember::query()->where('active_key', $user->personnel_id)->where('user_id', $user->id)
                ->whereHas('crew', fn ($q) => $q->where('vehicle_id', $vehicleId)->whereNull('ended_at'))->with('crew')->first()];
            request()->attributes->set(self::MEMO, $memo);
        }

        return $memo[1];
    }

    /**
     * Vehicle login: a new crew (driver = own record), replacing an open crew of the vehicle (the login page confirms it).
     *
     * @param  array<int, int>  $members  position => personnel_id, position 1 is the account's own record
     */
    public function open(User $user, Vehicle $vehicle, array $members, ?string $pin): EnotfCrew
    {
        return $this->guard(function () use ($user, $vehicle, $members, $pin) {
            $this->vacate((int) $user->personnel_id);
            EnotfCrew::query()->where('vehicle_id', $vehicle->id)->whereNull('ended_at')->lockForUpdate()->get()
                ->each(fn (EnotfCrew $old) => $this->close($old, 'replaced'));
            $this->claim(array_values(array_diff_key($members, [1 => true])));

            CrewSession::for('enotf')->login($user, $vehicle);
            $crew = (new EnotfCrew)->forceFill([
                'vehicle_id' => $vehicle->id, 'user_id' => $user->id, 'started_at' => now(), 'last_activity_at' => now(),
                'pin_hash' => $pin === null ? null : Hash::make($pin),
            ]);
            $crew->save();
            foreach ($members as $position => $personnelId) {
                $this->add($crew, $position, $personnelId, $position === 1 ? $user : null);
            }

            return $crew;
        });
    }

    public function joinSelf(User $user, Vehicle $vehicle, int $position, ?string $pin): EnotfCrew
    {
        return $this->guard(function () use ($user, $vehicle, $position, $pin) {
            $crew = EnotfCrew::query()->where('vehicle_id', $vehicle->id)->whereNull('ended_at')->latest('id')->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['vehicle_id' => __('enotf.crew.none_open')]);
            if ($crew->pin_hash !== null && ! Hash::check((string) $pin, $crew->pin_hash)) {
                throw ValidationException::withMessages(['pin' => __('enotf.pin.wrong')]);
            }
            $personnelId = (int) $user->personnel_id;
            $own = CrewMember::query()->where('active_key', $personnelId)->lockForUpdate()->first();
            if ($own?->enotf_crew_id === $crew->id) {
                CrewSession::for('enotf')->login($user, $vehicle);
                $own->forceFill(['user_id' => $user->id])->save();

                return $crew;
            }
            $this->vacate($personnelId);
            $this->assertFree($crew, $position);
            CrewSession::for('enotf')->login($user, $vehicle);
            $this->add($crew, $position, $personnelId, $user);

            return $crew;
        });
    }

    public function name(CrewMember $by, int $position, int $personnelId): void
    {
        $this->guard(function () use ($by, $position, $personnelId) {
            $crew = EnotfCrew::query()->whereKey($by->enotf_crew_id)->whereNull('ended_at')->lockForUpdate()->first() ?? abort(409);
            $this->assertFree($crew, $position);
            $this->claim([$personnelId]);
            $this->add($crew, $position, $personnelId, null);
        });
    }

    public function leave(CrewMember $member): void
    {
        DB::transaction(function () use ($member) {
            EnotfCrew::query()->whereKey($member->enotf_crew_id)->lockForUpdate()->first();
            $this->leaveMember($member);
        });
        CrewSession::for('enotf')->logout();
    }

    public function pinEnabled(): bool
    {
        return (bool) app(Settings::class)->get('enotf.pin_enabled');
    }

    public function locked(EnotfCrew $crew): bool
    {
        if (! $this->pinEnabled() || $crew->pin_hash === null) {
            return false;
        }
        if ($crew->locked_at !== null) {
            return true;
        }
        $minutes = max(2, min(60, (int) app(Settings::class)->get('enotf.pin_timeout')));
        if ($crew->last_activity_at !== null && $crew->last_activity_at->gt(now()->subMinutes($minutes))) {
            return false;
        }
        if (EnotfCrew::query()->whereKey($crew->id)->whereNull('locked_at')->update(['locked_at' => now()]) === 1) {
            VehiclePulse::send($crew->vehicle_id);
        }
        $crew->locked_at = now();

        return true;
    }

    public function touch(EnotfCrew $crew): void
    {
        if ($crew->last_activity_at === null || $crew->last_activity_at->lt(now()->subSeconds(30))) {
            EnotfCrew::query()->whereKey($crew->id)->whereNull('locked_at')->update(['last_activity_at' => now()]);
        }
    }

    public function unlock(EnotfCrew $crew, string $pin): ?bool
    {
        if ($crew->pin_hash !== null && Hash::check($pin, $crew->pin_hash)) {
            EnotfCrew::query()->whereKey($crew->id)->update(['locked_at' => null, 'failed_attempts' => 0, 'last_activity_at' => now()]);
            VehiclePulse::send($crew->vehicle_id);

            return true;
        }
        EnotfCrew::query()->whereKey($crew->id)->increment('failed_attempts');
        if ((int) EnotfCrew::query()->whereKey($crew->id)->value('failed_attempts') < self::MAX_ATTEMPTS) {
            return false;
        }
        $this->close($crew, 'pin_failed');
        CrewSession::for('enotf')->logout();

        return null;
    }

    public function close(EnotfCrew $crew, string $reason): void
    {
        request()->attributes->remove(self::MEMO);
        DB::transaction(function () use ($crew, $reason) {
            if (EnotfCrew::query()->whereKey($crew->id)->whereNull('ended_at')->update(['ended_at' => now(), 'ended_reason' => $reason]) === 1) {
                CrewMember::query()->where('enotf_crew_id', $crew->id)->whereNotNull('active_key')->update(['left_at' => now(), 'active_key' => null]);
                VehiclePulse::send($crew->vehicle_id);
            }
        });
    }

    /**
     * Vehicle out of service (inactive, blocking defect): its open crews end.
     *
     * @param  list<int>  $vehicleIds
     */
    public function closeVehicles(array $vehicleIds, string $reason): void
    {
        EnotfCrew::query()->whereIn('vehicle_id', $vehicleIds)->whereNull('ended_at')->get()->each(fn (EnotfCrew $c) => $this->close($c, $reason));
    }

    private function vacate(int $personnelId): void
    {
        $member = CrewMember::query()->where('active_key', $personnelId)->lockForUpdate()->first();
        if ($member !== null) {
            $this->leaveMember($member);
        }
    }

    /**
     * Named records: refused while they drive another crew, otherwise they leave it.
     *
     * @param  list<int>  $personnelIds
     */
    private function claim(array $personnelIds): void
    {
        $active = CrewMember::query()->whereIn('active_key', $personnelIds)->lockForUpdate()->get();
        if ($active->contains('position', 1)) {
            throw ValidationException::withMessages(['members' => __('enotf.login.members_busy')]);
        }
        $active->each(fn (CrewMember $m) => $this->leaveMember($m));
    }

    private function leaveMember(CrewMember $member): void
    {
        request()->attributes->remove(self::MEMO);
        CrewMember::query()->whereKey($member->id)->update(['left_at' => now(), 'active_key' => null]);
        VehiclePulse::send((int) EnotfCrew::query()->whereKey($member->enotf_crew_id)->value('vehicle_id'));
        $accounts = CrewMember::query()->where('enotf_crew_id', $member->enotf_crew_id)->whereNotNull('active_key')->whereNotNull('user_id')->exists();
        if (! $accounts) {
            $this->close(EnotfCrew::query()->findOrFail($member->enotf_crew_id), 'empty');
        }
    }

    private function assertFree(EnotfCrew $crew, int $position): void
    {
        if (CrewMember::query()->where('enotf_crew_id', $crew->id)->where('position', $position)->whereNotNull('active_key')->exists()) {
            throw ValidationException::withMessages(['position' => __('enotf.crew.position_taken')]);
        }
    }

    private function add(EnotfCrew $crew, int $position, int $personnelId, ?User $user): void
    {
        request()->attributes->remove(self::MEMO);
        (new CrewMember)->forceFill([
            'enotf_crew_id' => $crew->id, 'position' => $position, 'personnel_id' => $personnelId, 'active_key' => $personnelId,
            'user_id' => $user?->id, 'joined_at' => now(),
        ])->save();
        VehiclePulse::send($crew->vehicle_id);
    }

    /**
     * One transaction (retried up to 3 times on a deadlock, MariaDB on concurrent joins); a lost race on the active_key
     * index (the same person joined elsewhere meanwhile) or a deadlock that persists is a 422.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    private function guard(Closure $fn): mixed
    {
        try {
            return DB::transaction($fn, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['members' => __('enotf.login.members_busy')]);
        } catch (QueryException|DeadlockException $e) {
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }
            throw ValidationException::withMessages(['members' => __('enotf.login.members_busy')]);
        }
    }
}

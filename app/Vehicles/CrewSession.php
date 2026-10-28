<?php

namespace App\Vehicles;

use App\Models\FiveMCharacter;
use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle session (Fahrzeugsitzung) of a signed-in account, one per context ('firetab', 'enotf'), each under its own
 * session key, so one account may hold both at once (two frames on one cookie jar).
 * The only place that reads or writes crew state. The operator is always the account's own personnel record,
 * never request input. It does not replace the account: every crew route also needs `auth`.
 * Several tablets may sign in to the same vehicle.
 */
final class CrewSession
{
    public const CONTEXTS = [
        'firetab' => ['services' => [Vehicle::SERVICE_FIRE], 'login' => '/firetab/login'],
        'enotf' => ['services' => [Vehicle::SERVICE_EMS_PHYSICIAN, Vehicle::SERVICE_EMS], 'login' => '/enotf/login'],
    ];

    public function __construct(private readonly string $context = 'firetab')
    {
        abort_unless(isset(self::CONTEXTS[$context]), 500);
    }

    public static function for(string $context): self
    {
        return new self($context);
    }

    public function loginPath(): string
    {
        return self::CONTEXTS[$this->context]['login'];
    }

    /**
     * Vehicles this context may sign in to: one of its services, active, no open blocking defect. With `enotf.job_filter`
     * (both contexts, as legacy) and an identified FiveM character only vehicles whose `allowed_jobs` is empty or holds
     * the character's job; without a character all of them.
     *
     * @return Builder<Vehicle>
     */
    public function vehicles(): Builder
    {
        $query = Vehicle::query()->whereIn('service', self::CONTEXTS[$this->context]['services'])->operable();
        $job = app(Settings::class)->get('enotf.job_filter', false) ? FiveMCharacter::current()?->char_job : null;

        return $job === null ? $query : $query->where(fn (Builder $q) => $q->whereNull('allowed_jobs')
            ->orWhereJsonLength('allowed_jobs', 0)->orWhereJsonContains('allowed_jobs', $job));
    }

    /**
     * @throws AuthorizationException super-admin without a personnel record
     * @throws ValidationException account without a personnel record, vehicle not eligible
     */
    public function login(User $user, Vehicle $vehicle): void
    {
        if ($user->personnel_id === null) {
            if ($user->is_super_admin) {
                throw new AuthorizationException(__('crew.no_personnel'));
            }
            throw ValidationException::withMessages(['vehicle_id' => __('crew.no_personnel')]);
        }
        // enotf.char_lock (both contexts): the own record must be the identified FiveM character.
        $character = app(Settings::class)->get('enotf.char_lock', false) ? FiveMCharacter::current() : null;
        if ($character !== null && self::normalName((string) Personnel::query()->whereKey($user->personnel_id)->value('full_name')) !== self::normalName((string) $character->char_name)) {
            throw ValidationException::withMessages(['vehicle_id' => __('crew.char_mismatch')]);
        }
        if (! $this->vehicles()->whereKey($vehicle->id)->exists()) {
            throw ValidationException::withMessages(['vehicle_id' => __('crew.vehicle_unavailable')]);
        }

        // regenerate() without its token rotation: new session id against fixation, old id destroyed, but the CSRF
        // token stays, so the other frame on the same cookies (eNOTF / fireTab) keeps working (docs/dev/cef.md).
        session()->migrate(true);
        session()->put($this->key(), [
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'personnel_id' => $user->personnel_id,
            'since' => now()->getTimestamp(),
        ]);
    }

    /** @return array{user_id: int, vehicle_id: int, personnel_id: int, since: int}|null for the signed-in account only */
    public function current(): ?array
    {
        $crew = session($this->key());

        return is_array($crew) && ($crew['user_id'] ?? null) === auth()->id() ? $crew : null;
    }

    public function vehicle(): ?Vehicle
    {
        $id = $this->current()['vehicle_id'] ?? null;

        return $id === null ? null : $this->vehicles()->find($id);
    }

    public function valid(?User $user): bool
    {
        $crew = $this->current();

        return $crew !== null && $user !== null && $user->personnel_id === (int) $crew['personnel_id']
            && $this->vehicles()->whereKey((int) $crew['vehicle_id'])->exists();
    }

    public function logout(): void
    {
        session()->forget($this->key());
    }

    private static function normalName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    private function key(): string
    {
        return 'crew.'.$this->context;
    }
}

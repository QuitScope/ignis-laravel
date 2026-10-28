<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FiveM character of a browser session. The page gets a one-time token (shared prop
 * `fivem.identifyToken`) and posts it as `ignis_session.session_id` to ef_bridge, which redeems it with the character
 * at POST /api/character/identify. The plain token is kept only in the account's server-side session, the table holds
 * its SHA-256: so the link is bound to account + session and survives the session id renewal of a vehicle login,
 * and no session id ever leaves the server. Redeemable 10 min, the character counts for 12 h.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property ?Carbon $identified_at
 * @property ?string $char_name
 * @property ?string $char_job
 * @property ?int $char_id
 */
class FiveMCharacter extends Model
{
    use MassPrunable;

    protected $table = 'fivem_characters';

    public const SESSION_KEY = 'fivem_identify_token';

    public const REDEEM_MINUTES = 10;

    public const VALID_HOURS = 12;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'identified_at' => 'datetime', 'char_id' => 'integer'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function tokenFor(Request $request): ?string
    {
        $user = $request->user();
        if ($user === null || ! $request->hasSession() || stripos((string) $request->userAgent(), 'CitizenFX') === false) {
            return null;
        }
        $token = $request->session()->get(self::SESSION_KEY);
        if (is_string($token) && self::ofSession($token, (int) $user->getAuthIdentifier())
            ->where(fn (Builder $q) => $q->where('expires_at', '>', now())->orWhere('identified_at', '>', now()->subHours(self::VALID_HOURS)))
            ->exists()) {
            return $token;
        }

        $token = Str::random(40);
        static::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'token_hash' => self::hashToken($token),
            'expires_at' => now()->addMinutes(self::REDEEM_MINUTES),
        ]);
        $request->session()->put(self::SESSION_KEY, $token);

        return $token;
    }

    public static function current(): ?self
    {
        $token = session(self::SESSION_KEY);
        $userId = auth()->id();

        return is_string($token) && $userId !== null
            ? self::ofSession($token, (int) $userId)->where('identified_at', '>', now()->subHours(self::VALID_HOURS))->first()
            : null;
    }

    /** @return Builder<static> */
    private static function ofSession(string $token, int $userId): Builder
    {
        return static::query()->where('token_hash', self::hashToken($token))->where('user_id', $userId);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDay());
    }
}

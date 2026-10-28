<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One-time login token for ef_bridge (see TabletLoginController). Only the SHA-256 of the token
 * is stored; the token itself belongs in no table and no log.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property ?Carbon $used_at
 * @property Carbon $created_at
 */
class TabletLoginToken extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public static function issue(User $user): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $row = new self;
        $row->user_id = $user->id;
        $row->token_hash = hash('sha256', $token);
        $row->expires_at = now()->addSeconds((int) config('ignis.tablet_token_ttl'));
        $row->created_at = now();
        $row->save();

        return $token;
    }

    /**
     * Marks the token used with ONE conditional UPDATE: of concurrent callers exactly one affects
     * the row and gets the user id, everyone else (and any used, expired or unknown token) null.
     */
    public static function redeem(string $token): ?int
    {
        $hash = hash('sha256', $token);
        $now = now()->toDateTimeString();

        $claimed = self::query()->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['used_at' => $now]);

        if ($claimed !== 1) {
            return null;
        }

        $userId = self::query()->where('token_hash', $hash)->value('user_id');

        return is_numeric($userId) ? (int) $userId : null;
    }
}

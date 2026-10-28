<?php

namespace App\Models;

use App\Personnel\AccountLink;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code
 * @property ?string $label
 * @property ?int $personnel_id
 * @property ?int $created_by
 * @property ?int $used_by
 * @property ?Carbon $used_at
 * @property ?Carbon $expires_at
 */
class RegistrationCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public static function generate(?User $by, ?string $label, ?DateTimeInterface $expiresAt): self
    {
        $code = new self;
        $code->code = Str::random(32);
        $code->label = $label;
        $code->created_by = $by?->id;
        $code->expires_at = $expiresAt === null ? null : Carbon::instance($expiresAt);
        $code->save();

        return $code;
    }

    /** The shareable link; it carries the secret code, so never log it. */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/invite?code='.$this->code;
    }

    public function isRedeemable(): bool
    {
        return $this->used_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Atomically claims a redeemable code: exactly one concurrent caller gets the row (UPDATE
     * affects 1 row), everyone else null. Call inside the transaction that creates the account,
     * so a failure there releases the code again.
     */
    public static function reserve(string $code): ?self
    {
        $now = now()->toDateTimeString();

        $claimed = self::query()->where('code', $code)
            ->whereNull('used_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->update(['used_at' => $now]);

        return $claimed === 1 ? self::query()->where('code', $code)->first() : null;
    }

    public function redeemFor(User $user): void
    {
        $this->used_by = $user->id;
        $this->save();
        $personnelId = $this->personnel_id;
        DB::afterCommit(fn () => AccountLink::linkInvited($user, $personnelId));
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function usedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}

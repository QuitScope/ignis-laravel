<?php

namespace Modules\Mail\Models;

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Modules\Mail\Support\MailboxAccess;

/**
 * A mailbox: personal (one per record, provisioned by MailboxProvisioner) or group (members in mail_mailbox_members).
 * Deliverable only while active and not locked. Who owns it is `user_id` alone (MailboxAccess). Nothing is
 * mass-assignable; writers use forceFill.
 *
 * @property int $id
 * @property string $kind personal|group
 * @property ?int $personnel_id
 * @property ?int $user_id
 * @property string $address
 * @property string $display_name
 * @property string $domain
 * @property bool $is_active
 * @property bool $is_locked
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 * @property-read ?Personnel $personnel
 * @property-read ?User $user
 */
class Mailbox extends Model
{
    public const PERSONAL = 'personal';

    public const GROUP = 'group';

    protected $table = 'mail_mailboxes';

    protected $guarded = ['*'];

    protected $attributes = ['kind' => self::PERSONAL, 'is_active' => true, 'is_locked' => false];

    protected static function booted(): void
    {
        $forget = static fn () => MailboxAccess::forget();
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['personnel_id' => 'integer', 'user_id' => 'integer', 'is_active' => 'boolean', 'is_locked' => 'boolean'];
    }

    public function isGroup(): bool
    {
        return $this->kind === self::GROUP;
    }

    public function isOpen(): bool
    {
        return $this->is_active && ! $this->is_locked;
    }

    /**
     * Receives mail (isOpen() as a query): an inactive or locked mailbox looks like an unknown address.
     *
     * @param  Builder<Mailbox>  $query
     */
    public function scopeDeliverable(Builder $query): void
    {
        $query->where('is_active', true)->where('is_locked', false);
    }

    public function hasMember(User $user): bool
    {
        return $this->isGroup() && $this->members()->whereKey($user->id)->exists();
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mail_mailbox_members', 'mailbox_id', 'user_id')->withTimestamps();
    }
}

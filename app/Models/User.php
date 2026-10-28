<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property bool $is_super_admin
 * @property bool $is_active
 * @property bool $discord_dm
 * @property string $theme one of THEMES (checked by ProfileThemeRequest and again on output, not by the column)
 * @property ?string $calendar_token_hash sha256 of the iCal token (module Calendar), hidden
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public const THEMES = ['dark', 'light', 'system'];

    /**
     * Only the profile preferences are mass-assignable. Everything else (is_super_admin, is_active, personnel_id,
     * discord_id, tokens) is set attribute by attribute or with forceFill by the code that owns the rule.
     */
    protected $fillable = ['theme', 'discord_dm'];

    protected $hidden = ['calendar_token_hash'];

    protected $attributes = ['discord_dm' => true];

    public static function themeOrDefault(mixed $theme): string
    {
        return is_string($theme) && in_array($theme, self::THEMES, true) ? $theme : 'dark';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'discord_dm' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function role(): ?Role
    {
        $role = $this->roles->first();

        return $role instanceof Role ? $role : null;
    }

    public function setRole(Role $role): void
    {
        $this->syncRoles([$role]);
    }

    public function rank(): int
    {
        return $this->is_super_admin ? 0 : ($this->role()->priority ?? PHP_INT_MAX);
    }

    public function displayName(): string
    {
        return $this->personnel->full_name ?? $this->name ?? $this->username;
    }

    /** @param Builder<User> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param string|array<int, string> $any */
    public function hasPermission(string|array $any): bool
    {
        return $this->is_super_admin || $this->hasAnyPermission((array) $any);
    }
}

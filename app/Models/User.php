<?php

namespace App\Models;

use App\Support\GroupScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'is_super_admin', 'status',
        'mfa_secret', 'mfa_enabled', 'email_verified_at', 'phone_verified_at',
    ];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'is_super_admin' => 'boolean',
            'mfa_enabled' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'members')
            ->withPivot(['id', 'member_no', 'status', 'role_id', 'membership_category'])
            ->withTimestamps();
    }

    public function activeMembership(?int $groupId = null): ?Member
    {
        // Resolving the caller's own membership must not depend on a tenant
        // context that has not been established yet. The role is part of the
        // membership row, so it must also bypass the tenant scope: at this
        // point GroupContext has no active group and the scope would zero out
        // the relation.
        return $this->memberships()
            ->withoutGlobalScopes()
            ->when($groupId, fn ($q) => $q->where('group_id', $groupId))
            ->whereIn('status', ['active', 'approved'])
            ->with(['role' => fn ($q) => $q->withoutGlobalScope(GroupScope::class)])
            ->first();
    }

    public function hasGroupRole(int $groupId, string $roleKey): bool
    {
        return $this->memberships()
            ->withoutGlobalScopes()
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('key', $roleKey))
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials ?: 'U';
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }
}

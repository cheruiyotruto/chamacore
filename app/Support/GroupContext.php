<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Member;
use App\Models\Role;

/**
 * Holds the active group and membership for the current request/job.
 *
 * Tenant models are filtered by GroupScope using this context. Member-scoped
 * models are additionally filtered to the authenticated member's own rows.
 */
class GroupContext
{
    private ?Member $membership = null;

    private ?Group $group = null;

    private bool $bypassed = false;

    public function setMembership(?Member $membership): void
    {
        $this->membership = $membership;
        $this->group = $membership?->group;
    }

    public function setGroup(?Group $group): void
    {
        $this->group = $group;
    }

    public function membership(): ?Member
    {
        return $this->membership;
    }

    public function group(): ?Group
    {
        return $this->group;
    }

    public function groupId(): ?int
    {
        return $this->group?->id ?? $this->membership?->group_id;
    }

    public function memberId(): ?int
    {
        return $this->membership?->id;
    }

    public function roleKey(): ?string
    {
        /** @var Role|null $role */
        $role = $this->membership?->role;

        return $role?->key;
    }

    public function isMember(): bool
    {
        return $this->roleKey() === 'member';
    }

    public function isStaff(): bool
    {
        return $this->membership !== null && ! $this->isMember();
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * Explicitly disable tenant scoping. Only for migrations, seeders, platform
     * maintenance commands and tests. Never call from a web request.
     */
    public function withoutTenantScope(callable $callback): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->membership === null) {
            return false;
        }

        /** @var Role|null $role */
        $role = $this->membership->role;

        if (! $role) {
            return false;
        }

        if ($role->key === 'group_admin') {
            return true;
        }

        $permissions = $role->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function canApproveAmount(int $amount): bool
    {
        /** @var Role|null $role */
        $role = $this->membership?->role;

        if (! $role) {
            return false;
        }

        return $role->approval_limit === null || $amount <= $role->approval_limit;
    }

    /**
     * A member may only ever access their own member record; staff may access
     * records inside their own group only.
     */
    public function canAccessMember(Member $member): bool
    {
        if ($this->groupId() !== $member->group_id) {
            return false;
        }

        if ($this->isMember()) {
            return $this->memberId() === $member->id;
        }

        return $this->isStaff();
    }
}

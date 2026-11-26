<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;
use App\Support\GroupContext;

class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return app(GroupContext::class)->isStaff();
    }

    public function view(User $user, Member $member): bool
    {
        return app(GroupContext::class)->canAccessMember($member);
    }

    public function create(User $user): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff() && $context->hasPermission('members.create');
    }

    public function update(User $user, Member $member): bool
    {
        $context = app(GroupContext::class);

        if (! $context->canAccessMember($member)) {
            return false;
        }

        if ($context->isMember()) {
            return $context->memberId() === $member->id;
        }

        return $context->hasPermission('members.view');
    }

    /**
     * Changing role, status, KYC state or email is an administrative action,
     * not something a viewer-level staff role may do.
     */
    public function updateSensitive(User $user, Member $member): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $member->group_id
            && $context->hasPermission('members.update');
    }

    public function approve(User $user, Member $member): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $member->group_id
            && $context->hasPermission('members.approve');
    }

    public function terminate(User $user, Member $member): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $member->group_id
            && $context->hasPermission('members.terminate');
    }
}

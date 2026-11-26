<?php

namespace App\Policies;

use App\Models\MerryGoRoundCycle;
use App\Models\User;
use App\Support\GroupContext;

class MerryGoRoundCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return app(GroupContext::class)->membership() !== null;
    }

    public function view(User $user, MerryGoRoundCycle $cycle): bool
    {
        $context = app(GroupContext::class);

        if ($context->groupId() !== $cycle->group_id) {
            return false;
        }

        if ($context->isStaff()) {
            return true;
        }

        return $cycle->participants()
            ->where('member_id', $context->memberId())
            ->exists();
    }

    public function manage(User $user, MerryGoRoundCycle $cycle): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $cycle->group_id
            && $context->hasPermission('mgr.manage');
    }
}

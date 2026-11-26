<?php

namespace App\Policies;

use App\Models\Investment;
use App\Models\User;
use App\Support\GroupContext;

class InvestmentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(GroupContext::class)->membership() !== null;
    }

    public function view(User $user, Investment $investment): bool
    {
        $context = app(GroupContext::class);

        if ($context->groupId() !== $investment->group_id) {
            return false;
        }

        return $context->isStaff() || $context->memberId() === $investment->member_id;
    }

    /**
     * Members may open their own investments through the portal.
     */
    public function create(User $user): bool
    {
        return app(GroupContext::class)->membership() !== null;
    }

    public function withdraw(User $user, Investment $investment): bool
    {
        $context = app(GroupContext::class);

        return $context->groupId() === $investment->group_id
            && $context->memberId() === $investment->member_id
            && $investment->isOpen();
    }

    /**
     * Staff ability to record a cash or bank investment on a member's behalf.
     */
    public function deposit(User $user): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff() && $context->hasPermission('investments.deposit');
    }

    public function manage(User $user): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff() && $context->hasPermission('investments.manage');
    }
}

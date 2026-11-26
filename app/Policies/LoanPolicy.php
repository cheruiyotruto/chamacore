<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;
use App\Support\GroupContext;

class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return app(GroupContext::class)->membership() !== null;
    }

    public function view(User $user, Loan $loan): bool
    {
        $context = app(GroupContext::class);

        if ($context->groupId() !== $loan->group_id) {
            return false;
        }

        return $context->isStaff() || $context->memberId() === $loan->member_id;
    }

    public function create(User $user): bool
    {
        return app(GroupContext::class)->isStaff()
            && app(GroupContext::class)->hasPermission('loans.create');
    }

    /**
     * Members may apply for their own loan through the portal.
     */
    public function apply(User $user): bool
    {
        $context = app(GroupContext::class);

        return $context->membership() !== null;
    }

    public function approve(User $user, Loan $loan): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $loan->group_id
            && $context->hasPermission('loans.approve');
    }

    public function disburse(User $user, Loan $loan): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $loan->group_id
            && $context->hasPermission('loans.disburse');
    }

    public function restructure(User $user, Loan $loan): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $loan->group_id
            && $context->hasPermission('loans.restructure');
    }
}

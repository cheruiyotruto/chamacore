<?php

namespace App\Policies;

use App\Models\SavingsAccount;
use App\Models\User;
use App\Support\GroupContext;

class SavingsAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return app(GroupContext::class)->membership() !== null;
    }

    public function view(User $user, SavingsAccount $account): bool
    {
        $context = app(GroupContext::class);

        if ($context->groupId() !== $account->group_id) {
            return false;
        }

        return $context->isStaff() || $context->memberId() === $account->member_id;
    }

    public function deposit(User $user, SavingsAccount $account): bool
    {
        $context = app(GroupContext::class);

        return $context->groupId() === $account->group_id
            && $context->hasPermission('savings.deposit');
    }

    public function withdraw(User $user, SavingsAccount $account): bool
    {
        $context = app(GroupContext::class);

        return $context->groupId() === $account->group_id
            && $context->hasPermission('savings.withdraw');
    }
}

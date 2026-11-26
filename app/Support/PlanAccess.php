<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Plan;
use App\Models\Subscription;

/**
 * Resolves what the group's active plan actually allows.
 *
 * Plan modules were previously descriptive only. This service is the single
 * place where a plan is translated into behaviour: module access, loan limits,
 * platform-only management and where payment charges are collected.
 *
 * Groups without a subscription record are treated as unrestricted so existing
 * installations and tests keep working until they are moved onto a plan.
 */
final class PlanAccess
{
    public const SINGLE_LOAN = 'single_loan';

    public const WALLET_MEMBERS_OFFICIALS = 'wallet_members_officials';

    public const PLATFORM_MANAGED = 'platform_managed';

    public const PLATFORM_CHARGES = 'platform_charges';

    public static function planFor(Group $group): ?Plan
    {
        $owner = $group->billingOwner();

        return Subscription::withoutGlobalScopes()
            ->where('group_id', $owner->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first()
            ?->plan;
    }

    public static function hasModule(Group $group, string $module): bool
    {
        $plan = self::planFor($group);

        if (! $plan) {
            return true;
        }

        $modules = $plan->modules ?? [];

        return in_array('*', $modules, true) || in_array($module, $modules, true);
    }

    public static function hasFlag(Group $group, string $flag): bool
    {
        $plan = self::planFor($group);

        return $plan && in_array($flag, $plan->modules ?? [], true);
    }

    /**
     * The paid starter plan allows one active loan per member. Other plans keep
     * the limit configured on the loan product.
     */
    public static function maxConcurrentLoans(Group $group, int $productLimit): int
    {
        return self::hasFlag($group, self::SINGLE_LOAN) ? 1 : $productLimit;
    }

    public static function isPlatformManaged(Group $group): bool
    {
        return self::hasFlag($group, self::PLATFORM_MANAGED);
    }

    public static function isPlatformManagedPlan(Plan $plan): bool
    {
        return in_array(self::PLATFORM_MANAGED, $plan->modules ?? [], true);
    }

    public static function chargesToPlatform(Group $group): bool
    {
        return self::hasFlag($group, self::PLATFORM_CHARGES);
    }

    public static function trialDays(Plan $plan): int
    {
        return (int) ($plan->trial_days ?? 0);
    }
}

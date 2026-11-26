<?php

namespace App\Support;

use App\Models\User;

/**
 * Role-aware post-login destination.
 *
 * Group staff start in the organisation portal. Their personal member portal
 * remains available through explicit navigation. Members keep their member
 * portal as the first page after sign-in.
 */
final class LandingRoute
{
    public static function for(User $user, ?int $groupId = null): string
    {
        if ($user->isSuperAdmin()) {
            return route('platform.dashboard');
        }

        $membership = $user->activeMembership($groupId);

        if (! $membership) {
            return route('dashboard');
        }

        if (($membership->role?->key ?? 'member') === 'member') {
            return route('portal.dashboard');
        }

        return route('dashboard');
    }
}

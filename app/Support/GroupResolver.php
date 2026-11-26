<?php

namespace App\Support;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;

class GroupResolver
{
    public const SESSION_KEY = 'active_group_id';
    public const FULL_CONTROL_KEY = 'platform_full_control_group_id';

    public static function current(User $user, ?int $requestedGroupId = null): ?Group
    {
        $groupId = $requestedGroupId;

        if (! $groupId && session()->isStarted()) {
            $groupId = session(self::SESSION_KEY);
        }

        if ($groupId) {
            $managed = self::superAdminFullControlGroup($user, (int) $groupId);

            if ($managed) {
                return $managed;
            }

            $group = $user->groups()->where('groups.id', $groupId)->where('groups.status', 'active')->first();
            if ($group) {
                return $group;
            }
        }

        return $user->groups()->where('groups.status', 'active')->orderBy('groups.name')->first();
    }

    /**
     * A super admin may only enter a tenant that was explicitly marked for
     * full platform control, and only after using the platform "Open tenant"
     * action. This prevents X-Group-Id from becoming a cross-tenant bypass.
     */
    private static function superAdminFullControlGroup(User $user, int $groupId): ?Group
    {
        if (! $user->isSuperAdmin() || ! session()->isStarted()) {
            return null;
        }

        if ((int) session(self::FULL_CONTROL_KEY) !== $groupId) {
            return null;
        }

        $group = Group::query()
            ->whereKey($groupId)
            ->where('status', 'active')
            ->first();

        return $group?->allowsFullPlatformControl() ? $group : null;
    }

    public static function shareWithViews(): void
    {
        $context = app(GroupContext::class);

        View::share('currentGroup', $context->group());
        View::share('currentMembership', $context->membership());
        View::share('chamaContext', $context);
        View::share('unreadNotifications', Auth::check() ? Auth::user()->unreadNotifications()->count() : 0);
    }
}

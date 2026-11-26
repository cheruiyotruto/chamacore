<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\GroupContext;
use App\Support\GroupResolver;
use App\Support\PlanAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for every authenticated request and hard-stops requests
 * that have no valid membership. Never trusts a group id supplied by the client
 * without verifying membership.
 */
class SetGroupContext
{
    /**
     * Route-name prefixes that require a module from the group's active plan.
     * Plans without a subscription record are deliberately unrestricted.
     *
     * @var array<string, array<int, string>>
     */
    private const MODULE_ROUTES = [
        'savings' => ['portal.savings.*', 'savings.*'],
        'investments' => ['portal.investments.*', 'investments.*'],
        'loans' => ['portal.loans.*', 'portal.guarantees.*', 'loans.*', 'loan-products.*'],
        'wallet' => ['portal.wallet.*', 'wallet.*'],
        'mgr' => ['portal.mgr.*', 'mgr.*', 'reports.mgr'],
        'accounting' => ['accounting.*'],
        'crm' => ['crm.*'],
        'governance' => ['portal.governance.*', 'governance.*'],
        'documents' => ['portal.documents.*', 'documents.*'],
        'meetings' => ['meetings.*'],
        'reports' => ['reports.*'],
    ];

    public function __construct(private readonly GroupContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->status !== 'active') {
            abort(403, 'This account has been suspended.');
        }

        $requestedGroupId = $this->requestedGroupId($request);
        $group = GroupResolver::current($user, $requestedGroupId);

        if (! $group) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'You do not have access to an active group.',
                ], 403);
            }

            // Platform administrators have no tenant membership; send them to
            // the platform console instead of a dead-end 403 on group pages.
            if ($user->isSuperAdmin()) {
                return redirect()->route('platform.dashboard');
            }

            abort(403, 'You do not have access to an active group.');
        }

        // API requests must always name their tenant explicitly so a token can
        // never silently read a different group after a membership change.
        if ($request->is('api/*') && ! $requestedGroupId) {
            return response()->json([
                'message' => 'The X-Group-Id header is required.',
            ], 400);
        }

        $membership = $user->activeMembership($group->id);

        if (! $membership
            && $user->isSuperAdmin()
            && $request->hasSession()
            && (int) session(GroupResolver::FULL_CONTROL_KEY) === $group->id
            && $group->allowsFullPlatformControl()
        ) {
            $membership = app(\App\Services\PlatformAccessService::class)
                ->ensureFullControlMembership($group, $user);
        }

        if (! $membership) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'No active membership in this group.'], 403);
            }

            abort(403, 'No active membership in this group.');
        }

        $this->context->setMembership($membership->loadMissing('role', 'group'));

        if ($request->hasSession()) {
            session([GroupResolver::SESSION_KEY => $group->id]);
        }

        if ($request->is('api/*')) {
            $request->attributes->set('group', $group);
        }

        if (! $this->subscriptionAllows($group)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'The group subscription is inactive. Please contact your administrator.',
                ], 402);
            }

            if (! $request->routeIs('subscription.*', 'logout', 'notifications.*')) {
                return redirect()->route('subscription.show')
                    ->with('error', 'Your group subscription is inactive. Renew to continue.');
            }
        }

        $requiredModule = $this->requiredModule($request);

        if ($requiredModule && ! PlanAccess::hasModule($group, $requiredModule)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Your current plan does not include '.str_replace('_', ' ', $requiredModule).'.',
                ], 403);
            }

            abort(403, 'Your current plan does not include '.str_replace('_', ' ', $requiredModule).'.');
        }

        if (! $request->is('api/*')) {
            GroupResolver::shareWithViews();
        }

        // Admin-controlled member portal section access (per member).
        $routeName = $request->route()?->getName();
        if ($routeName && str_starts_with($routeName, 'portal.') && $membership->isStaff() === false) {
            $section = \App\Support\PortalAccess::sectionForRoute($routeName);
            if ($section && ! \App\Support\PortalAccess::canAccessSection($group, $membership, $section)) {
                abort(403, 'This section is not available for your account.');
            }
        }

        return $next($request);
    }

    private function requestedGroupId(Request $request): ?int
    {
        $raw = $request->header('X-Group-Id') ?? $request->input('group_id');

        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    private function requiredModule(Request $request): ?string
    {
        $routeName = $request->route()?->getName();

        if (! $routeName) {
            return null;
        }

        foreach (self::MODULE_ROUTES as $module => $patterns) {
            if ($request->routeIs(...$patterns)) {
                return $module;
            }
        }

        return null;
    }

    private function subscriptionAllows(\App\Models\Group $group): bool
    {
        $owner = $group->billingOwner();

        if ($group->status !== 'active') {
            return false;
        }

        if ($owner->onTrial()) {
            return true;
        }

        return in_array($owner->subscription_status, ['active', 'trialing'], true);
    }
}

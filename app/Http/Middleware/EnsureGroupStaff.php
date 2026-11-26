<?php

namespace App\Http\Middleware;

use App\Support\GroupContext;
use App\Support\PlanAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGroupStaff
{
    public function __construct(private readonly GroupContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->isStaff()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Staff access is required.'], 403);
            }

            abort(403, 'Staff access is required.');
        }

        $group = $this->context->group();

        // Platform-managed free groups are operated from the platform console.
        // The super admin's explicit "Open tenant" session is the only staff
        // access allowed inside their tenant pages.
        if ($group && PlanAccess::isPlatformManaged($group)) {
            $user = $request->user();

            $allowed = $user
                && $user->isSuperAdmin()
                && $request->hasSession()
                && (int) session(\App\Support\GroupResolver::FULL_CONTROL_KEY) === $group->id;

            if (! $allowed) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => 'This group is managed by the platform administrator.',
                    ], 403);
                }

                abort(403, 'This group is managed by the platform administrator.');
            }
        }

        return $next($request);
    }
}

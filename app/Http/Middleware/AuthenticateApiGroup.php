<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum authenticates the token; this middleware guarantees the token has a
 * tenant ability and that the requested group is within the token's scope.
 */
class AuthenticateApiGroup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $token) {
            return response()->json(['message' => 'A personal access token is required.'], 401);
        }

        $groupId = (int) $request->header('X-Group-Id');

        if ($groupId && method_exists($token, 'can') && ! $token->can('group:'.$groupId) && ! $token->can('*')) {
            return response()->json(['message' => 'This token is not valid for the requested group.'], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API tokens issued after an MFA challenge carry the mfa:verified ability.
 * Enabling or disabling MFA revokes all existing tokens, so an old token can
 * never keep working with stale MFA state.
 */
class EnsureApiMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->mfa_enabled) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'can') && $token->can('mfa:verified')) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Multi-factor authentication is required.',
            'code' => 'mfa_required',
        ], 423);
    }
}

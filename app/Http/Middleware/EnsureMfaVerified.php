<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an MFA challenge when the user has MFA enabled. The challenge itself
 * is handled by MfaController; this middleware protects everything else.
 */
class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->mfa_enabled) {
            return $next($request);
        }

        $verifiedAt = $request->hasSession() ? $request->session()->get('mfa_verified_at') : null;

        if ($verifiedAt && now()->timestamp - (int) $verifiedAt < 43200) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Multi-factor authentication is required.',
                'code' => 'mfa_required',
            ], 423);
        }

        return redirect()->route('mfa.challenge');
    }
}

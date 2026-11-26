<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline browser security headers to responses.
 *
 * The CSP allows the small set of CDNs the current Blade layouts use; unsafe
 * inline is retained until inline scripts/styles are nonce- or hash-based.
 * 'unsafe-eval' is required by the standard Alpine build, which compiles
 * expressions with eval(); the CSP-compatible Alpine build cannot run the
 * richer expressions used by the loan form, settings tabs and repeaters.
 * HSTS is only sent on secure requests to avoid breaking local/non-TLS setups.
 */
class SecurityHeaders
{
    private const CSP = "default-src 'self'; "
        ."base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; "
        ."style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.bunny.net; "
        ."font-src 'self' data: https://fonts.bunny.net https://fonts.gstatic.com; "
        ."img-src 'self' data: blob:; connect-src 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('X-Frame-Options', 'DENY', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none', false);
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()', false);
        $headers->set('Content-Security-Policy', self::CSP, false);

        // Avoid caching authenticated/sensitive pages in shared caches.
        if ($request->user()) {
            $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', false);
            $headers->set('Pragma', 'no-cache', false);
        }

        if ($request->isSecure()) {
            $hsts = 'max-age=31536000; includeSubDomains';

            if (config('app.hsts_preload', true)) {
                $hsts .= '; preload';
            }

            $headers->set('Strict-Transport-Security', $hsts, false);
        }

        return $response;
    }
}

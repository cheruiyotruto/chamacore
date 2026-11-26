<?php

namespace App\Http\Middleware;

use App\Support\GroupContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private readonly GroupContext $context)
    {
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        foreach ($permissions as $permission) {
            if ($this->context->hasPermission($permission)) {
                return $next($request);
            }
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'You are not authorised for this action.'], 403);
        }

        abort(403, 'You are not authorised for this action.');
    }
}

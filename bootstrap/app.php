<?php

use App\Http\Middleware\AuthenticateApiGroup;
use App\Http\Middleware\EnsureGroupStaff;
use App\Http\Middleware\EnsureApiMfaVerified;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetGroupContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'group.context' => SetGroupContext::class,
            'group.staff' => EnsureGroupStaff::class,
            'super.admin' => EnsureSuperAdmin::class,
            'mfa' => EnsureMfaVerified::class,
            'api.mfa' => EnsureApiMfaVerified::class,
            'api.group' => AuthenticateApiGroup::class,
            'force.json' => ForceJsonResponse::class,
            'permission' => EnsurePermission::class,
        ]);

        // Tenant context has to be established before route model binding so
        // tenant global scopes apply to bound models, and before the staff and
        // member middleware run. Keep the framework's default priority list and
        // slot the context middleware in front of SubstituteBindings.
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            SetGroupContext::class,
        );

        $middleware->throttleApi('60,1');

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();

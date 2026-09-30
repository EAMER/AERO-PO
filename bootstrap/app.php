<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'tenant.user' => \App\Http\Middleware\EnsureUserBelongsToTenant::class,
        ]);

        // Tenant must resolve before EVERYTHING else that depends on it
        // (auth, sessions, route-model binding), so put it first in the
        // priority list rather than trying to slot it in relative to one
        // specific middleware further down.
        $middleware->prependToPriorityList(
            before: \Illuminate\Session\Middleware\StartSession::class,
            prepend: \App\Http\Middleware\ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.user' => EnsureUserBelongsToTenant::class,
        ]);

        // Tenant must resolve before EVERYTHING else that depends on it
        // (auth, sessions, route-model binding), so put it first in the
        // priority list rather than trying to slot it in relative to one
        // specific middleware further down.
        $middleware->prependToPriorityList(
            before: StartSession::class,
            prepend: ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (UniqueConstraintViolationException $exception, Request $request) {
            $message = 'A record with this reference already exists. Refresh the page and use a unique reference.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['reference' => [$message]]], 422);
            }

            return redirect()->back()->withInput($request->except(['password', 'password_confirmation', 'current_password']))->withErrors(['reference' => $message]);
        });
    })->create();

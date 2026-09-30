<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureUserBelongsToTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->tenant_id !== TenantContext::id()) {
            auth()->logout();
            abort(403, 'Account does not belong to this tenant.');
        }

        return $next($request);
    }
}

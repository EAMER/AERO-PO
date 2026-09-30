<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Resolves the tenant from the {tenant} subdomain route parameter. */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $slug = $request->route('tenant');
        $tenant = $slug ? Tenant::where('slug', $slug)->first() : null;

        abort_if(! $tenant, 404, 'Unknown tenant.');

        TenantContext::set($tenant);
        // route('login') etc. need the {tenant} domain parameter filled in automatically.
        URL::defaults(['tenant' => $tenant->slug]);
        // Keep {tenant} out of controller arguments.
        $request->route()->forgetParameter('tenant');

        return $next($request);
    }

    public function terminate(): void
    {
        TenantContext::flush();
    }
}

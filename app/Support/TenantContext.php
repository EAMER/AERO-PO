<?php

namespace App\Support;

use App\Models\Tenant;
use Closure;

/**
 * Holds the current tenant for the request. Fails CLOSED: with no tenant set
 * and no explicit bypass, tenant-scoped queries return nothing.
 */
class TenantContext
{
    private static ?Tenant $tenant = null;
    private static bool $bypass = false;

    public static function set(?Tenant $tenant): void { self::$tenant = $tenant; }
    public static function get(): ?Tenant { return self::$tenant; }
    public static function id(): ?int { return self::$tenant?->id; }
    public static function bypassed(): bool { return self::$bypass; }

    public static function runAs(Tenant $tenant, Closure $fn): mixed
    {
        $prev = self::$tenant;
        self::$tenant = $tenant;
        try { return $fn(); } finally { self::$tenant = $prev; }
    }

    /** Cross-tenant access (seeders, super admin). Use sparingly. */
    public static function bypass(Closure $fn): mixed
    {
        $prev = self::$bypass;
        self::$bypass = true;
        try { return $fn(); } finally { self::$bypass = $prev; }
    }

    public static function flush(): void { self::$tenant = null; self::$bypass = false; }
}

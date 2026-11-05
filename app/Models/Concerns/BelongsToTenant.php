<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (! TenantContext::bypassed() && $model->tenant_id && $model->tenant_id !== TenantContext::id()) {
                throw new RuntimeException('Cannot create a record for another tenant.');
            }
            if ($model->tenant_id) {
                return;
            }
            if (TenantContext::id() === null) {
                throw new RuntimeException(static::class.' created without a tenant context.');
            }
            $model->tenant_id = TenantContext::id();
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new RuntimeException('The tenant of an existing record cannot change.');
            }
            if (! TenantContext::bypassed() && $model->tenant_id !== TenantContext::id()) {
                throw new RuntimeException('Cannot update a record outside its tenant context.');
            }
        });
        static::deleting(function ($model) {
            if (! TenantContext::bypassed() && $model->tenant_id !== TenantContext::id()) {
                throw new RuntimeException('Cannot delete a record outside its tenant context.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

<?php

namespace App\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::bypassed()) {
            return;
        }

        $id = TenantContext::id();

        $id === null
            ? $builder->whereRaw('1 = 0')                       // fail closed
            : $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}

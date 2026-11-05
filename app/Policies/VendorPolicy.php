<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Support\TenantContext;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->tenant_id === TenantContext::id() && $user->role !== Role::Requester;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && match ($user->role) {
            Role::LogisticsOfficer, Role::TenantAdmin, Role::SuperAdmin => true,
            default => false,
        };
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $vendor->tenant_id === $user->tenant_id && $this->create($user);
    }

    public function approve(User $user, Vendor $vendor): bool
    {
        return $vendor->tenant_id === $user->tenant_id && $this->viewAny($user) && match ($user->role) {
            Role::Tlm, Role::TenantAdmin, Role::SuperAdmin => true,
            default => false,
        };
    }
}

<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\TenantContext;

class PurchaseOrderPolicy
{
    public function view(User $user, PurchaseOrder $order): bool
    {
        if (! $user->is_active || $user->tenant_id !== TenantContext::id() || $order->tenant_id !== $user->tenant_id) {
            return false;
        }

        return match ($user->role) {
            Role::Requester => $order->requested_by === $user->id,
            default => true,
        };
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->tenant_id === TenantContext::id() && match ($user->role) {
            Role::Requester, Role::LogisticsOfficer, Role::TenantAdmin, Role::SuperAdmin => true,
            default => false,
        };
    }

    public function source(User $user, PurchaseOrder $order): bool
    {
        return $this->view($user, $order) && match ($user->role) {
            Role::LogisticsOfficer, Role::TenantAdmin, Role::SuperAdmin => true,
            default => false,
        };
    }

    public function receive(User $user, PurchaseOrder $order): bool
    {
        return $this->view($user, $order) && match ($user->role) {
            Role::StoreStaff, Role::StoreSupervisor, Role::TenantAdmin, Role::SuperAdmin => true,
            default => false,
        };
    }
}

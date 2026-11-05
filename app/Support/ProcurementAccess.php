<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\AmountChange;
use App\Models\FreightQuote;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class ProcurementAccess
{
    public static function roles(User $actor, Role ...$roles): void
    {
        if ($actor->tenant_id !== TenantContext::id() || ! $actor->is_active || (! $actor->role->isAdmin() && ! in_array($actor->role, $roles, true))) {
            throw new AuthorizationException('Your account cannot perform this action.');
        }
    }

    public static function tenant(Model $record): void
    {
        $tenantId = $record instanceof Tenant ? $record->id : $record->tenant_id;
        if ($tenantId !== TenantContext::id()) {
            throw new AuthorizationException('This record does not belong to the current tenant.');
        }
    }

    public static function audit(Model $subject, User $actor, string $action, array $before = [], array $after = [], ?string $reason = null): void
    {
        self::tenant($subject);
        ActivityLog::create(self::payload($subject, $actor, $action, $before, $after, $reason));
    }

    public static function amount(Model $subject, User $actor, string $action, array $before, array $after, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Explain the amount change.');
        }
        self::tenant($subject);
        AmountChange::create(self::payload($subject, $actor, $action, $before, $after, $reason));
    }

    private static function payload(Model $subject, User $actor, string $action, array $before, array $after, ?string $reason): array
    {
        $orderId = match (true) {
            $subject instanceof PurchaseOrder => $subject->id,
            $subject instanceof FreightQuote => $subject->shipment->purchase_order_id,
            default => $subject->getAttribute('purchase_order_id'),
        };

        return [
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id,
            'purchase_order_id' => $orderId,
            'actor_id' => $actor->id, 'action' => $action, 'before' => $before, 'after' => $after, 'reason' => $reason,
        ];
    }
}

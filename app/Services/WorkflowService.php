<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Events\PurchaseOrderTransitioned;
use App\Models\PoStatusLog;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY place a PO's status changes. Other modules call this and listen
 * for PurchaseOrderTransitioned; they never write `status` directly.
 */
class WorkflowService
{
    public function transition(
        PurchaseOrder $po,
        PurchaseOrderStatus $to,
        User $actor,
        ?string $note = null,
    ): PurchaseOrder {
        return DB::transaction(function () use ($po, $to, $actor, $note) {
            // Re-read under lock so two people can't move the same PO at once.
            $po = PurchaseOrder::whereKey($po->getKey())->lockForUpdate()->firstOrFail();
            $from = $po->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidArgumentException("Cannot move a PO from {$from->value} to {$to->value}.");
            }

            if (! $actor->role->isAdmin() && ! in_array($actor->role, $to->enteredBy(), true)) {
                throw new AuthorizationException("Role {$actor->role->value} cannot move a PO to {$to->value}.");
            }

            $po->status = $to;
            $po->save();

            PoStatusLog::create([
                'purchase_order_id' => $po->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'note' => $note,
            ]);

            PurchaseOrderTransitioned::dispatch($po, $from, $to, $actor, $note);

            return $po;
        });
    }
}

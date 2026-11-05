<?php

namespace App\Listeners;

use App\Events\ApprovalQueried;
use App\Events\ApprovalQueryAnswered;
use App\Events\ApprovalStepOpened;
use App\Events\PurchaseOrderTransitioned;
use App\Models\User;
use App\Notifications\ApprovalActionRequired;
use App\Notifications\ApprovalQueryRaised;
use App\Notifications\ApprovalQueryWasAnswered;
use App\Notifications\RequisitionDecided;
use App\Notifications\ShipmentProgress;
use Illuminate\Support\Facades\Notification;

/** Laravel auto-discovers these handle* methods by their type-hinted event. */
class SendApprovalNotifications
{
    public function handleStepOpened(ApprovalStepOpened $e): void
    {
        $recipients = User::where('role', $e->approval->role)->where('is_active', true)
            ->when($e->approval->assigned_to, fn ($query) => $query->whereKey($e->approval->assigned_to))->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ApprovalActionRequired($e->approval));
        }
    }

    public function handleQueried(ApprovalQueried $e): void
    {
        $e->query->target->notify(new ApprovalQueryRaised($e->query));
    }

    public function handleQueryAnswered(ApprovalQueryAnswered $e): void
    {
        $e->query->asker->notify(new ApprovalQueryWasAnswered($e->query));
    }

    public function handleTransitioned(PurchaseOrderTransitioned $e): void
    {
        if (in_array($e->to->value, ['in_transit', 'delivered', 'received'], true)) {
            $stakeholders = User::where('is_active', true)->where(function ($query) use ($e) {
                $query->whereIn('role', ['logistics_officer', 'store_supervisor', 'store_staff', 'tlm'])->orWhere('id', $e->po->requested_by);
            })->get();
            Notification::send($stakeholders, new ShipmentProgress($e->po));
        }
        if (! in_array($e->to->value, ['closed', 'rejected'], true)) {
            return;
        }

        $requester = $e->po->requester;
        if ($requester && $requester->is_active) {
            $requester->notify(new RequisitionDecided($e->po));
        }
    }
}

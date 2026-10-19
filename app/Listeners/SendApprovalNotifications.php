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
use Illuminate\Support\Facades\Notification;

/** Laravel auto-discovers these handle* methods by their type-hinted event. */
class SendApprovalNotifications
{
    public function handleStepOpened(ApprovalStepOpened $e): void
    {
        $recipients = User::where('role', $e->approval->role)->where('is_active', true)->get();

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
        if (! in_array($e->to->value, ['closed', 'rejected'], true)) {
            return;
        }

        $requester = $e->po->requester;
        if ($requester && $requester->is_active) {
            $requester->notify(new RequisitionDecided($e->po));
        }
    }
}

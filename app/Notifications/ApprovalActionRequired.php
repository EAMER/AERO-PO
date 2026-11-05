<?php

namespace App\Notifications;

use App\Models\Approval;

class ApprovalActionRequired extends WorkflowMessage
{
    public function __construct(Approval $approval)
    {
        $order = $approval->order();
        parent::__construct('Action needed: '.$order->reference, $order->reference.' is waiting on your '.$approval->stage.' approval (step '.$approval->step_order.', round '.$approval->round.').', route('requisitions.show', ['tenant' => $order->tenant->slug, 'purchaseOrder' => $order->id]));
    }
}

<?php

namespace App\Notifications;

use App\Models\ApprovalQuery;

class ApprovalQueryRaised extends WorkflowMessage
{
    public function __construct(ApprovalQuery $query)
    {
        $order = $query->approval->order();
        parent::__construct('Question about '.$order->reference, $query->asker->name.' asked: '.$query->question, route('requisitions.show', ['tenant' => $order->tenant->slug, 'purchaseOrder' => $order->id]));
    }
}

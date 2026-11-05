<?php

namespace App\Notifications;

use App\Models\ApprovalQuery;

class ApprovalQueryWasAnswered extends WorkflowMessage
{
    public function __construct(ApprovalQuery $query)
    {
        $order = $query->approval->order();
        parent::__construct('Answered: '.$order->reference, $query->target->name.' answered your question: '.$query->answer, route('requisitions.show', ['tenant' => $order->tenant->slug, 'purchaseOrder' => $order->id]));
    }
}

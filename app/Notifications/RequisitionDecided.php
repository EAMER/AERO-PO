<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;

class RequisitionDecided extends WorkflowMessage
{
    public function __construct(PurchaseOrder $order)
    {
        parent::__construct($order->reference.' is '.$order->status->value, 'Your requisition '.$order->reference.' is '.$order->status->value.'. See its history for details.', route('requisitions.show', ['tenant' => $order->tenant->slug, 'purchaseOrder' => $order->id]));
    }
}

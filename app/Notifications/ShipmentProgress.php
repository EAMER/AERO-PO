<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;

class ShipmentProgress extends WorkflowMessage
{
    public function __construct(PurchaseOrder $order)
    {
        parent::__construct('Shipment update: '.$order->reference, $order->reference.' has reached '.$order->status->value.'. AWB: '.($order->awb_number ?? 'not assigned'), route('requisitions.show', ['tenant' => $order->tenant->slug, 'purchaseOrder' => $order->id]));
    }
}

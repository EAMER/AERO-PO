<?php

namespace App\Events;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PurchaseOrderTransitioned
{
    use Dispatchable;

    public function __construct(
        public PurchaseOrder $po,
        public PurchaseOrderStatus $from,
        public PurchaseOrderStatus $to,
        public User $actor,
        public ?string $note = null,
    ) {}
}

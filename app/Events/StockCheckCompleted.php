<?php

namespace App\Events;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class StockCheckCompleted
{
    use Dispatchable;

    /** @param bool $fulfilledFromStock true if every line was available */
    public function __construct(public PurchaseOrder $po, public bool $fulfilledFromStock, public User $actor) {}
}

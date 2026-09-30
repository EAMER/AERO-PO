<?php

namespace App\Events;

use App\Models\ApprovalQuery;
use Illuminate\Foundation\Events\Dispatchable;

/** Notify `$query->asker` that they can decide the step now. */
class ApprovalQueryAnswered
{
    use Dispatchable;

    public function __construct(public ApprovalQuery $query) {}
}

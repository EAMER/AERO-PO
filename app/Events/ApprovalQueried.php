<?php

namespace App\Events;

use App\Models\ApprovalQuery;
use Illuminate\Foundation\Events\Dispatchable;

/** Notify `$query->target` that a question is waiting for them. */
class ApprovalQueried
{
    use Dispatchable;

    public function __construct(public ApprovalQuery $query) {}
}

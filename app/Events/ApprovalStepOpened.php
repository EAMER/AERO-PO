<?php

namespace App\Events;

use App\Models\Approval;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when an approval step becomes actionable - either it's step 1, or the step before it just got approved. */
class ApprovalStepOpened
{
    use Dispatchable;

    public function __construct(public Approval $approval) {}
}

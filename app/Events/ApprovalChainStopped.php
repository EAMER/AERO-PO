<?php

namespace App\Events;

use App\Enums\ApprovalDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when an approver returns or rejects. */
class ApprovalChainStopped
{
    use Dispatchable;

    public function __construct(
        public Model $subject,
        public string $stage,
        public ApprovalDecision $decision,
        public $actor,
        public ?string $note = null,
    ) {}
}

<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class ApprovalChainCompleted
{
    use Dispatchable;

    public function __construct(public Model $subject, public string $stage, public $actor) {}
}

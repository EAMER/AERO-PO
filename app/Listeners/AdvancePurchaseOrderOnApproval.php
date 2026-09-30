<?php

namespace App\Listeners;

use App\Enums\ApprovalDecision;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Events\ApprovalChainCompleted;
use App\Events\ApprovalChainStopped;
use App\Models\PurchaseOrder;
use App\Services\ApprovalService;
use App\Services\WorkflowService;

/**
 * Wires approval outcomes to PO status. Laravel auto-discovers the two
 * handle* methods by their type-hinted events.
 */
class AdvancePurchaseOrderOnApproval
{
    public function __construct(private WorkflowService $workflow, private ApprovalService $approvals) {}

    public function handleCompleted(ApprovalChainCompleted $e): void
    {
        if (! $e->subject instanceof PurchaseOrder) {
            return;
        }
        $po = $e->subject;

        if ($e->stage === 'evaluation') {
            // TLM / HAMO / TD signed off the quote evaluation -> PO goes to the CFO.
            $po = $this->workflow->transition($po, S::Approved, $e->actor, 'Evaluation approved');
            $this->workflow->transition($po, S::PendingCfo, $e->actor);
            $this->approvals->request($po, [Role::Cfo], 'cfo');
        } elseif ($e->stage === 'cfo') {
            $this->workflow->transition($po, S::PoSent, $e->actor, 'CFO approved');
        }
    }

    public function handleStopped(ApprovalChainStopped $e): void
    {
        if (! $e->subject instanceof PurchaseOrder) {
            return;
        }

        if ($e->decision === ApprovalDecision::Rejected) {
            $this->workflow->transition($e->subject, S::Rejected, $e->actor, $e->note);
        }
    }
}

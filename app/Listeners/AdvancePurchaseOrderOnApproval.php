<?php

namespace App\Listeners;

use App\Enums\ApprovalDecision;
use App\Enums\PurchaseOrderStatus as S;
use App\Events\ApprovalChainCompleted;
use App\Events\ApprovalChainStopped;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\QuoteEvaluation;
use App\Models\Shipment;
use App\Services\ApprovalService;
use App\Services\PurchaseOrderService;
use App\Services\TenantSettingsService;
use App\Services\WorkflowService;

class AdvancePurchaseOrderOnApproval
{
    public function __construct(private WorkflowService $workflow, private ApprovalService $approvals) {}

    public function handleCompleted(ApprovalChainCompleted $event): void
    {
        $subject = $event->subject;
        if ($subject instanceof Invoice || $subject instanceof Shipment) {
            $subject->update(['status' => 'approved']);

            return;
        }
        $po = $subject instanceof QuoteEvaluation ? $subject->purchaseOrder : $subject;
        if (! $po instanceof PurchaseOrder) {
            return;
        }
        if ($event->stage === 'evaluation') {
            if ($subject instanceof QuoteEvaluation) {
                $subject->update(['status' => 'approved']);
            }
            $po = $this->workflow->transition($po, S::Approved, $event->actor, 'Quote evaluation approved');
            $po = $this->workflow->transition($po, S::PendingCfo, $event->actor);
            app(PurchaseOrderService::class)->generatePdf($po, $event->actor);
            $this->approvals->request($po, app(TenantSettingsService::class)->rolesFor('cfo'), 'cfo');
        } elseif ($event->stage === 'cfo') {
            $po->update(['po_approved_at' => now()]);
            $po = $this->workflow->transition($po, S::PoApproved, $event->actor, 'CFO approved PO; dispatch pending');
            app(PurchaseOrderService::class)->generatePdf($po, $event->actor);
        }
    }

    public function handleStopped(ApprovalChainStopped $event): void
    {
        $returned = $event->decision === ApprovalDecision::Returned;
        $subject = $event->subject;
        if ($subject instanceof Invoice || $subject instanceof Shipment) {
            $subject->update(['status' => $returned ? 'returned' : 'rejected']);

            return;
        }
        $po = $subject instanceof QuoteEvaluation ? $subject->purchaseOrder : $subject;
        if (! $po instanceof PurchaseOrder) {
            return;
        }
        if ($subject instanceof QuoteEvaluation) {
            $subject->update(['status' => $returned ? 'returned' : 'rejected']);
        }
        $target = $returned ? ($event->stage === 'cfo' ? S::ReturnedCfo : S::ReturnedEvaluation) : S::Rejected;
        $this->workflow->transition($po, $target, $event->actor, $event->note);
    }
}

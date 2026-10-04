<?php

namespace App\Services;

use App\Contracts\StockChecker;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Events\StockCheckCompleted;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class RequisitionService
{
    public function __construct(
        private WorkflowService $workflow,
        private ApprovalService $approvals,
        private DocumentChecklistService $checklist,
    ) {}

    public function raise(string $reference, User $requester, array $lines, bool $isAmoRequest = false): PurchaseOrder
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A requisition needs at least one part line.');
        }

        return DB::transaction(function () use ($reference, $requester, $lines, $isAmoRequest) {
            $po = PurchaseOrder::create([
                'reference' => $reference,
                'requested_by' => $requester->id,
                'is_amo_request' => $isAmoRequest,
            ]);

            foreach ($lines as $line) {
                $po->lines()->create([
                    'part_number' => $line['part_number'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'condition' => $line['condition'] ?? null,
                ]);
            }

            return $po;
        });
    }

    /** 7.1.1: check every line against DigiMaint (or its fake for now). */
    public function checkStock(PurchaseOrder $po, User $actor, StockChecker $checker): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $checker) {
            /** @var PoLine $line */
            foreach ($po->lines as $line) {
                $line->update([
                    'stock_available' => $checker->isAvailable($line),
                    'stock_checked_at' => now(),
                ]);
            }

            $po = $this->workflow->transition($po, S::StockChecked, $actor, 'Stock checked against DigiMaint');

            $allAvailable = $po->lines->every(fn (PoLine $l) => $l->stock_available === true);

            if ($allAvailable) {
                $po = $this->workflow->transition($po, S::FulfilledFromStock, $actor);
            }

            StockCheckCompleted::dispatch($po, $allAvailable, $actor);

            return $po;
        });
    }

    /** Logistics confirms external RFQs have gone out to vendors (by email, outside the app). */
    public function markRfqSent(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return $this->workflow->transition($po, S::RfqSent, $actor, 'RFQs sent to vendors (external)');
    }

    /** Logistics confirms quotations are in and uploaded. */
    public function markQuotesIn(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return $this->workflow->transition($po, S::QuotesIn, $actor, 'Quotations received');
    }

    /** Logistics records which payment path this vendor uses (manual 7.1.4 vs 7.1.5). */
    public function setPaymentTerms(PurchaseOrder $po, User $actor, string $terms): PurchaseOrder
    {
        if (! in_array($terms, ['net', 'cia'], true)) {
            throw new InvalidArgumentException("Payment terms must be 'net' or 'cia'.");
        }

        $po->update(['payment_terms' => $terms]);

        return $po->fresh();
    }

    /** 7.1.4: PO acknowledged by the vendor. Needs payment terms set and the acknowledged copy uploaded. */
    public function markAcknowledged(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if (! $po->payment_terms) {
            throw new RuntimeException('Set the payment terms before recording acknowledgement.');
        }

        $missing = $this->checklist->missing($po, 'acknowledgement');
        if ($missing !== []) {
            throw new RuntimeException('Acknowledgement is incomplete: '.implode(', ', $missing));
        }

        return $this->workflow->transition($po, S::Acknowledged, $actor, 'PO acknowledged by vendor');
    }

    /** 7.1.5: cash-in-advance vendor - move to awaiting payment. */
    public function proceedToPayment(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->payment_terms !== 'cia') {
            throw new RuntimeException('Only cash-in-advance orders go through the payment stage here.');
        }

        return $this->workflow->transition($po, S::AwaitingPayment, $actor);
    }

    /** 7.1.6-7.1.8: proforma invoice vetted, signed off, and payment confirmed (telex copy uploaded). */
    public function confirmPayment(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->payment_terms !== 'cia') {
            throw new RuntimeException('Only cash-in-advance orders confirm payment here.');
        }

        $missing = $this->checklist->missing($po, 'payment_cia');
        if ($missing !== []) {
            throw new RuntimeException('Payment pack is incomplete: '.implode(', ', $missing));
        }

        return $this->workflow->transition($po, S::ReadyToShip, $actor, 'Payment confirmed');
    }

    /** 7.1.4: net-terms vendor - no upfront payment, ready to ship once acknowledged. */
    public function markReadyToShipOnNetTerms(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->payment_terms !== 'net') {
            throw new RuntimeException('Only net-terms orders skip straight to shipping.');
        }

        return $this->workflow->transition($po, S::ReadyToShip, $actor, 'Net terms - no upfront payment');
    }

    /**
     * 7.1.2: submit the evaluation pack for approval. Blocked until the
     * document checklist is complete (min. 3 quotations, etc.).
     */
    public function submitForApproval(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $missing = $this->checklist->missing($po, 'evaluation_pack');

        if ($missing !== []) {
            throw new RuntimeException('Evaluation pack is incomplete: '.implode(', ', $missing));
        }

        return DB::transaction(function () use ($po, $actor) {
            $po = $this->workflow->transition($po, S::PendingApproval, $actor, 'Evaluation pack submitted');

            $roles = $po->is_amo_request
                ? [Role::Tlm, Role::Hamo, Role::TechnicalDirector]
                : [Role::Tlm, Role::TechnicalDirector];

            $this->approvals->request($po, $roles, 'evaluation');

            return $po;
        });
    }
}

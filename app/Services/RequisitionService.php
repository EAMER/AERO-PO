<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Events\StockCheckCompleted;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\User;
use App\Support\ProcurementAccess as Access;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequisitionService
{
    public function __construct(private WorkflowService $workflow, private ApprovalService $approvals, private DocumentChecklistService $checklist) {}

    public function raise(string $reference, User $requester, array $lines, bool $isAmoRequest = false): PurchaseOrder
    {
        Access::roles($requester, Role::Requester, Role::LogisticsOfficer);
        if ($lines === []) {
            throw new InvalidArgumentException('A requisition needs at least one part line.');
        }

        return DB::transaction(function () use ($reference, $requester, $lines, $isAmoRequest) {
            $requisition = Requisition::create(['reference' => $reference, 'requested_by' => $requester->id, 'is_amo_request' => $isAmoRequest]);
            $po = PurchaseOrder::create(['reference' => $reference, 'requested_by' => $requester->id, 'is_amo_request' => $isAmoRequest, 'requisition_id' => $requisition->id]);
            foreach ($lines as $line) {
                $attributes = collect($line)->only(['part_number', 'description', 'quantity', 'condition'])->all();
                if ((int) $line['quantity'] < 1) {
                    throw new InvalidArgumentException('Requested quantities must be positive.');
                }
                $original = $requisition->lines()->create($attributes);
                $po->lines()->create($attributes + ['requisition_line_id' => $original->id]);
            }
            Access::audit($po, $requester, 'requisition.raised', [], ['requisition_id' => $requisition->id]);

            return $po;
        });
    }

    public function checkStock(PurchaseOrder $po, User $actor, array $availability): PurchaseOrder
    {
        Access::roles($actor, Role::StoreStaff, Role::StoreSupervisor);

        return DB::transaction(function () use ($po, $actor, $availability) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, [S::RequisitionRaised, S::StockChecked, S::FulfilledFromStock], true) || $po->rfqs()->exists() || $po->handovers()->where('activity', 'stock_issue')->whereIn('status', ['recorded', 'verified'])->exists()) {
                throw new InvalidArgumentException('Stock can only be checked before sourcing or an actual stock issue.');
            }
            foreach ($po->lines as $line) {
                $check = $po->handovers()->where('activity', 'stock_check')->where('po_line_id', $line->id)->whereIn('status', ['recorded', 'verified'])->latest('id')->first();
                if (! array_key_exists($line->id, $availability) || ! $check || $check->status !== 'verified') {
                    throw new InvalidArgumentException('Record and independently verify DigiMaint stock-check evidence for every requested line.');
                }
                $available = $check->quantity >= $line->quantity;
                if ((bool) $availability[$line->id] !== $available) {
                    throw new InvalidArgumentException('Availability must agree with the recorded DigiMaint quantity.');
                }
                $line->update(['stock_available' => $available, 'stock_checked_at' => $check->performed_at]);
            }
            $allAvailable = $po->lines()->get()->every(fn ($line) => $line->stock_available === true);
            if ($po->status === S::RequisitionRaised || ($po->status === S::FulfilledFromStock && ! $allAvailable)) {
                $po = $this->workflow->transition($po, S::StockChecked, $actor, 'DigiMaint stock checked manually with verified evidence');
            }
            if ($allAvailable && $po->status !== S::FulfilledFromStock) {
                $po = $this->workflow->transition($po, S::FulfilledFromStock, $actor);
            }
            Access::audit($po, $actor, 'stock.checked', [], ['all_available' => $allAvailable]);
            StockCheckCompleted::dispatch($po, $allAvailable, $actor);

            return $po;
        });
    }

    public function markRfqSent(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        app(SourcingService::class)->sendRfqs($po, $actor);

        return $po->fresh();
    }

    public function markQuotesIn(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);
        if (! $po->quotes()->exists()) {
            throw new InvalidArgumentException('Capture the vendor quotations before marking them received.');
        }

        return $po->status === S::QuotesIn ? $po->fresh() : $this->workflow->transition($po, S::QuotesIn, $actor);
    }

    public function setPaymentTerms(PurchaseOrder $po, User $actor, string $terms): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);
        if (! in_array($terms, ['net', 'cia'], true) || $po->status !== S::QuotesIn || $terms !== data_get($po->commercial_snapshot, 'payment_terms')) {
            throw new InvalidArgumentException('Payment terms come from the selected vendor quotation and are frozen at submission.');
        }
        $po->update(['payment_terms' => $terms]);

        return $po->fresh();
    }

    public function markAcknowledged(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);
        if (! $po->payment_terms || $this->checklist->missing($po, 'acknowledgement') !== []) {
            throw new \DomainException('Set approved commercial terms and upload the acknowledged PO copy.');
        }

        return $this->workflow->transition($po, S::Acknowledged, $actor, 'PO acknowledged by vendor');
    }

    public function proceedToPayment(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->payment_terms !== 'cia' || ! $po->invoice) {
            throw new \DomainException('Prepare the CIA proforma invoice before submitting its finance approvals.');
        }
        app(PaymentService::class)->submit($po->invoice, $actor);

        return $po->fresh();
    }

    public function confirmPayment(PurchaseOrder $po, User $actor, string $reference = ''): PurchaseOrder
    {
        if (! $po->invoice) {
            throw new \DomainException('Prepare and approve an invoice before confirming payment.');
        }
        app(PaymentService::class)->confirm($po->invoice, $actor, $reference);

        return $po->fresh();
    }

    public function markReadyToShipOnNetTerms(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);
        if ($po->status !== S::Acknowledged || $po->payment_terms !== 'net') {
            throw new \DomainException('Only an acknowledged net-terms order can take this shipping path.');
        }

        return $this->workflow->transition($po, S::ReadyToShip, $actor, 'Net terms; payment follows Stores receipt');
    }

    public function submitForApproval(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($po, $actor) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->rfqs()->where('kind', 'parts')->whereNotIn('status', ['sent', 'withdrawn'])->exists()) {
                throw new InvalidArgumentException('Resolve queued or failed RFQ deliveries before submitting the evaluation.');
            }
            $missing = $this->checklist->missing($po, 'evaluation_pack');
            if ($missing !== []) {
                throw new \DomainException('Evaluation pack is incomplete: '.implode(', ', $missing));
            }
            $evaluation = $po->evaluations()->where('revision', $po->revision)->where('status', 'draft')->first();
            if (! $evaluation) {
                throw new InvalidArgumentException('Prepare the structured quotation comparison and select a vendor first.');
            }
            $evaluation = app(SourcingService::class)->evaluate($po, $actor, $evaluation->selected_quote_id, $evaluation->justification);
            $po = $po->fresh();
            $snapshot = $po->commercial_snapshot;
            $snapshot['documents'] = $po->documents()->get()->map->only(['id', 'type', 'disk_path', 'original_filename'])->all();
            $po->update(['commercial_snapshot' => $snapshot]);
            $po = $this->workflow->transition($po, S::PendingApproval, $actor, 'Evaluation pack submitted');
            $evaluation->update(['status' => 'pending_approval']);
            $this->approvals->request($evaluation, app(TenantSettingsService::class)->rolesFor('evaluation', $po->is_amo_request), 'evaluation');

            return $po;
        });
    }
}

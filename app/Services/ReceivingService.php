<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus as Status;
use App\Enums\Role;
use App\Models\DigimaintHandover;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\ProcurementAccess as Access;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReceivingService
{
    public function receive(PurchaseOrder $order, User $actor, string $grn, array $quantities, ?string $note = null): GoodsReceipt
    {
        Access::roles($actor, Role::StoreStaff, Role::StoreSupervisor);

        return DB::transaction(function () use ($order, $actor, $grn, $quantities, $note) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== Status::Delivered || trim($grn) === '' || $quantities === []) {
                throw new InvalidArgumentException('Record receipt quantities and a GRN after delivery.');
            }
            $missing = app(DocumentChecklistService::class)->missing($order, 'receiving');
            if ($missing !== []) {
                throw new InvalidArgumentException('Receiving documents are incomplete: '.implode(', ', $missing));
            }
            $lines = $order->lines()->get()->keyBy('id');
            $already = $this->receivedQuantities($order);
            $received = [];
            foreach ($quantities as $id => $quantity) {
                if (! isset($lines[$id]) || ! filter_var($quantity, FILTER_VALIDATE_INT) || $quantity < 1 || $quantity > $lines[$id]->quantity - ($already[$id] ?? 0)) {
                    throw new InvalidArgumentException('Receipt quantities must be positive and cannot exceed the remaining quantity for this order.');
                }
                $received[] = ['po_line_id' => $id, 'quantity' => (int) $quantity];
            }
            $receipt = GoodsReceipt::create(['purchase_order_id' => $order->id, 'grn_number' => $grn, 'received_by' => $actor->id, 'received_at' => now(), 'note' => $note]);
            $receipt->lines()->createMany($received);
            Access::audit($order, $actor, 'stores.received', [], ['receipt_id' => $receipt->id, 'grn' => $grn, 'lines' => $received]);
            if ($this->fullyReceived($order)) {
                $order->update(['grn_number' => $grn]);
                app(WorkflowService::class)->transition($order, Status::Received, $actor, 'All ordered quantities received; '.$grn);
            }

            return $receipt;
        });
    }

    public function receivedQuantities(PurchaseOrder $order): array
    {
        return GoodsReceiptLine::whereHas('goodsReceipt', fn ($q) => $q->where('purchase_order_id', $order->id))
            ->selectRaw('po_line_id, SUM(quantity) as received')->groupBy('po_line_id')->pluck('received', 'po_line_id')->all();
    }

    public function fullyReceived(PurchaseOrder $order): bool
    {
        $quantities = $this->receivedQuantities($order);

        return $order->lines()->exists() && $order->lines()->get()->every(fn ($line) => (int) ($quantities[$line->id] ?? 0) === $line->quantity);
    }

    public function recordHandover(PurchaseOrder $order, User $actor, array $data): DigimaintHandover
    {
        Access::roles($actor, Role::StoreStaff, Role::StoreSupervisor);

        return DB::transaction(function () use ($order, $actor, $data) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $activity = $data['activity'];
            if (! preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $data['performed_at'])) {
                throw new InvalidArgumentException('Include the UTC offset in the DigiMaint action time.');
            }
            $line = $order->lines()->findOrFail($data['po_line_id']);
            $document = $order->documents()->whereKey($data['po_document_id'])->where('type', DocumentType::DigimaintEvidence)->firstOrFail();
            $receipt = null;
            if ($activity === 'receipt') {
                $receipt = $order->receipts()->findOrFail($data['goods_receipt_id']);
                $receiptLine = $receipt->lines()->where('po_line_id', $line->id)->firstOrFail();
                if ((int) $data['quantity'] !== $receiptLine->quantity) {
                    throw new InvalidArgumentException('The DigiMaint receipt quantity must match the GRN line.');
                }
            } elseif ($activity === 'stock_issue') {
                $stockCheck = $order->handovers()->where('activity', 'stock_check')->where('po_line_id', $line->id)->latest('id')->first();
                if ($order->status !== Status::FulfilledFromStock || (int) $data['quantity'] !== $line->quantity || ! $stockCheck || $stockCheck->status !== 'verified' || $stockCheck->quantity < $line->quantity) {
                    throw new InvalidArgumentException('A stock issue must cover the requested line on a stock-fulfilled order.');
                }
            } elseif ($activity !== 'stock_check' || ! in_array($order->status, [Status::RequisitionRaised, Status::StockChecked, Status::FulfilledFromStock], true)) {
                throw new InvalidArgumentException('Choose an activity appropriate to the order stage.');
            }
            if ($activity === 'stock_check' && $order->handovers()->where('activity', 'stock_issue')->whereIn('status', ['recorded', 'verified'])->exists()) {
                throw new InvalidArgumentException('An actual stock issue is already recorded. Resolve that handover before changing the stock check.');
            }
            if (in_array($order->status, [Status::Closed, Status::Rejected, Status::Cancelled], true) || Carbon::parse($data['performed_at'])->isFuture() || (int) $data['quantity'] < 0) {
                throw new InvalidArgumentException('Record an actual DigiMaint action on an open order, using its completed action time.');
            }
            $previous = $order->handovers()->where('activity', $activity)->where('po_line_id', $line->id)->where('goods_receipt_id', $receipt?->id)->latest('id')->first();
            if ($previous && $activity !== 'stock_check' && $previous->status !== 'exception') {
                throw new InvalidArgumentException('This line already has a handover. Verify the existing entry or resolve its exception.');
            }
            $handover = DigimaintHandover::create([
                'purchase_order_id' => $order->id, 'goods_receipt_id' => $receipt?->id, 'po_line_id' => $line->id,
                'activity' => $activity, 'transaction_reference' => $data['transaction_reference'], 'po_document_id' => $document->id,
                'dedupe_key' => $activity === 'stock_check' ? null : hash('sha256', $activity.'|'.trim($data['transaction_reference']).'|'.$line->id),
                'quantity' => (int) $data['quantity'], 'recorded_by' => $actor->id, 'performed_at' => Carbon::parse($data['performed_at'])->utc(),
                'status' => 'recorded', 'note' => $data['note'] ?? null, 'supersedes_id' => $previous?->id,
            ]);
            if ($previous) {
                $previous->update(['status' => 'superseded']);
            }
            Access::audit($order, $actor, 'digimaint.recorded', [], $handover->getAttributes());

            return $handover;
        });
    }

    public function verify(DigimaintHandover $handover, User $actor, bool $verified, string $note): void
    {
        Access::roles($actor, Role::StoreSupervisor);
        Access::tenant($handover);
        DB::transaction(function () use ($handover, $actor, $verified, $note) {
            $handover = DigimaintHandover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            if ($handover->status !== 'recorded' || $handover->recorded_by === $actor->id || trim($note) === '') {
                throw new InvalidArgumentException('Another Stores operator must record the entry; verification needs a note and an unverified handover.');
            }
            $before = $handover->getAttributes();
            $handover->update(['status' => $verified ? 'verified' : 'exception', 'verified_by' => $actor->id, 'verified_at' => $verified ? now() : null, 'note' => $note]);
            Access::audit($handover->purchaseOrder, $actor, 'digimaint.'.($verified ? 'verified' : 'exception'), $before, $handover->getAttributes(), $note);
            $order = $handover->purchaseOrder;
            if ($verified && in_array($handover->activity, ['receipt', 'stock_issue'], true) && $this->handoverComplete($order, $handover->activity)) {
                $order->update(['digimaint_synced_at' => now()]);
            }
        });
    }

    public function handoverComplete(PurchaseOrder $order, string $activity): bool
    {
        if ($order->handovers()->where('status', 'exception')->exists()) {
            return false;
        }
        if ($activity === 'stock_issue') {
            $lines = $order->lines()->get();

            return $lines->isNotEmpty() && $lines->every(fn ($line) => $order->handovers()->where('activity', 'stock_issue')->where('po_line_id', $line->id)->where('status', 'verified')->where('quantity', $line->quantity)->exists());
        }
        if (! $this->fullyReceived($order)) {
            return false;
        }
        foreach ($order->receipts()->with('lines')->get() as $receipt) {
            foreach ($receipt->lines as $line) {
                if (! $receipt->handovers()->where('po_line_id', $line->po_line_id)->where('activity', 'receipt')->where('quantity', $line->quantity)->where('status', 'verified')->exists()) {
                    return false;
                }
            }
        }

        return true;
    }
}

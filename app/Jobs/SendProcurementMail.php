<?php

namespace App\Jobs;

use App\Enums\PurchaseOrderStatus as Status;
use App\Enums\Role;
use App\Mail\ProcurementMessage;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\ProcurementAccess as Access;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendProcurementMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $tenantId, public string $kind, public int $recordId, public int $actorId) {}

    public function handle(): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        TenantContext::runAs($tenant, function () {
            $actor = User::findOrFail($this->actorId);
            Access::roles($actor, Role::LogisticsOfficer);
            if ($this->kind === 'rfq') {
                $this->sendRfq($actor);
            } elseif ($this->kind === 'purchase_order') {
                $this->sendOrder($actor);
            } else {
                throw new \InvalidArgumentException('Unknown procurement delivery type.');
            }
        });
    }

    private function sendRfq(User $actor): void
    {
        $rfq = DB::transaction(function () {
            $rfq = Rfq::whereKey($this->recordId)->lockForUpdate()->firstOrFail();
            if ($rfq->status !== 'queued') {
                return null;
            }
            $order = $rfq->purchaseOrder;
            if (! $rfq->vendor->is_active || ! $rfq->vendor->is_approved || ($rfq->kind === 'parts' && ! in_array($order->status, [Status::StockChecked, Status::RfqSent, Status::QuotesIn], true)) || ($rfq->kind === 'freight' && $order->status !== Status::ReadyToShip)) {
                throw new \DomainException('Vendor approval or workflow stage changed before RFQ delivery.');
            }
            $rfq->update(['status' => 'sending']);

            return $rfq;
        });
        if (! $rfq) {
            return;
        }
        $order = $rfq->purchaseOrder;
        $lines = $order->lines->map(fn ($line) => $line->part_number.' | '.$line->description.' | Qty '.$line->quantity.' | '.($line->condition ?? 'Condition to be quoted'))->implode("\n");
        $body = 'Please quote against '.$rfq->reference.".\n\n".$lines."\n\nInclude currency, unit prices, condition, warranty, validity, and payment terms.";
        if ($rfq->kind === 'freight') {
            $body .= "\nShipment: ".$rfq->shipment->weight_kg.' kg; dangerous goods: '.($rfq->shipment->contains_dg ? 'yes' : 'no').'.';
        }
        Mail::to($rfq->vendor->email)->send(new ProcurementMessage($rfq->reference.' - Request for quotation', $body));
        DB::transaction(function () use ($rfq, $order, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $rfq->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null]);
            Access::audit($order, $actor, 'rfq.sent', [], ['rfq_id' => $rfq->id, 'vendor_id' => $rfq->vendor_id]);
            if ($rfq->kind === 'parts' && $order->status === Status::StockChecked && ! $order->rfqs()->where('kind', 'parts')->where('revision', $rfq->revision)->whereNotIn('status', ['sent', 'withdrawn'])->exists()) {
                app(WorkflowService::class)->transition($order, Status::RfqSent, $actor, 'All approved-vendor RFQs delivered');
            }
        });
    }

    private function sendOrder(User $actor): void
    {
        $order = DB::transaction(function () {
            $order = PurchaseOrder::whereKey($this->recordId)->lockForUpdate()->firstOrFail();
            if ($order->status !== Status::PoApproved || ! $order->dispatch_queued_at || $order->dispatch_started_at) {
                return null;
            }
            if (! $order->vendor?->is_approved || ! $order->vendor?->is_active || ! $order->po_approved_at || ! $order->pdf_path) {
                throw new \DomainException('Purchase order is no longer eligible for dispatch.');
            }
            $order->update(['dispatch_started_at' => now()]);

            return $order;
        });
        if (! $order) {
            return;
        }
        $recipient = data_get($order->commercial_snapshot, 'vendor.email');
        Mail::to($recipient)->send(new ProcurementMessage('Purchase order '.$order->reference, 'Please find our approved purchase order attached. Confirm acknowledgement against '.$order->reference.'.', $order->pdf_path));
        DB::transaction(function () use ($order, $actor) {
            $order->update(['issued_at' => now()]);
            app(WorkflowService::class)->transition($order, Status::PoSent, $actor, 'Approved PO PDF delivered to vendor');
        });
    }

    public function failed(?Throwable $exception): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }
        TenantContext::runAs($tenant, function () {
            if ($this->kind === 'rfq') {
                Rfq::whereKey($this->recordId)->where('status', '!=', 'sent')->update(['status' => 'failed', 'last_error' => 'Delivery failed. Confirm the delivery outcome before retrying.']);
            } else {
                PurchaseOrder::whereKey($this->recordId)->where('status', Status::PoApproved)->update(['dispatch_queued_at' => null, 'dispatch_started_at' => null]);
            }
        });
    }
}

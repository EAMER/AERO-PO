<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vendor;
use App\Support\ProcurementAccess as Access;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ShippingService
{
    public function __construct(private WorkflowService $workflow, private DocumentChecklistService $checklist) {}

    public function recordShipmentDetails(PurchaseOrder $po, User $actor, float $weightKg, bool $containsDg, ?int $carrierId = null): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($po, $actor, $weightKg, $containsDg, $carrierId) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== S::ReadyToShip || ! is_finite($weightKg) || $weightKg < 0.01 || $weightKg > 99999999.99) {
                throw new InvalidArgumentException('Record a valid shipment weight while the order is ready to ship.');
            }
            $shipment = $po->shipment;
            if ($shipment && ($shipment->status !== 'draft' || $shipment->rfqs()->exists())) {
                throw new InvalidArgumentException('Freight already under sourcing or approval cannot be changed.');
            }
            $heavy = $containsDg || $weightKg >= 50;
            $carrier = $carrierId ? Vendor::whereKey($carrierId)->where('kind', 'courier')->where('is_active', true)->where('is_approved', true)->firstOrFail() : null;
            if (! $heavy && ! $carrier) {
                throw new InvalidArgumentException('Select an approved courier account for a light non-DG shipment.');
            }
            $shipment = Shipment::updateOrCreate(['purchase_order_id' => $po->id], ['route' => $heavy ? 'freight_forwarder' : 'courier', 'weight_kg' => $weightKg, 'contains_dg' => $containsDg, 'carrier_id' => $heavy ? null : $carrier->id, 'status' => 'draft']);
            $po->update(['total_weight_kg' => $weightKg, 'contains_dg' => $containsDg]);
            Access::audit($po, $actor, 'shipment.prepared', [], $shipment->getAttributes());

            return $po->fresh();
        });
    }

    public function isHeavyOrDangerous(PurchaseOrder $po): bool
    {
        return $po->contains_dg || (float) $po->total_weight_kg >= 50;
    }

    public function missingShippingDocuments(PurchaseOrder $po): array
    {
        return $this->checklist->missing($po, $this->isHeavyOrDangerous($po) ? 'shipping_heavy' : 'shipping_light');
    }

    public function selectFreight(PurchaseOrder $order, User $actor, int $quoteId): void
    {
        Access::roles($actor, Role::LogisticsOfficer);
        DB::transaction(function () use ($order, $actor, $quoteId) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = $order->shipment;
            if ($order->status !== S::ReadyToShip || ! $shipment || $shipment->route !== 'freight_forwarder' || ! in_array($shipment->status, ['draft', 'returned'], true)) {
                throw new InvalidArgumentException('Select a freight quotation for a draft or returned freight shipment.');
            }
            $quote = $shipment->quotes()->with('vendor')->findOrFail($quoteId);
            if (! $quote->vendor->is_approved || ! $quote->vendor->is_active) {
                throw new InvalidArgumentException('The freight forwarder must still be approved and active.');
            }
            $before = $shipment->only(['carrier_id', 'currency', 'freight_minor']);
            $shipment->update(['carrier_id' => $quote->vendor_id, 'selected_quote_id' => $quote->id, 'currency' => $quote->currency, 'freight_minor' => $quote->amount_minor, 'status' => 'pending_approval']);
            Access::amount($shipment, $actor, 'freight.selected', $before, $shipment->only(['carrier_id', 'currency', 'freight_minor']), 'Freight quotation selected');
            app(ApprovalService::class)->request($shipment, app(TenantSettingsService::class)->rolesFor('freight'), 'freight');
        });
    }

    public function dispatch(PurchaseOrder $po, User $actor, string $awbNumber): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($po, $actor, $awbNumber) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            $shipment = $po->shipment;
            if ($po->status !== S::ReadyToShip || ! $shipment || trim($awbNumber) === '' || ! $shipment->carrier?->is_approved || ! $shipment->carrier?->is_active) {
                throw new InvalidArgumentException('A ready order, approved carrier, shipment details, and AWB number are required.');
            }
            if (($shipment->route === 'freight_forwarder' && $shipment->status !== 'approved') || ($shipment->route === 'courier' && ! $shipment->customs_duty_paid)) {
                throw new InvalidArgumentException('Complete TLM freight approval or the courier customs-duty payment before dispatch.');
            }
            $missing = $this->missingShippingDocuments($po);
            if ($missing !== []) {
                throw new \DomainException('Shipping documents incomplete: '.implode(', ', $missing));
            }
            $shipment->update(['awb_number' => $awbNumber, 'status' => 'dispatched']);
            $po->update(['awb_number' => $awbNumber]);

            return $this->workflow->transition($po, S::InTransit, $actor, 'Dispatched, AWB '.$awbNumber);
        });
    }

    public function markCustomsDutyPaid(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($po, $actor) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== S::ReadyToShip || ! $po->shipment || ! $po->documents()->where('type', DocumentType::CustomsDutyReceipt)->exists()) {
                throw new InvalidArgumentException('Upload the customs-duty receipt for a shipment ready to dispatch.');
            }
            $po->shipment->update(['customs_duty_paid' => true]);
            $po->update(['customs_duty_paid' => true]);
            Access::audit($po, $actor, 'customs.paid');

            return $po;
        });
    }

    public function markDelivered(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return $this->workflow->transition($po, S::Delivered, $actor);
    }

    public function confirmReceipt(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::StoreStaff, Role::StoreSupervisor);
        throw new InvalidArgumentException('Use the receiving form to record actual quantities and a GRN.');
    }

    public function issueGrn(PurchaseOrder $po, User $actor, string $grnNumber): PurchaseOrder
    {
        Access::roles($actor, Role::StoreStaff, Role::StoreSupervisor);

        return DB::transaction(function () use ($po, $actor, $grnNumber) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== S::Received || ! $po->receipts()->where('grn_number', $grnNumber)->exists() || ! app(ReceivingService::class)->fullyReceived($po)) {
                throw new InvalidArgumentException('Complete Stores receipt and use its recorded GRN number.');
            }
            $po->update(['grn_number' => $grnNumber]);

            return $this->workflow->transition($po, S::GrnIssued, $actor, 'GRN '.$grnNumber);
        });
    }

    public function markServiceableLabelApplied(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::StoreStaff, Role::StoreSupervisor);

        return $this->completeCloseTask($po, $actor, 'serviceable_label_applied_at');
    }

    public function markCardexSent(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return $this->completeCloseTask($po, $actor, 'cardex_sent_at');
    }

    public function closeOut(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($po, $actor) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== S::GrnIssued || ! $po->serviceable_label_applied_at || ! $po->cardex_sent_at || ! app(ReceivingService::class)->handoverComplete($po, 'receipt')) {
                throw new \DomainException('Complete the GRN, verified DigiMaint receipts, serviceable label, and Cardex before closure.');
            }

            return $this->workflow->transition($po, S::Closed, $actor, 'Verified manual DigiMaint receipt and close-out');
        });
    }

    public function closeFulfilledFromStock(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($po, $actor) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== S::FulfilledFromStock || ! app(ReceivingService::class)->handoverComplete($po, 'stock_issue')) {
                throw new \DomainException('Verify the actual DigiMaint stock issue for every line before closure.');
            }

            return $this->workflow->transition($po, S::Closed, $actor, 'Fulfilled from stock; manual issue verified');
        });
    }

    private function completeCloseTask(PurchaseOrder $po, User $actor, string $field): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $field) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== S::GrnIssued || ! app(ReceivingService::class)->handoverComplete($po, 'receipt')) {
                throw new InvalidArgumentException('Issue the GRN and verify the manual DigiMaint receipts first.');
            }
            if ($po->$field) {
                throw new InvalidArgumentException('This close-out action is already recorded.');
            }
            $po->update([$field => now()]);
            Access::audit($po, $actor, 'close_out.'.$field);

            return $po;
        });
    }
}

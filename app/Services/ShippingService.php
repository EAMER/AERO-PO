<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus as S;
use App\Models\PurchaseOrder;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manual 7.1.9-7.1.20: courier vs freight-forwarder shipping, customs duty,
 * receipt, GRN, serviceable label, Cardex, and close-out. The DigiMaint
 * handover at the end is recorded here, not yet integrated with DigiMaint
 * itself (see RequisitionService::checkStock for the entry-side equivalent).
 */
class ShippingService
{
    public function __construct(private WorkflowService $workflow, private DocumentChecklistService $checklist) {}

    /** Logistics records the shipment's weight and DG status - this decides the route. */
    public function recordShipmentDetails(PurchaseOrder $po, User $actor, float $weightKg, bool $containsDg): PurchaseOrder
    {
        if ($weightKg <= 0) {
            throw new InvalidArgumentException('Weight must be greater than zero.');
        }

        $po->update(['total_weight_kg' => $weightKg, 'contains_dg' => $containsDg]);

        return $po->fresh();
    }

    public function isHeavyOrDangerous(PurchaseOrder $po): bool
    {
        return $po->contains_dg || (float) $po->total_weight_kg > 50;
    }

    private function shippingStage(PurchaseOrder $po): string
    {
        return $this->isHeavyOrDangerous($po) ? 'shipping_heavy' : 'shipping_light';
    }

    /** @return list<string> */
    public function missingShippingDocuments(PurchaseOrder $po): array
    {
        return $this->checklist->missing($po, $this->shippingStage($po));
    }

    /**
     * 7.1.9/7.1.11: dispatch the shipment. Requires weight/DG recorded, the
     * route-appropriate documents, customs duty paid (light route only -
     * the manual ties duty to the courier path), and an AWB number.
     */
    public function dispatch(PurchaseOrder $po, User $actor, string $awbNumber): PurchaseOrder
    {
        if (is_null($po->total_weight_kg)) {
            throw new RuntimeException('Record the shipment weight and DG status before dispatch.');
        }

        if (! $this->isHeavyOrDangerous($po) && ! $po->customs_duty_paid) {
            throw new RuntimeException('Customs duty must be paid before a courier shipment can dispatch.');
        }

        $missing = $this->missingShippingDocuments($po);
        if ($missing !== []) {
            throw new RuntimeException('Shipping documents incomplete: '.implode(', ', $missing));
        }

        if (trim($awbNumber) === '') {
            throw new InvalidArgumentException('An AWB number is required.');
        }

        $po->update(['awb_number' => $awbNumber]);

        return $this->workflow->transition($po, S::InTransit, $actor, "Dispatched, AWB {$awbNumber}");
    }

    public function markCustomsDutyPaid(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $po->update(['customs_duty_paid' => true]);

        return $po->fresh();
    }

    public function markDelivered(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return $this->workflow->transition($po, S::Delivered, $actor);
    }

    /** 7.1.15: Stores confirms the item physically arrived. */
    public function confirmReceipt(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return $this->workflow->transition($po, S::Received, $actor, 'Confirmed by stores');
    }

    /** 7.1.17: shipment documents registered and given a GRN number. */
    public function issueGrn(PurchaseOrder $po, User $actor, string $grnNumber): PurchaseOrder
    {
        if (trim($grnNumber) === '') {
            throw new InvalidArgumentException('A GRN number is required.');
        }

        $po->update(['grn_number' => $grnNumber]);

        return $this->workflow->transition($po, S::GrnIssued, $actor, "GRN {$grnNumber}");
    }

    /** 7.1.19: serviceable label prepared and placed on the item. */
    public function markServiceableLabelApplied(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $po->update(['serviceable_label_applied_at' => now()]);

        return $po->fresh();
    }

    /** 7.1.20: Cardex prepared and sent to the end user. */
    public function markCardexSent(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $po->update(['cardex_sent_at' => now()]);

        return $po->fresh();
    }

    /**
     * Close the PO out. This is also the DigiMaint handover point (manual
     * 7.1.18): the item goes back into DigiMaint as available stock. Today
     * that's a manual confirmation, not an API call - see the project's
     * open DigiMaint-integration question.
     */
    public function closeOut(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if (is_null($po->serviceable_label_applied_at)) {
            throw new RuntimeException('Apply the serviceable label before closing the PO.');
        }
        if (is_null($po->cardex_sent_at)) {
            throw new RuntimeException('Send the Cardex before closing the PO.');
        }

        $po->update(['digimaint_synced_at' => now()]);

        return $this->workflow->transition($po, S::Closed, $actor, 'Handed over to DigiMaint');
    }

    /** For lines fulfilled directly from existing stock - no shipping needed at all. */
    public function closeFulfilledFromStock(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $po->update(['digimaint_synced_at' => now()]);

        return $this->workflow->transition($po, S::Closed, $actor, 'Fulfilled from existing stock');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Jobs\SendProcurementMail;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\ReceivingService;
use App\Services\ShippingService;
use App\Services\SourcingService;
use Tests\ProcurementTestCase;

class ShippingAndReceivingTest extends ProcurementTestCase
{
    private function poAtReadyToShip(): PurchaseOrder
    {
        $order = $this->raisedOrder();
        $this->document($order, D::Certificate);
        $order->forceFill(['status' => S::ReadyToShip])->save();

        return $order->fresh();
    }

    private function lightShipment(): PurchaseOrder
    {
        $order = $this->poAtReadyToShip();
        $courier = Vendor::factory()->approved()->create(['kind' => 'courier']);

        return app(ShippingService::class)->recordShipmentDetails($order, $this->person(Role::LogisticsOfficer), 10, false, $courier->id);
    }

    private function completeCourierPack(PurchaseOrder $order): PurchaseOrder
    {
        $this->document($order, D::CustomsDutyReceipt);
        $this->document($order, D::AirwayBill);

        return app(ShippingService::class)->markCustomsDutyPaid($order, $this->person(Role::LogisticsOfficer));
    }

    private function approveFreight(PurchaseOrder $order): PurchaseOrder
    {
        $logistics = $this->person(Role::LogisticsOfficer);
        Vendor::factory()->approved()->create(['kind' => 'freight_forwarder']);
        $rfq = app(SourcingService::class)->sendRfqs($order, $logistics, 'freight')->first();
        (new SendProcurementMail($order->tenant_id, 'rfq', $rfq->id, $logistics->id))->handle();
        $document = $this->document($order, D::FreightQuotation);
        $quote = app(SourcingService::class)->captureFreightQuote($order, $logistics, $rfq->fresh(), ['reference' => 'FREIGHT-1', 'amount' => '100.00', 'currency' => 'USD', 'po_document_id' => $document->id]);
        app(ShippingService::class)->selectFreight($order, $logistics, $quote->id);
        $this->approveSubject($order->fresh()->shipment, 'freight');

        return $order->fresh();
    }

    private function verifiedGrnOrder(): PurchaseOrder
    {
        $order = $this->poAtReadyToShip();
        $order->forceFill(['status' => S::Delivered])->save();
        $this->document($order, D::CommercialInvoice);
        $this->document($order, D::AirwayBill);
        $stores = $this->person(Role::StoreStaff);
        $receipt = app(ReceivingService::class)->receive($order, $stores, 'GRN-003', [$order->lines->first()->id => 2]);
        $order = app(ShippingService::class)->issueGrn($order->fresh(), $stores, 'GRN-003');
        $evidence = $this->document($order, D::DigimaintEvidence);
        $handover = app(ReceivingService::class)->recordHandover($order, $stores, ['activity' => 'receipt', 'goods_receipt_id' => $receipt->id, 'po_line_id' => $order->lines->first()->id, 'quantity' => 2, 'transaction_reference' => 'DM-GRN-003', 'po_document_id' => $evidence->id, 'performed_at' => now()->toIso8601String()]);
        app(ReceivingService::class)->verify($handover, $this->person(Role::StoreSupervisor), true, 'Receipt checked');

        return $order->fresh();
    }

    public function test_light_route_requires_customs_duty_and_its_documents(): void
    {
        $this->beginProcurement();
        $order = $this->lightShipment();
        $this->assertFalse(app(ShippingService::class)->isHeavyOrDangerous($order));
        $this->expectException(\InvalidArgumentException::class);

        app(ShippingService::class)->dispatch($order, $this->person(Role::LogisticsOfficer), 'AWB-1');
    }

    public function test_light_route_dispatch_succeeds_once_complete(): void
    {
        $this->beginProcurement();
        $order = $this->completeCourierPack($this->lightShipment());

        $sent = app(ShippingService::class)->dispatch($order, $this->person(Role::LogisticsOfficer), 'AWB-123');

        $this->assertSame(S::InTransit, $sent->status);
        $this->assertSame('AWB-123', $sent->awb_number);
    }

    public function test_heavy_route_does_not_need_customs_duty_but_needs_commercial_invoice(): void
    {
        $this->beginProcurement();
        $order = $this->poAtReadyToShip();
        $order = app(ShippingService::class)->recordShipmentDetails($order, $this->person(Role::LogisticsOfficer), 80, false);
        $order = $this->approveFreight($order);
        $this->document($order, D::AirwayBill);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('commercial_invoice');

        app(ShippingService::class)->dispatch($order, $this->person(Role::LogisticsOfficer), 'AWB-1');
    }

    public function test_heavy_route_dispatch_succeeds_once_complete(): void
    {
        $this->beginProcurement();
        $order = $this->poAtReadyToShip();
        $order = app(ShippingService::class)->recordShipmentDetails($order, $this->person(Role::LogisticsOfficer), 5, true);
        $order = $this->approveFreight($order);
        $this->document($order, D::CommercialInvoice);
        $this->document($order, D::AirwayBill);

        $sent = app(ShippingService::class)->dispatch($order, $this->person(Role::LogisticsOfficer), 'AWB-999');

        $this->assertSame(S::InTransit, $sent->status);
        $this->assertFalse($sent->customs_duty_paid);
    }

    public function test_full_receiving_flow_reaches_grn_registration(): void
    {
        $this->beginProcurement();
        $order = $this->completeCourierPack($this->lightShipment());
        $this->document($order, D::CommercialInvoice);
        $shipping = app(ShippingService::class);
        $order = $shipping->dispatch($order, $this->person(Role::LogisticsOfficer), 'AWB-1');
        $order = $shipping->markDelivered($order, $this->person(Role::LogisticsOfficer));
        app(ReceivingService::class)->receive($order, $this->person(Role::StoreStaff), 'GRN-001', [$order->lines->first()->id => 2]);

        $order = $shipping->issueGrn($order->fresh(), $this->person(Role::StoreStaff), 'GRN-001');

        $this->assertSame(S::GrnIssued, $order->status);
        $this->assertSame('GRN-001', $order->grn_number);
    }

    public function test_close_out_is_blocked_without_label_and_cardex(): void
    {
        $this->beginProcurement();
        $order = $this->verifiedGrnOrder();
        $this->expectException(\DomainException::class);

        app(ShippingService::class)->closeOut($order, $this->person(Role::LogisticsOfficer));
    }

    public function test_close_out_preserves_the_verified_digimaint_handover(): void
    {
        $this->beginProcurement();
        $order = $this->verifiedGrnOrder();
        $verifiedAt = $order->digimaint_synced_at->toIso8601String();
        app(ShippingService::class)->markServiceableLabelApplied($order, $this->person(Role::StoreStaff));
        app(ShippingService::class)->markCardexSent($order->fresh(), $this->person(Role::LogisticsOfficer));

        $closed = app(ShippingService::class)->closeOut($order->fresh(), $this->person(Role::LogisticsOfficer));

        $this->assertSame(S::Closed, $closed->status);
        $this->assertSame($verifiedAt, $closed->digimaint_synced_at->toIso8601String());
    }

    public function test_an_empty_awb_number_is_rejected(): void
    {
        $this->beginProcurement();
        $order = $this->completeCourierPack($this->lightShipment());
        $this->expectException(\InvalidArgumentException::class);

        app(ShippingService::class)->dispatch($order, $this->person(Role::LogisticsOfficer), ' ');
    }

    public function test_fulfilled_from_stock_closes_without_any_shipping(): void
    {
        $this->beginProcurement();
        $order = $this->stockCheckedOrder(true);
        $document = $this->document($order, D::DigimaintEvidence);
        $handover = app(ReceivingService::class)->recordHandover($order, $this->person(Role::StoreStaff), ['activity' => 'stock_issue', 'po_line_id' => $order->lines->first()->id, 'quantity' => 2, 'transaction_reference' => 'DM-ISSUE-1', 'po_document_id' => $document->id, 'performed_at' => now()->toIso8601String()]);
        app(ReceivingService::class)->verify($handover, $this->person(Role::StoreSupervisor), true, 'Stock issued to requester');

        $closed = app(ShippingService::class)->closeFulfilledFromStock($order->fresh(), $this->person(Role::LogisticsOfficer));

        $this->assertSame(S::Closed, $closed->status);
        $this->assertNotNull($closed->digimaint_synced_at);
        $this->assertNull($closed->shipment);
    }
}

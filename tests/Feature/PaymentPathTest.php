<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Services\PaymentService;
use App\Services\RequisitionService;
use Tests\ProcurementTestCase;

class PaymentPathTest extends ProcurementTestCase
{
    private function poAtPoSent(?string $terms = null): PurchaseOrder
    {
        $order = $this->commercialFixture($this->raisedOrder());
        $order->forceFill(['status' => S::PoSent, 'payment_terms' => $terms, 'po_approved_at' => now(), 'issued_at' => now()])->save();

        return $order->fresh();
    }

    private function acknowledged(string $terms): PurchaseOrder
    {
        $order = $this->poAtPoSent($terms);
        $this->document($order, D::AcknowledgedPo);

        return app(RequisitionService::class)->markAcknowledged($order, $this->person(Role::LogisticsOfficer));
    }

    private function approvedCiaInvoice(PurchaseOrder $order): Invoice
    {
        $document = $this->document($order, D::ProformaInvoice);
        $invoice = app(PaymentService::class)->prepare($order, $this->person(Role::LogisticsOfficer), ['reference' => 'PFI-1', 'currency' => 'USD', 'amount' => '20.00', 'po_document_id' => $document->id]);
        app(PaymentService::class)->submit($invoice, $this->person(Role::LogisticsOfficer));
        $this->approveSubject($invoice, 'payment_cia');

        return $invoice->fresh();
    }

    public function test_payment_terms_must_be_net_or_cia(): void
    {
        $this->beginProcurement();
        $order = $this->poAtPoSent();
        $this->expectException(\InvalidArgumentException::class);

        app(RequisitionService::class)->setPaymentTerms($order, $this->person(Role::LogisticsOfficer), 'crypto');
    }

    public function test_acknowledgement_is_blocked_without_payment_terms(): void
    {
        $this->beginProcurement();
        $order = $this->poAtPoSent();
        $this->document($order, D::AcknowledgedPo);
        $this->expectException(\DomainException::class);

        app(RequisitionService::class)->markAcknowledged($order, $this->person(Role::LogisticsOfficer));
    }

    public function test_acknowledgement_is_blocked_without_the_document(): void
    {
        $this->beginProcurement();
        $order = $this->poAtPoSent('net');
        $this->expectException(\DomainException::class);

        app(RequisitionService::class)->markAcknowledged($order, $this->person(Role::LogisticsOfficer));
    }

    public function test_net_terms_goes_straight_to_ready_to_ship(): void
    {
        $this->beginProcurement();
        $order = $this->acknowledged('net');

        $ready = app(RequisitionService::class)->markReadyToShipOnNetTerms($order, $this->person(Role::LogisticsOfficer));

        $this->assertSame(S::ReadyToShip, $ready->status);
        $this->assertNull($ready->invoice);
    }

    public function test_net_terms_po_cannot_use_the_cia_payment_path(): void
    {
        $this->beginProcurement();
        $order = $this->acknowledged('net');
        $this->expectException(\DomainException::class);

        app(RequisitionService::class)->proceedToPayment($order, $this->person(Role::LogisticsOfficer));
    }

    public function test_cia_path_requires_proforma_and_telex_before_confirming_payment(): void
    {
        $this->beginProcurement();
        $order = $this->acknowledged('cia');
        $invoice = $this->approvedCiaInvoice($order);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('telex');

        app(PaymentService::class)->confirm($invoice, $this->person(Role::Treasury), 'PAYMENT-1');
    }

    public function test_cia_path_reaches_ready_to_ship_once_payment_is_confirmed(): void
    {
        $this->beginProcurement();
        $order = $this->acknowledged('cia');
        $invoice = $this->approvedCiaInvoice($order);
        $this->document($order->fresh(), D::TelexCopy);

        app(PaymentService::class)->confirm($invoice, $this->person(Role::Treasury), 'PAYMENT-1');

        $this->assertSame(S::ReadyToShip, $order->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }
}

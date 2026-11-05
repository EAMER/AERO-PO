<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Jobs\SendProcurementMail;
use App\Mail\ProcurementMessage;
use App\Models\FreightQuote;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\QuoteEvaluation;
use App\Models\Shipment;
use App\Models\Vendor;
use App\Services\ApprovalService;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use App\Services\ReceivingService;
use App\Services\RequisitionService;
use App\Services\ShippingService;
use App\Services\SourcingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ProcurementTestCase;

class ProcurementWorkflowTest extends ProcurementTestCase
{
    public function test_newly_approved_vendors_can_be_solicited_without_resending_existing_rfqs(): void
    {
        $this->beginProcurement();
        $order = $this->stockCheckedOrder();
        $logistics = $this->person(Role::LogisticsOfficer);
        Vendor::factory()->approved()->count(2)->create();
        foreach (app(SourcingService::class)->sendRfqs($order, $logistics) as $rfq) {
            (new SendProcurementMail($order->tenant_id, 'rfq', $rfq->id, $logistics->id))->handle();
        }
        $third = Vendor::factory()->approved()->create();

        $additional = app(SourcingService::class)->sendRfqs($order->fresh(), $logistics);

        $this->assertCount(1, $additional);
        $this->assertSame($third->id, $additional->first()->vendor_id);
        $this->assertSame(3, $order->rfqs()->count());
        Mail::assertSentCount(2);
        Queue::assertPushed(SendProcurementMail::class, 3);
    }

    public function test_withdrawing_an_unsent_rf_q_requires_a_vendor_whose_approval_was_withdrawn(): void
    {
        $this->beginProcurement();
        $order = $this->stockCheckedOrder();
        $logistics = $this->person(Role::LogisticsOfficer);
        Vendor::factory()->approved()->count(2)->create();
        $rfqs = app(SourcingService::class)->sendRfqs($order, $logistics);
        (new SendProcurementMail($order->tenant_id, 'rfq', $rfqs[0]->id, $logistics->id))->handle();
        $rfqs[1]->vendor->update(['is_approved' => false]);

        $this->actingAs($logistics)->post($this->address('/rfqs/'.$rfqs[1]->id.'/withdraw'), ['reason' => 'Vendor approval withdrawn during sourcing'])->assertRedirect()->assertSessionHasNoErrors();
        (new SendProcurementMail($order->tenant_id, 'rfq', $rfqs[1]->id, $logistics->id))->handle();

        $this->assertSame('withdrawn', $rfqs[1]->fresh()->status);
        $this->assertSame(S::RfqSent, $order->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_returned_freight_can_be_repriced_without_rewriting_the_previous_approval_snapshot(): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::ReadyToShip]);
        $shipment = Shipment::factory()->create(['purchase_order_id' => $order->id, 'route' => 'freight_forwarder', 'weight_kg' => 50]);
        $quote = FreightQuote::factory()->create(['shipment_id' => $shipment->id]);
        $quote->rfq->update(['status' => 'sent']);
        $logistics = $this->person(Role::LogisticsOfficer);
        app(ShippingService::class)->selectFreight($order, $logistics, $quote->id);
        $first = $shipment->approvals()->first();
        app(ApprovalService::class)->returnForCorrection($first, $this->person(Role::Tlm), 'Negotiate the freight price');
        $document = $this->document($order, D::FreightQuotation);
        app(SourcingService::class)->captureFreightQuote($order, $logistics, $quote->rfq->fresh(), ['reference' => 'FREIGHT-REVISED', 'amount' => '60.00', 'currency' => 'USD', 'po_document_id' => $document->id, 'reason' => 'Negotiated revised freight']);

        app(ShippingService::class)->selectFreight($order->fresh(), $logistics, $quote->id);

        $second = $shipment->approvals()->where('round', 2)->first();
        $this->assertSame(5000, data_get($first->fresh()->snapshot, 'data.freight_minor'));
        $this->assertSame(6000, data_get($second->snapshot, 'data.freight_minor'));
        $this->assertSame('returned', $first->fresh()->decision->value);
        $this->assertDatabaseHas('amount_changes', ['purchase_order_id' => $order->id, 'subject_type' => FreightQuote::class, 'subject_id' => $quote->id, 'action' => 'freight_quote.priced']);
    }

    public function test_logistics_can_capture_and_submit_the_structured_evaluation_through_http(): void
    {
        $this->beginProcurement();
        $order = $this->quotedOrder();
        $quote = $order->quotes()->first();
        $this->actingAs($this->person(Role::LogisticsOfficer));

        $this->post($this->address('/requisitions/'.$order->id.'/actions/quote'), ['rfq_id' => $quote->rfq_id, 'po_document_id' => $quote->po_document_id, 'reference' => 'Q-REVISED', 'currency' => 'USD', 'reason' => 'Negotiated revised warranty and price', 'lines' => [['po_line_id' => $order->lines->first()->id, 'unit_price' => '12.34', 'condition' => 'new', 'warranty' => '24 months']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2468, $quote->fresh()->total_minor);
        foreach ([D::EndUserDocument, D::EvaluationSheet, D::ProvisioningRequisition, D::NegotiationEmail, D::Certificate] as $type) {
            $this->document($order->fresh(), $type);
        }
        $this->post($this->address('/requisitions/'.$order->id.'/actions/evaluate'), ['selected_quote_id' => $quote->id, 'justification' => 'Selected extended warranty'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->address('/requisitions/'.$order->id.'/submit'))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(S::PendingApproval, $order->fresh()->status);
        $this->assertSame(2468, $order->fresh()->total_minor);
        $this->assertDatabaseHas('amount_changes', ['subject_id' => $quote->id, 'subject_type' => Quote::class, 'reason' => 'Negotiated revised warranty and price']);
        $this->actingAs($this->person(Role::Tlm))->get($this->address('/requisitions/'.$order->id))->assertOk()->assertSee('24 months')->assertSee('Quote comparison');
    }

    public function test_invoice_submission_and_payment_confirmation_are_wired_to_the_forms(): void
    {
        $this->beginProcurement();
        $order = $this->commercialFixture($this->raisedOrder());
        $order->forceFill(['status' => S::Acknowledged, 'payment_terms' => 'cia', 'po_approved_at' => now()])->save();
        $document = $this->document($order, D::ProformaInvoice);
        $this->actingAs($this->person(Role::LogisticsOfficer));

        $this->post($this->address('/requisitions/'.$order->id.'/actions/invoice'), ['reference' => 'PFI-HTTP', 'po_document_id' => $document->id, 'amount' => '20.00', 'currency' => 'USD', 'reason' => 'Invoice received'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->address('/requisitions/'.$order->id.'/actions/submit-invoice'))->assertRedirect()->assertSessionHasNoErrors();
        $invoice = $order->invoice()->firstOrFail();
        $this->approveSubject($invoice, 'payment_cia');
        $this->actingAs($this->person(Role::Treasury))->get($this->address('/dashboard'))->assertOk()->assertSee('PFI-HTTP');
        $this->post($this->address('/requisitions/'.$order->id.'/documents'), ['type' => 'telex_copy', 'file' => UploadedFile::fake()->create('telex.pdf', 1)])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->address('/requisitions/'.$order->id.'/actions/pay'), ['payment_reference' => 'TRANSFER-HTTP'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('TRANSFER-HTTP', $invoice->fresh()->payment_reference);
        $this->assertSame(S::ReadyToShip, $order->fresh()->status);
    }

    public function test_net_order_can_be_received_paid_and_closed_after_verified_manual_handover(): void
    {
        $this->beginProcurement();
        $order = $this->acknowledgedOrder();
        $logistics = $this->person(Role::LogisticsOfficer);
        $stores = $this->person(Role::StoreStaff);
        $shipping = app(ShippingService::class);
        $order = app(RequisitionService::class)->markReadyToShipOnNetTerms($order, $logistics);
        $carrier = Vendor::factory()->approved()->create(['kind' => 'courier']);
        $shipping->recordShipmentDetails($order, $logistics, 10, false, $carrier->id);
        foreach ([D::CustomsDutyReceipt, D::AirwayBill, D::CommercialInvoice] as $type) {
            $this->document($order->fresh(), $type);
        }
        $shipping->markCustomsDutyPaid($order->fresh(), $logistics);
        $order = $shipping->dispatch($order->fresh(), $logistics, 'AWB-001');
        $order = $shipping->markDelivered($order, $logistics);
        $receipt = app(ReceivingService::class)->receive($order, $stores, 'GRN-001', [$order->lines->first()->id => 2]);
        $order->refresh();
        $invoiceDocument = $order->documents()->where('type', D::CommercialInvoice)->first();
        $invoice = app(PaymentService::class)->prepare($order, $this->person(Role::Payables), ['reference' => 'NET-INV-1', 'currency' => 'USD', 'amount' => '20.00', 'po_document_id' => $invoiceDocument->id]);
        app(PaymentService::class)->submit($invoice, $this->person(Role::Payables));
        $this->approveSubject($invoice, 'payment_net');
        app(PaymentService::class)->confirm($invoice->fresh(), $this->person(Role::Treasury), 'BANK-NET-1');
        $order = $shipping->issueGrn($order->fresh(), $stores, 'GRN-001');
        $evidence = $this->document($order, D::DigimaintEvidence);
        $handover = app(ReceivingService::class)->recordHandover($order, $stores, ['activity' => 'receipt', 'goods_receipt_id' => $receipt->id, 'po_line_id' => $order->lines->first()->id, 'quantity' => 2, 'transaction_reference' => 'DM-RECEIPT-1', 'po_document_id' => $evidence->id, 'performed_at' => now()->toIso8601String()]);
        app(ReceivingService::class)->verify($handover, $this->person(Role::StoreSupervisor), true, 'GRN and DigiMaint receipt agree');
        $shipping->markServiceableLabelApplied($order->fresh(), $stores);
        $shipping->markCardexSent($order->fresh(), $logistics);

        $closed = $shipping->closeOut($order->fresh(), $logistics);

        $this->assertSame(S::Closed, $closed->status);
        $this->assertNotNull($closed->digimaint_synced_at);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(6, $invoice->steps()->where('decision', 'approved')->count());
        $this->assertSame('PR-1', $closed->requisition->reference);
        Mail::assertSent(ProcurementMessage::class, fn ($mail) => $mail->pdfPath !== null);
        Queue::assertPushed(SendProcurementMail::class, fn ($job) => $job->kind === 'purchase_order' && $job->tenantId === $order->tenant_id);
        $this->assertStringStartsWith('%PDF-', Storage::disk('private')->get($closed->pdf_path));
        $this->actingAs($logistics)->get($this->address('/requisitions/'.$order->id))->assertOk()->assertSee('GRN-001');
        $this->get($this->address('/requisitions/'.$order->id.'/pdf'))->assertOk()->assertDownload('purchase-order-'.$order->id.'.pdf');
    }

    public function test_cia_requires_all_finance_approvals_and_treasury_confirmation(): void
    {
        $this->beginProcurement();
        $order = $this->acknowledgedOrder('cia');
        $document = $this->document($order, D::ProformaInvoice);
        $invoice = app(PaymentService::class)->prepare($order, $this->person(Role::LogisticsOfficer), ['reference' => 'PFI-1', 'currency' => 'USD', 'amount' => '20.00', 'po_document_id' => $document->id]);

        app(PaymentService::class)->submit($invoice, $this->person(Role::LogisticsOfficer));

        $this->assertSame(S::AwaitingPayment, $order->fresh()->status);
        $this->assertSame(['cost_control', 'tlm', 'technical_director', 'internal_audit', 'payables', 'treasury'], $invoice->steps()->orderBy('step_order')->pluck('role')->map(fn ($role) => $role->value)->all());
        $this->approveSubject($invoice->fresh(), 'payment_cia');
        $this->assertSame(S::AwaitingPayment, $order->fresh()->status);
        $this->document($order->fresh(), D::TelexCopy);
        app(PaymentService::class)->confirm($invoice->fresh(), $this->person(Role::Treasury), 'BANK-CIA-1');
        $this->assertSame(S::ReadyToShip, $order->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_cfo_approval_does_not_claim_the_po_was_sent(): void
    {
        $this->beginProcurement();
        $order = $this->pendingEvaluation();
        $this->approveSubject($order->evaluations()->first(), 'evaluation');
        $reviewPdf = $order->fresh()->pdf_path;

        $this->approveSubject($order->fresh(), 'cfo');

        $this->assertSame(S::PoApproved, $order->fresh()->status);
        $this->assertNull($order->fresh()->issued_at);
        Storage::disk('private')->assertExists($order->fresh()->pdf_path);
        Storage::disk('private')->assertExists($reviewPdf);
        $this->assertNotSame($reviewPdf, $order->fresh()->pdf_path);
        Mail::assertSentCount(3);
    }

    public function test_returned_evaluation_preserves_old_decisions_and_creates_a_new_revision(): void
    {
        $this->beginProcurement();
        $order = $this->pendingEvaluation('net', true);
        $original = $order->evaluations()->first();
        $step = $original->approvals()->where('step_order', 1)->first();

        app(ApprovalService::class)->returnForCorrection($step, $this->person(Role::Tlm), 'Clarify the selected warranty');

        $this->assertSame(S::ReturnedEvaluation, $order->fresh()->status);
        $this->assertSame(0, $original->approvals()->where('is_active', true)->count());
        $order = app(PurchaseOrderService::class)->reopenForCorrection($order->fresh(), $this->person(Role::LogisticsOfficer));
        app(SourcingService::class)->evaluate($order, $this->person(Role::LogisticsOfficer), $original->selected_quote_id, 'Warranty clarified with end user');
        app(RequisitionService::class)->submitForApproval($order->fresh(), $this->person(Role::LogisticsOfficer));
        $this->assertSame(2, $order->fresh()->revision);
        $this->assertSame('returned', $step->fresh()->decision->value);
        $this->assertSame('Clarify the selected warranty', $step->fresh()->note);
        $this->assertDatabaseHas('approvals', ['approvable_id' => $order->evaluations()->where('revision', 2)->first()->id, 'approvable_type' => QuoteEvaluation::class, 'role' => 'hamo', 'is_active' => true]);
    }

    public function test_a_cfo_return_requires_a_new_evaluation_and_preserves_the_reviewed_pdf(): void
    {
        $this->beginProcurement();
        $order = $this->pendingEvaluation();
        $original = $order->evaluations()->first();
        $this->approveSubject($original, 'evaluation');
        $reviewedPath = $order->fresh()->pdf_path;
        $cfo = $order->approvals()->where('stage', 'cfo')->firstOrFail();

        app(ApprovalService::class)->returnForCorrection($cfo, $this->person(Role::Cfo), 'Clarify the commercial justification');

        $this->assertSame(S::ReturnedCfo, $order->fresh()->status);
        $revised = app(PurchaseOrderService::class)->reopenForCorrection($order->fresh(), $this->person(Role::LogisticsOfficer));
        $this->assertNull($revised->po_approved_at);
        $this->assertNull($revised->pdf_path);
        Storage::disk('private')->assertExists($reviewedPath);
        app(SourcingService::class)->evaluate($revised, $this->person(Role::LogisticsOfficer), $original->selected_quote_id, 'Commercial justification clarified with requester');
        app(RequisitionService::class)->submitForApproval($revised->fresh(), $this->person(Role::LogisticsOfficer));

        $this->assertSame(S::PendingApproval, $revised->fresh()->status);
        $this->assertSame('returned', $cfo->fresh()->decision->value);
        $this->assertCount(0, app(ApprovalService::class)->actionableFor($this->person(Role::Cfo)));
    }

    public function test_returned_invoice_restarts_the_finance_chain_and_retains_previous_decisions(): void
    {
        $this->beginProcurement();
        $order = $this->acknowledgedOrder('cia');
        $document = $this->document($order, D::ProformaInvoice);
        $logistics = $this->person(Role::LogisticsOfficer);
        $data = ['reference' => 'PFI-RETURN', 'currency' => 'USD', 'amount' => '20.00', 'po_document_id' => $document->id];
        $invoice = app(PaymentService::class)->prepare($order, $logistics, $data);
        app(PaymentService::class)->submit($invoice, $logistics);
        $first = $invoice->approvals()->where('step_order', 1)->firstOrFail();
        app(ApprovalService::class)->approve($first, $this->person(Role::CostControl));
        $tlm = $invoice->approvals()->where('step_order', 2)->firstOrFail();

        app(ApprovalService::class)->returnForCorrection($tlm, $this->person(Role::Tlm), 'Correct the invoice reference');
        $data['reference'] = 'PFI-CORRECTED';
        $revised = app(PaymentService::class)->prepare($order->fresh(), $logistics, $data);
        app(PaymentService::class)->submit($revised, $logistics);

        $this->assertSame(2, $revised->fresh()->revision);
        $this->assertSame('approved', $first->fresh()->decision->value);
        $this->assertSame('returned', $tlm->fresh()->decision->value);
        $this->assertSame(6, $revised->steps()->where('round', 2)->where('decision', 'pending')->count());
        $this->assertCount(0, app(ApprovalService::class)->actionableFor($this->person(Role::Tlm)));
        $this->approveSubject($revised, 'payment_cia');
        $this->assertSame('approved', $revised->fresh()->status);
        $this->assertSame(S::AwaitingPayment, $order->fresh()->status);
    }

    public function test_rejection_removes_later_approvers_from_their_queues(): void
    {
        $this->beginProcurement();
        $order = $this->pendingEvaluation('net', true);
        $step = $order->evaluations()->first()->approvals()->where('step_order', 1)->first();

        app(ApprovalService::class)->reject($step, $this->person(Role::Tlm), 'Commercial pack rejected');

        $this->assertSame(S::Rejected, $order->fresh()->status);
        $this->assertCount(0, app(ApprovalService::class)->actionableFor($this->person(Role::Hamo)));
    }

    public function test_three_document_uploads_do_not_replace_three_distinct_vendor_quotes(): void
    {
        $this->beginProcurement();
        $order = $this->quotedOrder();
        $order->quotes()->latest('id')->first()->delete();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least three distinct approved vendors');

        app(SourcingService::class)->evaluate($order, $this->person(Role::LogisticsOfficer), $order->quotes()->first()->id, 'Select vendor');
    }

    public function test_net_terms_are_ranked_before_a_cheaper_cia_quote(): void
    {
        $this->beginProcurement();
        $order = $this->quotedOrder('cia');
        $net = $order->quotes()->orderByDesc('total_minor')->first();
        $net->vendor->update(['payment_terms' => 'net']);
        $net->update(['payment_terms' => 'net']);

        $ranked = app(SourcingService::class)->comparison($order);

        $this->assertSame($net->id, $ranked->first()->id);
        $this->assertSame(2400, $ranked->first()->total_minor);
    }

    public static function shipmentRoutes(): array
    {
        return ['below threshold' => [49.99, false, 'courier'], 'exact threshold' => [50.0, false, 'freight_forwarder'], 'dangerous goods' => [0.1, true, 'freight_forwarder']];
    }

    #[DataProvider('shipmentRoutes')]
    public function test_shipping_uses_the_required_weight_and_dg_route(float $weight, bool $dg, string $route): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::ReadyToShip]);
        $courier = Vendor::factory()->approved()->create(['kind' => 'courier']);

        app(ShippingService::class)->recordShipmentDetails($order, $this->person(Role::LogisticsOfficer), $weight, $dg, $courier->id);

        $this->assertSame($route, $order->fresh()->shipment->route);
    }

    public function test_freight_dispatch_requires_a_selected_quote_and_tlm_approval(): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::ReadyToShip]);
        PoLine::factory()->create(['purchase_order_id' => $order->id]);
        $logistics = $this->person(Role::LogisticsOfficer);
        app(ShippingService::class)->recordShipmentDetails($order, $logistics, 50, false);
        Vendor::factory()->approved()->create(['kind' => 'freight_forwarder']);
        $rfq = app(SourcingService::class)->sendRfqs($order, $logistics, 'freight')->first();
        (new SendProcurementMail($order->tenant_id, 'rfq', $rfq->id, $logistics->id))->handle();
        $document = $this->document($order->fresh(), D::FreightQuotation);
        $quote = app(SourcingService::class)->captureFreightQuote($order->fresh(), $logistics, $rfq->fresh(), ['reference' => 'FQ-1', 'currency' => 'USD', 'amount' => '100.00', 'po_document_id' => $document->id]);
        app(ShippingService::class)->selectFreight($order->fresh(), $logistics, $quote->id);
        $this->assertSame('pending_approval', $order->fresh()->shipment->status);
        $this->approveSubject($order->fresh()->shipment, 'freight');
        $this->document($order->fresh(), D::CommercialInvoice);
        $this->document($order->fresh(), D::AirwayBill);

        app(ShippingService::class)->dispatch($order->fresh(), $logistics, 'FREIGHT-AWB');

        $this->assertSame(S::InTransit, $order->fresh()->status);
        $this->assertSame(10000, $order->fresh()->shipment->freight_minor);
    }
}

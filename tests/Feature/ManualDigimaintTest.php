<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\DigimaintHandover;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Services\ReceivingService;
use App\Services\RequisitionService;
use App\Services\ShippingService;
use Tests\ProcurementTestCase;

class ManualDigimaintTest extends ProcurementTestCase
{
    public function test_manual_action_times_preserve_the_actual_instant_from_the_entered_offset(): void
    {
        $this->beginProcurement();
        $this->travelTo('2026-11-05 08:30:00 UTC');
        $order = $this->raisedOrder();
        $document = $this->document($order, D::DigimaintEvidence);

        $handover = app(ReceivingService::class)->recordHandover($order, $this->person(Role::StoreStaff), ['activity' => 'stock_check', 'po_line_id' => $order->lines->first()->id, 'transaction_reference' => 'DM-TIME', 'quantity' => 0, 'po_document_id' => $document->id, 'performed_at' => '2026-11-05T09:00:00+01:00']);

        $this->assertSame('2026-11-05 08:00:00', $handover->fresh()->performed_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_an_unverified_stock_check_cannot_complete_stores_stock_assessment(): void
    {
        $this->beginProcurement();
        $order = $this->raisedOrder();
        $evidence = $this->document($order, D::DigimaintEvidence);
        app(ReceivingService::class)->recordHandover($order, $this->person(Role::StoreStaff), ['activity' => 'stock_check', 'po_line_id' => $order->lines->first()->id, 'transaction_reference' => 'UNVERIFIED-CHECK', 'quantity' => 0, 'po_document_id' => $evidence->id, 'performed_at' => now()->toIso8601String()]);

        $this->actingAs($this->person(Role::StoreStaff))->postJson($this->address('/requisitions/'.$order->id.'/check-stock'), ['available' => []])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertSame(S::RequisitionRaised, $order->fresh()->status);
    }

    public function test_a_verified_stock_recheck_can_route_an_unissued_request_back_to_sourcing(): void
    {
        $this->beginProcurement();
        $order = $this->stockCheckedOrder(true);
        $evidence = $this->document($order, D::DigimaintEvidence);
        $handover = app(ReceivingService::class)->recordHandover($order, $this->person(Role::StoreStaff), ['activity' => 'stock_check', 'po_line_id' => $order->lines->first()->id, 'transaction_reference' => 'DM-CHECK-1', 'quantity' => 0, 'po_document_id' => $evidence->id, 'performed_at' => now()->toIso8601String()]);
        app(ReceivingService::class)->verify($handover, $this->person(Role::StoreSupervisor), true, 'Stock is now short');

        $rechecked = app(RequisitionService::class)->checkStock($order->fresh(), $this->person(Role::StoreStaff), [$order->lines->first()->id => false]);

        $this->assertSame(S::StockChecked, $rechecked->status);
        $this->assertFalse($rechecked->lines->first()->stock_available);
        $this->assertSame(2, $order->handovers()->where('activity', 'stock_check')->count());
    }

    private function deliveredOrder(): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create();
        PoLine::factory()->create(['purchase_order_id' => $order->id, 'quantity' => 2]);
        $this->document($order, D::Certificate);
        $order->forceFill(['status' => S::Delivered])->save();
        $this->document($order, D::CommercialInvoice);
        $this->document($order, D::AirwayBill);

        return $order->fresh();
    }

    public function test_partial_receipts_keep_the_order_open_until_all_quantities_arrive(): void
    {
        $this->beginProcurement();
        $order = $this->deliveredOrder();
        $line = $order->lines->first();
        $service = app(ReceivingService::class);

        $first = $service->receive($order, $this->person(Role::StoreStaff), 'GRN-PART-1', [$line->id => 1]);

        $this->assertSame(S::Delivered, $order->fresh()->status);
        $this->assertSame(1, $first->lines->first()->quantity);
        $service->receive($order->fresh(), $this->person(Role::StoreStaff), 'GRN-PART-2', [$line->id => 1]);
        $this->assertSame(S::Received, $order->fresh()->status);
        $this->assertSame(2, (int) $service->receivedQuantities($order)[$line->id]);
    }

    public function test_over_receipt_is_rejected_without_creating_a_grn(): void
    {
        $this->beginProcurement();
        $order = $this->deliveredOrder();

        $this->actingAs($this->person(Role::StoreStaff))->postJson($this->address('/requisitions/'.$order->id.'/actions/receive'), ['grn_number' => 'EXCESS-GRN', 'quantities' => [$order->lines->first()->id => 3]])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertSame(0, $order->receipts()->count());
        $this->assertSame(S::Delivered, $order->fresh()->status);
    }

    public function test_missing_receiving_documents_block_receipt(): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::Delivered]);
        $line = PoLine::factory()->create(['purchase_order_id' => $order->id]);

        $this->actingAs($this->person(Role::StoreStaff))->postJson($this->address('/requisitions/'.$order->id.'/actions/receive'), ['grn_number' => 'NO-DOCS', 'quantities' => [$line->id => 2]])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertDatabaseMissing('goods_receipts', ['grn_number' => 'NO-DOCS']);
    }

    public function test_the_operator_cannot_verify_their_own_manual_posting(): void
    {
        $this->beginProcurement();
        $supervisor = $this->person(Role::StoreSupervisor);
        $handover = DigimaintHandover::factory()->create(['recorded_by' => $supervisor->id]);

        $this->actingAs($supervisor)->postJson($this->address('/digimaint-handovers/'.$handover->id.'/verify'), ['verified' => true, 'note' => 'Self verification'])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertSame('recorded', $handover->fresh()->status);
        $this->assertNull($handover->fresh()->verified_at);
    }

    public function test_closure_does_not_manufacture_a_digimaint_confirmation(): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::GrnIssued, 'grn_number' => 'GRN-1', 'serviceable_label_applied_at' => now(), 'cardex_sent_at' => now()]);

        $this->actingAs($this->person(Role::LogisticsOfficer))->postJson($this->address('/requisitions/'.$order->id.'/close'))->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertNull($order->fresh()->digimaint_synced_at);
        $this->assertSame(S::GrnIssued, $order->fresh()->status);
    }

    public function test_stock_fulfilment_records_an_issue_without_creating_a_receipt(): void
    {
        $this->beginProcurement();
        $this->travelTo('2026-11-05 08:00:00 UTC');
        $order = $this->stockCheckedOrder(true);
        $document = $this->document($order, D::DigimaintEvidence);
        $handover = app(ReceivingService::class)->recordHandover($order, $this->person(Role::StoreStaff), ['activity' => 'stock_issue', 'po_line_id' => $order->lines->first()->id, 'transaction_reference' => 'DM-ISSUE-1', 'po_document_id' => $document->id, 'quantity' => 2, 'performed_at' => now()->toIso8601String()]);
        app(ReceivingService::class)->verify($handover, $this->person(Role::StoreSupervisor), true, 'Issue and end-user handover checked');
        $this->travel(2)->hours();

        $closed = app(ShippingService::class)->closeFulfilledFromStock($order->fresh(), $this->person(Role::LogisticsOfficer));

        $this->assertSame(S::Closed, $closed->status);
        $this->assertSame('2026-11-05 08:00:00', $closed->digimaint_synced_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $closed->receipts()->count());
        $this->assertSame(0, $closed->shipment()->count());
    }

    public function test_duplicate_manual_receipt_is_rejected(): void
    {
        $this->beginProcurement();
        $order = $this->deliveredOrder();
        $receipt = app(ReceivingService::class)->receive($order, $this->person(Role::StoreStaff), 'GRN-DUP', [$order->lines->first()->id => 2]);
        $document = $this->document($order->fresh(), D::DigimaintEvidence);
        $payload = ['activity' => 'receipt', 'goods_receipt_id' => $receipt->id, 'po_line_id' => $order->lines->first()->id, 'transaction_reference' => 'DM-RECEIPT-DUP', 'po_document_id' => $document->id, 'quantity' => 2, 'performed_at' => now()->toIso8601String()];
        app(ReceivingService::class)->recordHandover($order->fresh(), $this->person(Role::StoreStaff), $payload);

        $this->actingAs($this->person(Role::StoreStaff))->postJson($this->address('/requisitions/'.$order->id.'/actions/handover'), $payload)->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertSame(1, $order->handovers()->count());
    }

    public function test_exception_correction_preserves_the_original_posting_evidence(): void
    {
        $this->beginProcurement();
        $order = $this->deliveredOrder();
        $receipt = app(ReceivingService::class)->receive($order, $this->person(Role::StoreStaff), 'GRN-FIX', [$order->lines->first()->id => 2]);
        $document = $this->document($order->fresh(), D::DigimaintEvidence);
        $payload = ['activity' => 'receipt', 'goods_receipt_id' => $receipt->id, 'po_line_id' => $order->lines->first()->id, 'transaction_reference' => 'DM-ORIGINAL', 'po_document_id' => $document->id, 'quantity' => 2, 'performed_at' => now()->toIso8601String()];
        $original = app(ReceivingService::class)->recordHandover($order->fresh(), $this->person(Role::StoreStaff), $payload);
        app(ReceivingService::class)->verify($original, $this->person(Role::StoreSupervisor), false, 'Correct the DigiMaint posting reference');
        $payload['transaction_reference'] = 'DM-CORRECTED';

        $corrected = app(ReceivingService::class)->recordHandover($order->fresh(), $this->person(Role::StoreStaff), $payload);

        $this->assertSame('DM-ORIGINAL', $original->fresh()->transaction_reference);
        $this->assertSame('superseded', $original->fresh()->status);
        $this->assertSame($original->id, $corrected->supersedes_id);
        $this->assertNull($order->fresh()->digimaint_synced_at);
        app(ReceivingService::class)->verify($corrected, $this->person(Role::StoreSupervisor), true, 'Corrected entry checked');
        $this->assertNotNull($order->fresh()->digimaint_synced_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'digimaint.exception', 'purchase_order_id' => $order->id]);
    }

    public function test_failed_grn_action_leaves_the_order_metadata_unchanged(): void
    {
        $this->beginProcurement();
        $order = $this->raisedOrder();

        $this->actingAs($this->person(Role::StoreStaff))->postJson($this->address('/requisitions/'.$order->id.'/issue-grn'), ['grn_number' => 'PREMATURE'])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertNull($order->fresh()->grn_number);
        $this->assertSame(0, $order->logs()->count());
    }
}

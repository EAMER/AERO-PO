<?php

namespace Tests;

use App\Enums\DocumentType;
use App\Models\DigimaintHandover;
use App\Models\PoDocument;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Services\RequisitionService;
use App\Services\SourcingService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function checkedStock(RequisitionService $service, PurchaseOrder $order, User $actor, array $availability): PurchaseOrder
    {
        foreach ($availability as $lineId => $available) {
            $line = $order->lines()->findOrFail($lineId);
            $evidence = PoDocument::factory()->create(['purchase_order_id' => $order->id, 'type' => DocumentType::DigimaintEvidence]);
            DigimaintHandover::factory()->create(['purchase_order_id' => $order->id, 'po_line_id' => $line->id, 'po_document_id' => $evidence->id, 'recorded_by' => $actor->id, 'quantity' => $available ? $line->quantity : 0, 'status' => 'verified']);
        }

        return $service->checkStock($order, $actor, $availability);
    }

    protected function commercialFixture(PurchaseOrder $order): PurchaseOrder
    {
        $vendor = Vendor::factory()->approved()->create();
        if (! $order->lines()->exists()) {
            PoLine::factory()->create(['purchase_order_id' => $order->id]);
        }
        $order->refresh();
        $snapshot = ['revision' => 1, 'reference' => $order->reference, 'company' => $order->tenant->name, 'vendor' => $vendor->only(['id', 'name', 'email', 'address']), 'currency' => 'USD', 'payment_terms' => 'net', 'total_minor' => 2000, 'lines' => $order->lines->map->only(['id', 'part_number', 'description', 'quantity', 'condition', 'warranty', 'unit_price_minor'])->all()];
        $order->update(['vendor_id' => $vendor->id, 'commercial_snapshot' => $snapshot, 'currency' => 'USD', 'total_minor' => 2000]);

        return $order->fresh();
    }

    protected function evaluationFixture(PurchaseOrder $order, User $logistics): void
    {
        $quoteIds = [];
        foreach ($order->documents()->where('type', DocumentType::Quotation)->limit(3)->get() as $document) {
            $vendor = Vendor::factory()->approved()->create();
            $rfq = Rfq::factory()->create(['purchase_order_id' => $order->id, 'vendor_id' => $vendor->id, 'status' => 'sent']);
            $quote = Quote::factory()->create(['purchase_order_id' => $order->id, 'vendor_id' => $vendor->id, 'rfq_id' => $rfq->id, 'po_document_id' => $document->id, 'total_minor' => $order->lines()->sum('quantity') * 1000]);
            foreach ($order->lines as $line) {
                QuoteLine::factory()->create(['quote_id' => $quote->id, 'po_line_id' => $line->id]);
            }
            $quoteIds[] = $quote->id;
        }
        app(SourcingService::class)->evaluate($order, $logistics, $quoteIds[0], 'Selected after comparing three approved vendors');
    }
}

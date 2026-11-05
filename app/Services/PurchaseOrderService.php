<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus as Status;
use App\Enums\Role;
use App\Jobs\SendProcurementMail;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\User;
use App\Support\ProcurementAccess as Access;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PurchaseOrderService
{
    public function prepare(PurchaseOrder $order, Quote $quote, User $actor, string $reason): void
    {
        Access::roles($actor, Role::LogisticsOfficer);
        Access::tenant($quote);
        if ($order->status !== Status::QuotesIn || $quote->purchase_order_id !== $order->id) {
            throw new InvalidArgumentException('Commercial terms can only be prepared during quote evaluation.');
        }
        $before = $order->commercial_snapshot ?? [];
        $quote->load(['vendor', 'lines']);
        $snapshot = [
            'revision' => $order->revision, 'reference' => $order->reference, 'company' => $order->tenant->name,
            'vendor' => $quote->vendor->only(['id', 'name', 'email', 'address']),
            'quote_id' => $quote->id, 'currency' => $quote->currency, 'total_minor' => $quote->total_minor,
            'payment_terms' => $quote->payment_terms, 'lines' => [],
        ];
        foreach ($order->lines as $line) {
            $priced = $quote->lines->firstWhere('po_line_id', $line->id);
            if (! $priced) {
                throw new InvalidArgumentException('The selected quote does not cover every line.');
            }
            $line->update(['unit_price_minor' => $priced->unit_price_minor, 'condition' => $priced->condition, 'warranty' => $priced->warranty]);
            $snapshot['lines'][] = $line->only(['id', 'part_number', 'description', 'quantity', 'condition', 'warranty', 'unit_price_minor']);
        }
        $order->update([
            'vendor_id' => $quote->vendor_id, 'currency' => $quote->currency, 'total_minor' => $quote->total_minor,
            'payment_terms' => $quote->payment_terms, 'commercial_snapshot' => $snapshot,
        ]);
        Access::amount($order, $actor, 'po.commercial_terms', $before, $snapshot, $reason);
    }

    public function generatePdf(PurchaseOrder $order, User $actor): PoDocument
    {
        Access::tenant($order);
        if (! $order->commercial_snapshot || ! $order->vendor_id) {
            throw new InvalidArgumentException('Select and submit a complete commercial evaluation before generating the PO.');
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('purchase-orders.pdf', ['po' => $order, 'snapshot' => $order->commercial_snapshot])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        $contents = $pdf->output();
        $phase = $order->po_approved_at ? 'approved' : 'review';
        $path = 'po-documents/'.$order->tenant_id.'/'.$order->id.'/purchase-order-r'.$order->revision.'-'.$phase.'.pdf';
        if (! Storage::disk('private')->put($path, $contents)) {
            throw new \DomainException('The purchase-order PDF could not be saved.');
        }
        $order->update(['pdf_path' => $path]);

        return PoDocument::firstOrCreate(['disk_path' => $path], [
            'purchase_order_id' => $order->id, 'type' => DocumentType::PurchaseOrderDocument,
            'original_filename' => 'purchase-order-'.$order->id.'-r'.$order->revision.'-'.$phase.'.pdf',
            'mime_type' => 'application/pdf', 'size' => strlen($contents), 'uploaded_by' => $actor->id,
        ]);
    }

    public function issue(PurchaseOrder $order, User $actor): void
    {
        Access::roles($actor, Role::LogisticsOfficer);
        DB::transaction(function () use ($order, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== Status::PoApproved || ! $order->po_approved_at || ! $order->pdf_path || ! Storage::disk('private')->exists($order->pdf_path) || ! $order->vendor?->is_approved || ! $order->vendor?->is_active) {
                throw new InvalidArgumentException('CFO approval, an approved active vendor, and the generated PO PDF are required before dispatch.');
            }
            if ($order->dispatch_queued_at) {
                throw new InvalidArgumentException('Purchase-order delivery is already queued.');
            }
            $order->update(['dispatch_queued_at' => now()]);
            Access::audit($order, $actor, 'po.dispatch_queued', [], ['revision' => $order->revision]);
            SendProcurementMail::dispatch($order->tenant_id, 'purchase_order', $order->id, $actor->id)->afterCommit();
        });
    }

    public function reopenForCorrection(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($order, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [Status::ReturnedEvaluation, Status::ReturnedCfo], true)) {
                throw new InvalidArgumentException('Only a returned submission can be revised.');
            }
            $before = $order->only(['revision', 'commercial_snapshot', 'pdf_path']);
            $order->update(['revision' => $order->revision + 1, 'po_approved_at' => null, 'pdf_path' => null, 'dispatch_queued_at' => null]);
            $order = app(WorkflowService::class)->transition($order, Status::QuotesIn, $actor, 'Returned submission opened for correction');
            Access::audit($order, $actor, 'po.revision_opened', $before, ['revision' => $order->revision]);

            return $order;
        });
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DigimaintHandover;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use App\Services\ReceivingService;
use App\Services\ShippingService;
use App\Services\SourcingService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementController extends Controller
{
    public function action(Request $request, PurchaseOrder $purchaseOrder, string $action): RedirectResponse
    {
        $this->authorize('view', $purchaseOrder);
        $po = $purchaseOrder;
        $actor = $request->user();
        $documentRule = Rule::exists('po_documents', 'id')->where('tenant_id', TenantContext::id())->where('purchase_order_id', $po->id);
        $rules = match ($action) {
            'rfqs' => ['kind' => 'required|in:parts,freight'],
            'quote' => ['rfq_id' => 'required|integer', 'po_document_id' => ['required', 'integer', $documentRule], 'reference' => 'required|string|max:100', 'currency' => 'required|alpha|size:3', 'valid_until' => 'nullable|date|after_or_equal:today', 'reason' => 'required|string|max:1000', 'lines' => 'required|array|min:1|max:100', 'lines.*.po_line_id' => 'required|integer|distinct', 'lines.*.unit_price' => 'required|numeric|min:0', 'lines.*.condition' => 'required|string|max:100', 'lines.*.warranty' => 'required|string|max:255'],
            'evaluate' => ['selected_quote_id' => 'required|integer', 'justification' => 'required|string|max:3000'],
            'invoice' => ['po_document_id' => ['required', 'integer', $documentRule], 'reference' => ['required', 'string', 'max:100', Rule::unique('invoices')->where('tenant_id', TenantContext::id())->ignore($po->invoice?->id)], 'amount' => 'required|numeric|min:0', 'currency' => 'required|alpha|size:3', 'reason' => 'required|string|max:1000'],
            'pay' => ['payment_reference' => 'required|string|max:255'],
            'freight-quote' => ['rfq_id' => 'required|integer', 'po_document_id' => ['required', 'integer', $documentRule], 'reference' => 'required|string|max:100', 'amount' => 'required|numeric|min:0', 'currency' => 'required|alpha|size:3', 'reason' => 'required|string|max:1000'],
            'select-freight' => ['freight_quote_id' => 'required|integer'],
            'receive' => ['grn_number' => ['required', 'string', 'max:100', Rule::unique('goods_receipts')->where('tenant_id', TenantContext::id())], 'quantities' => 'required|array|min:1|max:100', 'quantities.*' => 'required|integer|min:0|max:1000000', 'note' => 'nullable|string|max:2000'],
            'handover' => ['activity' => 'required|in:stock_check,stock_issue,receipt', 'po_line_id' => 'required|integer', 'goods_receipt_id' => 'required_if:activity,receipt|nullable|integer', 'transaction_reference' => 'required|string|max:255', 'po_document_id' => ['required', 'integer', $documentRule], 'quantity' => 'required|integer|min:0|max:1000000', 'performed_at' => 'required|date|before_or_equal:now', 'note' => 'nullable|string|max:2000'],
            'issue', 'revise', 'submit-invoice' => [],
            default => abort(404),
        };
        $data = $request->validate($rules);

        return $this->complete(function () use ($action, $po, $actor, $data) {
            return match ($action) {
                'rfqs' => app(SourcingService::class)->sendRfqs($po, $actor, $data['kind']),
                'quote' => app(SourcingService::class)->captureQuote($po, $actor, $po->rfqs()->findOrFail($data['rfq_id']), $data),
                'evaluate' => app(SourcingService::class)->evaluate($po, $actor, $data['selected_quote_id'], $data['justification']),
                'issue' => app(PurchaseOrderService::class)->issue($po, $actor),
                'revise' => app(PurchaseOrderService::class)->reopenForCorrection($po, $actor),
                'invoice' => app(PaymentService::class)->prepare($po, $actor, $data),
                'submit-invoice' => app(PaymentService::class)->submit($po->invoice()->firstOrFail(), $actor),
                'pay' => app(PaymentService::class)->confirm($po->invoice()->firstOrFail(), $actor, $data['payment_reference']),
                'freight-quote' => app(SourcingService::class)->captureFreightQuote($po, $actor, $po->rfqs()->findOrFail($data['rfq_id']), $data),
                'select-freight' => app(ShippingService::class)->selectFreight($po, $actor, $data['freight_quote_id']),
                'receive' => app(ReceivingService::class)->receive($po, $actor, $data['grn_number'], array_filter($data['quantities'], fn ($quantity) => $quantity > 0), $data['note'] ?? null),
                'handover' => app(ReceivingService::class)->recordHandover($po, $actor, $data),
            };
        });
    }

    public function verifyHandover(Request $request, DigimaintHandover $handover): RedirectResponse
    {
        $this->authorize('view', $handover->purchaseOrder);
        $data = $request->validate(['verified' => 'required|boolean', 'note' => 'required|string|max:2000']);

        return $this->complete(fn () => app(ReceivingService::class)->verify($handover, $request->user(), (bool) $data['verified'], $data['note']));
    }

    public function retryRfq(Request $request, Rfq $rfq): RedirectResponse
    {
        $this->authorize('source', $rfq->purchaseOrder);

        return $this->complete(fn () => app(SourcingService::class)->retryRfq($rfq, $request->user()));
    }

    public function withdrawRfq(Request $request, Rfq $rfq): RedirectResponse
    {
        $this->authorize('source', $rfq->purchaseOrder);
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->complete(fn () => app(SourcingService::class)->withdrawRfq($rfq, $request->user(), $data['reason']));
    }

    public function pdf(PurchaseOrder $purchaseOrder): StreamedResponse
    {
        $this->authorize('view', $purchaseOrder);
        abort_unless($purchaseOrder->pdf_path && Storage::disk('private')->exists($purchaseOrder->pdf_path), 404);

        return Storage::disk('private')->download($purchaseOrder->pdf_path, 'purchase-order-'.$purchaseOrder->id.'.pdf');
    }
}

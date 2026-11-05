<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus as Status;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Money;
use App\Support\ProcurementAccess as Access;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    public function prepare(PurchaseOrder $order, User $actor, array $data): Invoice
    {
        Access::roles($actor, Role::LogisticsOfficer, Role::Payables);

        return DB::transaction(function () use ($order, $actor, $data) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $allowed = $order->payment_terms === 'cia' ? [Status::Acknowledged, Status::AwaitingPayment] : [Status::Received, Status::GrnIssued, Status::Closed];
            if (! $order->po_approved_at || ! in_array($order->status, $allowed, true)) {
                throw new InvalidArgumentException('CIA invoices follow acknowledgement; net-terms invoices follow completed Stores receipt.');
            }
            $type = $order->payment_terms === 'cia' ? DocumentType::ProformaInvoice : DocumentType::CommercialInvoice;
            $document = $order->documents()->whereKey($data['po_document_id'])->where('type', $type)->firstOrFail();
            $amount = Money::minor($data['amount']);
            if ($amount !== $order->total_minor || strtoupper($data['currency']) !== $order->currency) {
                throw new InvalidArgumentException('The invoice currency and total must match the approved PO. Return the commercial submission to correct its amounts.');
            }
            $invoice = Invoice::firstOrNew(['purchase_order_id' => $order->id]);
            if ($invoice->exists && ! in_array($invoice->status, ['draft', 'returned'], true)) {
                throw new InvalidArgumentException('This invoice is already submitted, rejected, or paid.');
            }
            $before = $invoice->getAttributes();
            if ($invoice->exists && $invoice->status === 'returned') {
                $invoice->revision++;
            }
            $invoice->fill(['reference' => $data['reference'], 'po_document_id' => $document->id, 'currency' => $order->currency, 'amount_minor' => $amount, 'payment_terms' => $order->payment_terms, 'status' => 'draft'])->save();
            Access::amount($invoice, $actor, 'invoice.prepared', $before, $invoice->getAttributes(), $data['reason'] ?? 'Invoice prepared');

            return $invoice;
        });
    }

    public function submit(Invoice $invoice, User $actor): void
    {
        Access::roles($actor, Role::LogisticsOfficer, Role::Payables);
        Access::tenant($invoice);
        DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $order = $invoice->purchaseOrder;
            $allowed = $invoice->payment_terms === 'cia' ? [Status::Acknowledged, Status::AwaitingPayment] : [Status::Received, Status::GrnIssued, Status::Closed];
            if ($invoice->status !== 'draft' || ! in_array($order->status, $allowed, true) || $invoice->amount_minor !== $order->total_minor || $invoice->currency !== $order->currency) {
                throw new InvalidArgumentException('A matching draft invoice and the correct receipt or acknowledgement stage are required.');
            }
            if ($invoice->payment_terms === 'cia' && $order->status === Status::Acknowledged) {
                app(WorkflowService::class)->transition($order, Status::AwaitingPayment, $actor);
            }
            $invoice->update(['status' => 'pending_approval']);
            $stage = 'payment_'.$invoice->payment_terms;
            app(ApprovalService::class)->request($invoice, app(TenantSettingsService::class)->rolesFor($stage), $stage);
            Access::audit($invoice, $actor, 'invoice.submitted', [], ['revision' => $invoice->revision]);
        });
    }

    public function confirm(Invoice $invoice, User $actor, string $paymentReference): void
    {
        Access::roles($actor, Role::Treasury);
        Access::tenant($invoice);
        DB::transaction(function () use ($invoice, $actor, $paymentReference) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::whereKey($invoice->purchase_order_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'approved' || trim($paymentReference) === '') {
                throw new InvalidArgumentException('Complete every finance approval and enter the payment reference before confirming payment.');
            }
            if ($invoice->payment_terms === 'cia' && (! $order->documents()->where('type', DocumentType::TelexCopy)->exists() || $order->status !== Status::AwaitingPayment)) {
                throw new InvalidArgumentException('Upload the telex copy while the CIA order is awaiting payment.');
            }
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $actor->id, 'payment_reference' => $paymentReference]);
            Access::audit($invoice, $actor, 'payment.confirmed', [], ['reference' => $paymentReference]);
            if ($invoice->payment_terms === 'cia') {
                app(WorkflowService::class)->transition($order, Status::ReadyToShip, $actor, 'CIA payment confirmed: '.$paymentReference);
            }
        });
    }
}

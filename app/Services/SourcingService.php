<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus as Status;
use App\Enums\Role;
use App\Jobs\SendProcurementMail;
use App\Models\FreightQuote;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\QuoteEvaluation;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Money;
use App\Support\ProcurementAccess as Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SourcingService
{
    public function saveVendor(User $actor, array $data, ?Vendor $vendor = null): Vendor
    {
        Access::roles($actor, Role::LogisticsOfficer);
        if ($vendor) {
            Access::tenant($vendor);
        }

        return DB::transaction(function () use ($actor, $data, $vendor) {
            $vendor ??= new Vendor;
            $before = $vendor->getAttributes();
            $vendor->fill(collect($data)->only(['name', 'email', 'kind', 'payment_terms', 'address', 'account_reference', 'is_active'])->all());
            $vendor->is_approved = false;
            $vendor->save();
            Access::audit($vendor, $actor, 'vendor.saved', $before, $vendor->getAttributes());

            return $vendor;
        });
    }

    public function approveVendor(Vendor $vendor, User $actor, bool $approved): void
    {
        Access::roles($actor, Role::Tlm);
        Access::tenant($vendor);
        DB::transaction(function () use ($vendor, $actor, $approved) {
            $before = ['is_approved' => $vendor->is_approved];
            $vendor->update(['is_approved' => $approved]);
            Access::audit($vendor, $actor, 'vendor.approval', $before, ['is_approved' => $approved]);
        });
    }

    public function sendRfqs(PurchaseOrder $order, User $actor, string $kind = 'parts'): Collection
    {
        Access::roles($actor, Role::LogisticsOfficer);
        Access::tenant($order);

        return DB::transaction(function () use ($order, $actor, $kind) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($kind, ['parts', 'freight'], true)) {
                throw new InvalidArgumentException('Unknown RFQ type.');
            }
            $shipment = $kind === 'freight' ? $order->shipment : null;
            if (($kind === 'parts' && ! in_array($order->status, [Status::StockChecked, Status::RfqSent, Status::QuotesIn], true)) || ($kind === 'freight' && ($order->status !== Status::ReadyToShip || ! $shipment || $shipment->route !== 'freight_forwarder' || ! in_array($shipment->status, ['draft', 'returned'], true)))) {
                throw new InvalidArgumentException('This order is not ready for these RFQs.');
            }
            $revision = $shipment?->revision ?? $order->revision;
            $solicited = $order->rfqs()->where('kind', $kind)->where('revision', $revision)->pluck('vendor_id');
            $vendors = Vendor::where('kind', $kind === 'parts' ? 'supplier' : 'freight_forwarder')->where('is_approved', true)->where('is_active', true)->whereNotIn('id', $solicited)->get();
            if ($vendors->isEmpty()) {
                throw new InvalidArgumentException('There are no additional approved active vendors to solicit. Retry failed deliveries individually.');
            }
            $rfqs = collect();
            foreach ($vendors as $vendor) {
                $rfq = Rfq::create([
                    'purchase_order_id' => $order->id, 'shipment_id' => $shipment?->id, 'vendor_id' => $vendor->id,
                    'kind' => $kind, 'revision' => $revision,
                    'reference' => 'RFQ-'.$order->id.'-'.$kind.'-'.$revision.'-'.$vendor->id, 'status' => 'queued',
                ]);
                SendProcurementMail::dispatch($order->tenant_id, 'rfq', $rfq->id, $actor->id)->afterCommit();
                $rfqs->push($rfq);
            }
            Access::audit($order, $actor, 'rfqs.queued', [], ['rfq_ids' => $rfqs->pluck('id')->all()]);

            return $rfqs;
        });
    }

    public function retryRfq(Rfq $rfq, User $actor): void
    {
        Access::roles($actor, Role::LogisticsOfficer);
        Access::tenant($rfq);
        DB::transaction(function () use ($rfq, $actor) {
            $rfq = Rfq::whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if ($rfq->status !== 'failed' || ! $rfq->vendor->is_active || ! $rfq->vendor->is_approved) {
                throw new InvalidArgumentException('Only a failed RFQ for an approved active vendor can be retried.');
            }
            $rfq->update(['status' => 'queued', 'last_error' => null]);
            SendProcurementMail::dispatch($rfq->tenant_id, 'rfq', $rfq->id, $actor->id)->afterCommit();
        });
    }

    public function withdrawRfq(Rfq $rfq, User $actor, string $reason): void
    {
        Access::roles($actor, Role::LogisticsOfficer);
        Access::tenant($rfq);
        DB::transaction(function () use ($rfq, $actor, $reason) {
            $rfq = Rfq::whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if (! in_array($rfq->status, ['queued', 'failed'], true) || ($rfq->vendor->is_approved && $rfq->vendor->is_active) || trim($reason) === '') {
                throw new InvalidArgumentException('Only an unsent RFQ to a withdrawn or inactive vendor can be withdrawn, with a reason.');
            }
            $rfq->update(['status' => 'withdrawn', 'last_error' => null]);
            $order = PurchaseOrder::whereKey($rfq->purchase_order_id)->lockForUpdate()->firstOrFail();
            Access::audit($order, $actor, 'rfq.withdrawn', [], ['rfq_id' => $rfq->id], $reason);
            if ($rfq->kind === 'parts' && $order->status === Status::StockChecked && $order->rfqs()->where('kind', 'parts')->where('status', 'sent')->exists() && ! $order->rfqs()->where('kind', 'parts')->whereNotIn('status', ['sent', 'withdrawn'])->exists()) {
                app(WorkflowService::class)->transition($order, Status::RfqSent, $actor, 'RFQs resolved; withdrawn vendor excluded with an audit reason');
            }
        });
    }

    public function captureQuote(PurchaseOrder $order, User $actor, Rfq $rfq, array $data): Quote
    {
        Access::roles($actor, Role::LogisticsOfficer);
        Access::tenant($rfq);

        return DB::transaction(function () use ($order, $actor, $rfq, $data) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [Status::RfqSent, Status::QuotesIn], true) || $rfq->purchase_order_id !== $order->id || $rfq->kind !== 'parts' || $rfq->status !== 'sent' || ! $rfq->vendor->is_approved || ! $rfq->vendor->is_active) {
                throw new InvalidArgumentException('Choose a delivered parts RFQ for an approved active vendor on this order.');
            }
            $document = $order->documents()->whereKey($data['po_document_id'])->where('type', DocumentType::Quotation)->firstOrFail();
            $lines = $order->lines()->get()->keyBy('id');
            $submitted = collect($data['lines']);
            if ($submitted->pluck('po_line_id')->map(fn ($id) => (int) $id)->sort()->values()->all() !== $lines->keys()->sort()->values()->all()) {
                throw new InvalidArgumentException('Quote every requested line exactly once.');
            }
            $total = 0;
            $priced = [];
            foreach ($submitted as $line) {
                $price = Money::minor($line['unit_price']);
                $total += $price * $lines[(int) $line['po_line_id']]->quantity;
                if (! is_int($total) || $total > 9000000000000000) {
                    throw new InvalidArgumentException('The quotation total is too large.');
                }
                $priced[] = ['po_line_id' => (int) $line['po_line_id'], 'unit_price_minor' => $price, 'condition' => $line['condition'], 'warranty' => $line['warranty']];
            }
            $quote = Quote::firstOrNew(['rfq_id' => $rfq->id]);
            $before = $quote->exists ? $quote->load('lines')->toArray() : [];
            $quote->fill([
                'purchase_order_id' => $order->id, 'vendor_id' => $rfq->vendor_id, 'po_document_id' => $document->id,
                'reference' => $data['reference'], 'currency' => strtoupper($data['currency']), 'payment_terms' => $rfq->vendor->payment_terms,
                'total_minor' => $total, 'valid_until' => $data['valid_until'] ?? null,
            ])->save();
            $quote->lines()->delete();
            $quote->lines()->createMany($priced);
            Access::amount($quote, $actor, 'quote.priced', $before, $quote->fresh()->load('lines')->toArray(), $data['reason'] ?? 'Quotation recorded');
            if ($order->status === Status::RfqSent) {
                app(WorkflowService::class)->transition($order, Status::QuotesIn, $actor, 'Vendor quotation captured');
            }

            return $quote;
        });
    }

    public function comparison(PurchaseOrder $order): Collection
    {
        return $order->quotes()->with(['vendor', 'lines', 'document'])->orderByDesc('id')->get()->unique('vendor_id')
            ->filter(fn (Quote $q) => $q->vendor->is_approved && $q->vendor->is_active && (! $q->valid_until || $q->valid_until->endOfDay()->isFuture()))
            ->sortBy(fn (Quote $q) => [$q->payment_terms === 'net' ? 0 : 1, $q->currency, $q->total_minor])->values();
    }

    public function evaluate(PurchaseOrder $order, User $actor, int $selectedQuoteId, string $justification): QuoteEvaluation
    {
        Access::roles($actor, Role::LogisticsOfficer);

        return DB::transaction(function () use ($order, $actor, $selectedQuoteId, $justification) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== Status::QuotesIn || trim($justification) === '') {
                throw new InvalidArgumentException('Select a quotation and explain the evaluation while quotations are under review.');
            }
            $quotes = $this->comparison($order);
            $selected = $quotes->firstWhere('id', $selectedQuoteId);
            if (! $selected || $quotes->count() < 3) {
                throw new InvalidArgumentException('The comparison requires valid quotations from at least three distinct approved vendors.');
            }
            if ($quotes->pluck('currency')->unique()->count() !== 1) {
                throw new InvalidArgumentException('Obtain quotations in the same currency before comparing them.');
            }
            $snapshot = $quotes->map(fn (Quote $q) => ['quote_id' => $q->id, 'vendor_id' => $q->vendor_id, 'vendor' => $q->vendor->name, 'payment_terms' => $q->payment_terms, 'currency' => $q->currency, 'total_minor' => $q->total_minor, 'document_id' => $q->po_document_id, 'lines' => $q->lines->toArray()])->all();
            $evaluation = QuoteEvaluation::updateOrCreate(['purchase_order_id' => $order->id, 'revision' => $order->revision], [
                'selected_quote_id' => $selected->id, 'justification' => $justification, 'comparison_snapshot' => $snapshot, 'status' => 'draft',
            ]);
            app(PurchaseOrderService::class)->prepare($order, $selected, $actor, $justification);
            Access::audit($order, $actor, 'evaluation.prepared', [], ['evaluation_id' => $evaluation->id, 'selected_quote_id' => $selected->id]);

            return $evaluation;
        });
    }

    public function captureFreightQuote(PurchaseOrder $order, User $actor, Rfq $rfq, array $data): FreightQuote
    {
        Access::roles($actor, Role::LogisticsOfficer);
        Access::tenant($rfq);

        return DB::transaction(function () use ($order, $rfq, $data, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = $order->shipment;
            $rfq = $rfq->fresh();
            if (! $shipment || $order->status !== Status::ReadyToShip || ! in_array($shipment->status, ['draft', 'returned'], true) || $rfq->shipment_id !== $shipment->id || $rfq->status !== 'sent' || ! $rfq->vendor->is_approved || ! $rfq->vendor->is_active) {
                throw new InvalidArgumentException('Choose a delivered freight RFQ for this draft or returned shipment.');
            }
            $document = $order->documents()->whereKey($data['po_document_id'])->where('type', DocumentType::FreightQuotation)->firstOrFail();
            $quote = FreightQuote::firstOrNew(['rfq_id' => $rfq->id]);
            $before = $quote->only(['amount_minor', 'currency']);
            $quote->fill(['shipment_id' => $shipment->id, 'vendor_id' => $rfq->vendor_id, 'po_document_id' => $document->id, 'reference' => $data['reference'], 'currency' => strtoupper($data['currency']), 'amount_minor' => Money::minor($data['amount'])])->save();
            Access::amount($quote, $actor, 'freight_quote.priced', $before, $quote->only(['amount_minor', 'currency']), $data['reason'] ?? 'Freight quotation recorded');

            return $quote;
        });
    }
}

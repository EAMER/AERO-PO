@if($logistics && in_array($stage, ['ready_to_ship', 'in_transit', 'delivered', 'received', 'grn_issued']))
<section class="bg-white p-6 rounded shadow-sm space-y-4"><h3 class="font-semibold">Shipping</h3>
@if($stage === 'ready_to_ship')
@if(!$po->shipment || ($po->shipment->status === 'draft' && $po->shipment->rfqs->isEmpty()))
<form method="POST" action="{{ route('shipping.record-details', $po) }}" class="flex flex-wrap items-end gap-3">@csrf<label>Weight (kg)<input class="field" name="total_weight_kg" type="number" min="0.01" max="99999999.99" step="0.01" value="{{ old('total_weight_kg', $po->total_weight_kg) }}" required></label><label><input type="checkbox" name="contains_dg" value="1" @checked($po->contains_dg)> Dangerous goods</label><label>Courier account (under 50 kg, non-DG)<select class="field" name="carrier_id"><option value="">Freight / select courier</option>@foreach($couriers as $courier)<option value="{{ $courier->id }}" @selected($po->shipment?->carrier_id === $courier->id)>{{ $courier->name }} · {{ $courier->account_reference }}</option>@endforeach</select></label><x-primary-button>Save shipment details</x-primary-button></form>
@endif
@if($po->shipment)
<p>{{ $po->shipment->weight_kg }} kg · {{ $po->contains_dg ? 'DG' : 'non-DG' }} · {{ ucwords(str_replace('_', ' ', $po->shipment->route)) }} · {{ $po->shipment->status }}</p>
@if($po->shipment->route === 'freight_forwarder')
@if($canSendFreightRfqs && in_array($po->shipment->status, ['draft', 'returned']))<form method="POST" action="{{ route('procurement.action', [$po, 'action' => 'rfqs']) }}">@csrf<input type="hidden" name="kind" value="freight"><x-primary-button>Send RFQs to approved forwarders not yet contacted</x-primary-button></form>@endif
@foreach($po->shipment->rfqs as $rfq)<p class="text-sm">{{ $rfq->reference }} · {{ $rfq->vendor->name }} · {{ $rfq->status }}</p>@if($rfq->status === 'failed')<form method="POST" action="{{ route('rfqs.retry', $rfq) }}">@csrf<button class="text-indigo-700 underline">Retry after checking delivery</button></form>@endif @endforeach
@foreach($po->shipment->rfqs as $rfq)@if(in_array($rfq->status, ['queued', 'failed']) && (!$rfq->vendor->is_active || !$rfq->vendor->is_approved))<form method="POST" action="{{ route('rfqs.withdraw', $rfq) }}">@csrf<label>Reason for withdrawing {{ $rfq->reference }}<input class="field" name="reason" required maxlength="1000"></label><x-primary-button>Withdraw unsent RFQ</x-primary-button></form>@endif @endforeach
@if(in_array($po->shipment->status, ['draft', 'returned']))
<form method="POST" action="{{ route('procurement.action', [$po, 'action' => 'freight-quote']) }}" class="grid sm:grid-cols-3 gap-3">@csrf
<label>Delivered RFQ<select class="field" name="rfq_id" required>@foreach($po->shipment->rfqs->where('status', 'sent') as $rfq)<option value="{{ $rfq->id }}">{{ $rfq->vendor->name }}</option>@endforeach</select></label><label>Quote reference<input class="field" name="reference" required maxlength="100"></label><label>Freight document<select class="field" name="po_document_id" required>@foreach($po->documents->where('type', \App\Enums\DocumentType::FreightQuotation) as $document)<option value="{{ $document->id }}">{{ $document->original_filename }}</option>@endforeach</select></label><label>Total freight<input class="field" name="amount" type="number" step="0.01" min="0" required></label><label>Currency<input class="field" name="currency" pattern="[A-Za-z]{3}" maxlength="3" required></label><label>Reason / revision notes<input class="field" name="reason" required maxlength="1000"></label><div><x-primary-button>Save freight quotation</x-primary-button></div></form>
<form method="POST" action="{{ route('procurement.action', [$po, 'action' => 'select-freight']) }}" class="flex flex-wrap items-end gap-3">@csrf<label>Selected freight quotation<select class="field" name="freight_quote_id" required>@foreach($po->shipment->quotes as $quote)<option value="{{ $quote->id }}">{{ $quote->vendor->name }} · {{ $quote->currency }} {{ \App\Support\Money::format($quote->amount_minor) }}</option>@endforeach</select></label><x-primary-button>Request TLM freight approval</x-primary-button></form>
@endif
@else
@if(!$po->customs_duty_paid)<p class="text-sm">Upload the customs-duty receipt before recording payment.</p><form method="POST" action="{{ route('shipping.customs-duty-paid', $po) }}">@csrf<x-primary-button>Record customs duty paid</x-primary-button></form>@else<p class="text-green-800 text-sm">Customs duty paid.</p>@endif
@endif
<ul class="text-sm">@foreach($shippingChecklist as $row)<li class="{{ $row['met'] ? 'text-green-800' : 'text-red-700' }}">{{ ucwords(str_replace('_', ' ', $row['type'])) }}: {{ $row['have'] }}/{{ $row['required'] }}</li>@endforeach</ul>
<form method="POST" action="{{ route('shipping.dispatch', $po) }}" class="flex flex-wrap items-end gap-3">@csrf<label>AWB number<input class="field" name="awb_number" required maxlength="100"></label><x-primary-button>Dispatch shipment</x-primary-button></form>
@endif
@endif
@if($po->awb_number)<p>AWB {{ $po->awb_number }}</p>@endif
@if($stage === 'in_transit')<form method="POST" action="{{ route('shipping.mark-delivered', $po) }}">@csrf<x-primary-button>Confirm delivery</x-primary-button></form>@endif
</section>
@endif

<section class="bg-white p-6 rounded shadow-sm space-y-4"><h3 class="font-semibold">Stores and manual DigiMaint handover</h3>
@if($stores && $stage === 'delivered')
<p class="text-sm">Record the quantities actually received. Zero leaves a line outstanding.</p><ul class="text-sm">@foreach($receivingChecklist as $row)<li class="{{ $row['met'] ? 'text-green-800' : 'text-red-700' }}">{{ ucwords(str_replace('_', ' ', $row['type'])) }}: {{ $row['have'] }}/{{ $row['required'] }}</li>@endforeach</ul>
<form method="POST" action="{{ route('procurement.action', [$po, 'action' => 'receive']) }}" class="space-y-3">@csrf<label class="block">GRN number<input class="field" name="grn_number" value="{{ old('grn_number') }}" required maxlength="100"></label>
@foreach($po->lines as $line)<label class="block">{{ $line->part_number }} — ordered {{ $line->quantity }}, already received {{ $receivedQuantities[$line->id] ?? 0 }}<input class="field" name="quantities[{{ $line->id }}]" type="number" min="0" max="{{ $line->quantity - ($receivedQuantities[$line->id] ?? 0) }}" value="{{ old('quantities.'.$line->id, 0) }}" required></label>@endforeach
<label class="block">Receipt / discrepancy notes<textarea class="field" name="note" maxlength="2000">{{ old('note') }}</textarea></label><x-primary-button>Record actual receipt</x-primary-button></form>
@endif
@foreach($po->receipts as $receipt)<p class="text-sm">GRN {{ $receipt->grn_number }} · {{ $receipt->received_at->format('d M Y H:i T') }} · @foreach($receipt->lines as $receiptLine){{ $po->lines->firstWhere('id', $receiptLine->po_line_id)?->part_number }}: {{ $receiptLine->quantity }} @endforeach · {{ $receipt->note }}</p>@endforeach
@php
    $activities = [];
    if(in_array($stage, ['requisition_raised', 'stock_checked', 'fulfilled_from_stock'])) { $activities['stock_check'] = 'Stock check'; }
    if($stage === 'fulfilled_from_stock') { $activities['stock_issue'] = 'Issue from existing stock'; }
    if($po->receipts->isNotEmpty() && !$po->status->isTerminal()) { $activities['receipt'] = 'Supplier receipt'; }
@endphp
@if($stores && count($activities))
<details open><summary class="font-medium cursor-pointer">Record a completed DigiMaint action</summary><p class="text-sm mt-2">Upload DigiMaint evidence in Documents first. Record the actual reference and quantity. A Store Supervisor verifies the entry separately.</p>
<form method="POST" action="{{ route('procurement.action', [$po, 'action' => 'handover']) }}" class="grid sm:grid-cols-3 gap-3 mt-3">@csrf
<label>Activity<select class="field" name="activity" required>@foreach($activities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
<label>Part line<select class="field" name="po_line_id" required>@foreach($po->lines as $line)<option value="{{ $line->id }}">{{ $line->part_number }} — {{ $line->quantity }} requested</option>@endforeach</select></label>
<label>GRN (supplier receipt only)<select class="field" name="goods_receipt_id"><option value="">Not applicable</option>@foreach($po->receipts as $receipt)<option value="{{ $receipt->id }}">{{ $receipt->grn_number }}</option>@endforeach</select></label>
<label>DigiMaint record / transaction reference<input class="field" name="transaction_reference" value="{{ old('transaction_reference') }}" required maxlength="255"></label><label>Actual quantity<input class="field" name="quantity" type="number" min="0" max="1000000" required value="{{ old('quantity') }}"></label><label>Action time, including UTC offset<input class="field" name="performed_at" required value="{{ old('performed_at', now()->toIso8601String()) }}"></label>
<label>Evidence<select class="field" name="po_document_id" required>@foreach($po->documents->where('type', \App\Enums\DocumentType::DigimaintEvidence) as $document)<option value="{{ $document->id }}">{{ $document->original_filename }}</option>@endforeach</select></label><label class="sm:col-span-2">Notes<textarea class="field" name="note" maxlength="2000">{{ old('note') }}</textarea></label><div><x-primary-button>Record manual handover</x-primary-button></div></form></details>
@endif
@foreach($po->handovers->sortByDesc('id') as $handover)<div class="border rounded p-3 space-y-2 text-sm"><p>{{ ucwords(str_replace('_', ' ', $handover->activity)) }} · {{ $po->lines->firstWhere('id', $handover->po_line_id)?->part_number }} · Qty {{ $handover->quantity }} · {{ $handover->transaction_reference }} · <strong>{{ $handover->status }}</strong></p><p>{{ $handover->recorder?->name }} · {{ $handover->performed_at->format('d M Y H:i T') }} · {{ $handover->note }}</p>@if($handover->verified_by)<p>Reviewed by {{ $handover->verifier?->name }} {{ $handover->verified_at?->format('d M Y H:i T') }}</p>@endif
@if($supervisor && $handover->status === 'recorded' && $handover->recorded_by !== auth()->id())<form method="POST" action="{{ route('handover.verify', $handover) }}" class="flex flex-wrap gap-3 items-end">@csrf<label>Result<select class="field" name="verified"><option value="1">Verified against DigiMaint</option><option value="0">Exception — correction needed</option></select></label><label>Verification / discrepancy note<input class="field" name="note" required maxlength="2000"></label><x-primary-button>Record verification</x-primary-button></form>@endif</div>@endforeach
@if($stores && $stage === 'received')<form method="POST" action="{{ route('shipping.issue-grn', $po) }}">@csrf<input type="hidden" name="grn_number" value="{{ $po->grn_number }}"><x-primary-button>Complete GRN registration</x-primary-button></form>@endif
@if($stage === 'grn_issued')
@if($stores && !$po->serviceable_label_applied_at)<form method="POST" action="{{ route('shipping.serviceable-label', $po) }}">@csrf<x-primary-button>Record serviceable label applied</x-primary-button></form>@endif
@if($logistics && !$po->cardex_sent_at)<form method="POST" action="{{ route('shipping.cardex-sent', $po) }}">@csrf<x-primary-button>Record Cardex sent</x-primary-button></form>@endif
@if($logistics)<form method="POST" action="{{ route('shipping.close', $po) }}">@csrf<x-primary-button>Close verified order</x-primary-button></form>@endif
@endif
@if($logistics && $stage === 'fulfilled_from_stock')<form method="POST" action="{{ route('shipping.close-from-stock', $po) }}">@csrf<x-primary-button>Close after verified stock issue</x-primary-button></form>@endif
@if($po->digimaint_synced_at)<p class="text-sm text-green-800">Manual DigiMaint handover verified {{ $po->digimaint_synced_at->format('d M Y H:i T') }}.</p>@endif
</section>

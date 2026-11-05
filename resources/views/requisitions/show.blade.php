<x-app-layout>
    <x-slot name="header"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold text-xl">{{ $po->reference }} <x-status-badge :status="$po->status" /></h2><a class="text-indigo-700 underline" href="{{ route('requisitions.index') }}">All requisitions</a></div></x-slot>
    @php
        $role = auth()->user()->role;
        $admin = $role->isAdmin();
        $logistics = $admin || $role === \App\Enums\Role::LogisticsOfficer;
        $stores = $admin || in_array($role, [\App\Enums\Role::StoreStaff, \App\Enums\Role::StoreSupervisor], true);
        $supervisor = $admin || $role === \App\Enums\Role::StoreSupervisor;
        $payables = $admin || $role === \App\Enums\Role::Payables;
        $treasury = $admin || $role === \App\Enums\Role::Treasury;
        $stage = $po->status->value;
    @endphp
    <div class="py-8"><div class="max-w-7xl mx-auto px-4 space-y-6">
        @if(session('status'))<p role="status" class="rounded bg-green-50 p-4 text-green-900">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded bg-red-50 p-4 text-red-900">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @php
            $stockPath = $stage === 'fulfilled_from_stock' || ($stage === 'closed' && $po->logs->contains(fn ($entry) => $entry->to_status === \App\Enums\PurchaseOrderStatus::FulfilledFromStock));
            $workflowLabels = $stockPath ? ['Request', 'Stock check', 'Stock issue'] : ['Request & stock', 'Sourcing', 'Approvals', 'PO & payment', 'Shipping', 'Receiving'];
            $workflowIndex = $stockPath ? ($stage === 'closed' ? 3 : 2) : match($stage) {
                'requisition_raised', 'stock_checked' => 0,
                'rfq_sent', 'quotes_in', 'returned_by_evaluation', 'returned_by_cfo' => 1,
                'pending_approval', 'approved', 'pending_cfo' => 2,
                'po_approved', 'po_sent', 'acknowledged', 'awaiting_payment' => 3,
                'ready_to_ship', 'in_transit', 'delivered' => 4,
                'received', 'grn_issued' => 5,
                'closed' => 6,
                default => 0,
            };
        @endphp
        @if(!in_array($stage, ['cancelled', 'rejected'], true))
        <ol class="request-stepper {{ $stockPath ? 'request-stepper-stock' : '' }}" aria-label="Requisition progress">
            @foreach($workflowLabels as $label)<li class="request-step {{ $loop->index < $workflowIndex ? 'is-complete' : ($loop->index === $workflowIndex ? 'is-current' : '') }}" @if($loop->index === $workflowIndex) aria-current="step" @endif><span class="step-number">@if($loop->index < $workflowIndex)<x-icon name="check" />@else{{ $loop->iteration }}@endif</span><span>{{ $label }}</span></li>@endforeach
        </ol>
        @endif
        <section class="panel">
            <dl class="request-summary">
                <div><dt>Requested by</dt><dd>{{ $po->requester?->name ?? 'Requester unavailable' }}</dd></div>
                <div><dt>Request type / revision</dt><dd>{{ $po->is_amo_request ? 'AMO request' : 'Standard request' }} · Revision {{ $po->revision }}</dd></div>
                <div><dt>Selected vendor</dt><dd>{{ data_get($po->commercial_snapshot, 'vendor.name') ?? 'Pending evaluation' }}</dd></div>
                <div><dt>Order value</dt><dd>{{ $po->commercial_snapshot ? $po->currency.' '.\App\Support\Money::format($po->total_minor) : 'Not yet priced' }}</dd></div>
            </dl>
            <div class="panel-header"><div><h3>Required parts <span class="count-pill">{{ $po->lines->count() }}</span></h3><p>{{ $po->commercial_snapshot ? ($po->payment_terms === 'cia' ? 'Cash in advance' : 'Net terms') : 'Stock checks and commercial terms are recorded against each line.' }}</p></div>@if($po->pdf_path)<a class="btn-secondary" href="{{ route('procurement.pdf', $po) }}"><x-icon name="file" /> Download PO PDF, revision {{ $po->revision }}</a>@endif</div>
            @if($po->evaluations->isNotEmpty())<p class="px-6 py-4 text-xs text-gray-500 border-b border-gray-100"><strong class="text-gray-700">Evaluation:</strong> {{ $po->evaluations->sortByDesc('revision')->first()->justification }}</p>@endif
            <div class="table-scroll"><table><thead><tr><th>Part number</th><th>Description</th><th>Requested</th><th>Condition</th><th>Warranty</th><th>Unit price</th><th>Stock</th></tr></thead><tbody>
                @foreach($po->lines as $line)<tr><td><span class="reference-link">{{ $line->part_number }}</span></td><td>{{ $line->description }}</td><td>{{ $line->quantity }}</td><td>{{ $line->condition }}</td><td>{{ $line->warranty ?? '—' }}</td><td>{{ $po->currency ? \App\Support\Money::format($line->unit_price_minor) : '—' }}</td><td><span class="status-badge {{ $line->stock_available === null ? 'status-info' : ($line->stock_available ? 'status-success' : 'status-warning') }}">{{ $line->stock_available === null ? 'Unchecked' : ($line->stock_available ? 'Sufficient' : 'Short') }}</span></td></tr>@endforeach
            </tbody></table></div>
        </section>
        @include('requisitions.partials.sourcing')
        @if(!$logistics && $comparison->isNotEmpty())<section class="bg-white p-6 rounded shadow-sm"><h3 class="font-semibold mb-3">Quote comparison — net terms ranked first</h3>@foreach($comparison as $quote)<p class="text-sm py-2">{{ $loop->iteration }}. {{ $quote->vendor->name }} · {{ strtoupper($quote->payment_terms) }} · {{ $quote->currency }} {{ \App\Support\Money::format($quote->total_minor) }}</p>@endforeach</section>@endif
        @include('requisitions.partials.finance')
        @include('requisitions.partials.shipping')
        @include('requisitions.partials.receiving')
        <section class="bg-white p-6 rounded shadow-sm space-y-4">
            <h3 class="font-semibold">Documents and evaluation pack</h3>
            <ul class="text-sm">@foreach($checklist as $row)<li class="{{ $row['met'] ? 'text-green-800' : 'text-red-700' }}">{{ ucwords(str_replace('_', ' ', $row['type'])) }}: {{ $row['have'] }}/{{ $row['required'] }}</li>@endforeach</ul>
            @php
                $uploadTypes = collect(\App\Enums\DocumentType::cases())->filter(function ($type) use ($admin, $logistics, $stores, $payables, $treasury) {
                    if ($type === \App\Enums\DocumentType::PurchaseOrderDocument) { return false; }
                    if ($admin) { return true; }
                    return match($type) {
                        \App\Enums\DocumentType::DigimaintEvidence, \App\Enums\DocumentType::DeliveryNote => $stores,
                        \App\Enums\DocumentType::TelexCopy => $treasury,
                        \App\Enums\DocumentType::ProformaInvoice, \App\Enums\DocumentType::CommercialInvoice => $logistics || $payables,
                        default => $logistics,
                    };
                });
            @endphp
            @if(!$po->status->isTerminal() && $uploadTypes->isNotEmpty())
            <form method="POST" action="{{ route('documents.store', $po) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">@csrf
                <label class="text-sm">Document type<select name="type" class="field">@foreach($uploadTypes as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select></label>
                <label class="text-sm">File (up to 20 MB)<input class="field" type="file" name="file" required></label><x-primary-button>Upload document</x-primary-button>
            </form>@endif
            <div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead><tr><th>File</th><th>Type</th><th>Uploaded by</th><th>Date</th><th></th></tr></thead><tbody>
                @foreach($po->documents as $document)<tr class="border-t"><td class="py-2">{{ $document->original_filename }}</td><td>{{ $document->type->label() }}</td><td>{{ $document->uploader?->name ?? 'Unavailable' }}</td><td>{{ $document->created_at->format('d M Y H:i') }}</td><td><a class="text-indigo-700 underline" href="{{ route('documents.download', $document) }}">Download</a></td></tr>@endforeach
            </tbody></table></div>
        </section>
        @include('requisitions.partials.approvals')
        <section class="bg-white p-6 rounded shadow-sm"><h3 class="font-semibold mb-3">Status history</h3>@forelse($po->logs->sortByDesc('id') as $log)<p class="text-sm py-2 border-t">{{ $log->created_at->format('d M Y H:i T') }} · {{ $log->from_status->value }} → {{ $log->to_status->value }} · {{ $log->actor?->name ?? 'Unavailable' }} · {{ $log->note }}</p>@empty<p>No transitions yet.</p>@endforelse</section>
        <section class="bg-white p-6 rounded shadow-sm"><h3 class="font-semibold mb-3">Activity and amount history</h3>@foreach($auditEntries as $entry)<p class="text-sm py-1">{{ $entry->created_at->format('d M Y H:i T') }} · {{ $entry->action }} · User #{{ $entry->actor_id }} · {{ $entry->reason }}</p>@endforeach
        @foreach($amountChanges as $change)<details class="border-t mt-2 p-2 text-sm"><summary>{{ $change->action }} · {{ $change->created_at->format('d M Y H:i') }} · {{ $change->reason }}</summary><pre class="whitespace-pre-wrap break-words">{{ json_encode(['before' => $change->before, 'after' => $change->after], JSON_PRETTY_PRINT) }}</pre></details>@endforeach</section>
    </div></div>
</x-app-layout>

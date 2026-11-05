<x-app-layout>
    <x-slot name="header"><h2>Operations overview</h2></x-slot>
    <div class="dashboard-intro">
        <p>Welcome back, <strong>{{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</strong>. Here is what needs your attention today.</p>
        @can('create', \App\Models\PurchaseOrder::class)<a href="{{ route('requisitions.create') }}" class="btn-primary"><x-icon name="plus" /> New requisition</a>@endcan
    </div>
    @if(session('status'))<div role="status" class="mb-5 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>@endif
    @php
        $attention = $actionable->count() + $openQueries->count() + $paymentsToConfirm->count() + $handoversToVerify->count();
        $stats = [
            ['label' => 'Active requisitions', 'value' => $overview['active'], 'note' => 'Across the procurement workflow', 'icon' => 'clipboard'],
            ['label' => 'Awaiting your action', 'value' => $attention, 'note' => 'Approvals, queries and verification', 'icon' => 'clock'],
            ['label' => 'Shipments in transit', 'value' => $overview['in_transit'], 'note' => 'On the way to your stores', 'icon' => 'truck'],
            ['label' => 'Completed orders', 'value' => $overview['closed'], 'note' => 'Verified and closed', 'icon' => 'check'],
        ];
    @endphp
    <div class="stats-grid">
        @foreach($stats as $stat)<article class="stat-card"><div class="stat-top"><span class="stat-label">{{ $stat['label'] }}</span><span class="stat-icon"><x-icon :name="$stat['icon']" /></span></div><p class="stat-value">{{ number_format($stat['value']) }}</p><p class="stat-note">{{ $stat['note'] }}</p></article>@endforeach
    </div>
    <div class="dashboard-grid">
        <div class="dashboard-column">
            <section class="panel">
                <div class="panel-header"><div><h3>Awaiting your action <span class="count-pill">{{ $actionable->count() + $paymentsToConfirm->count() + $handoversToVerify->count() }}</span></h3><p>Your next steps, all in one place.</p></div><x-icon name="clock" class="text-gray-400" /></div>
                @foreach($actionable as $approval)
                    <a href="{{ route('requisitions.show', $approval->order()->id) }}" class="queue-item"><div><strong>{{ $approval->order()->reference }}</strong><small>{{ ucwords(str_replace('_', ' ', $approval->stage)) }} approval · Step {{ $approval->step_order }}</small></div><x-status-badge :status="$approval->order()->status" /><x-icon name="chevron" /></a>
                @endforeach
                @foreach($paymentsToConfirm as $invoice)<a href="{{ route('requisitions.show', $invoice->purchaseOrder) }}" class="queue-item"><div><strong>{{ $invoice->reference }}</strong><small>{{ $invoice->purchaseOrder->reference }} · Approved invoice awaiting payment confirmation</small></div><span class="status-badge status-warning">Treasury action</span><x-icon name="chevron" /></a>@endforeach
                @foreach($handoversToVerify as $handover)<a href="{{ route('requisitions.show', $handover->purchaseOrder) }}" class="queue-item"><div><strong>{{ $handover->purchaseOrder->reference }}</strong><small>{{ ucwords(str_replace('_', ' ', $handover->activity)) }} · {{ $handover->transaction_reference }}</small></div><span class="status-badge status-warning">Verify handover</span><x-icon name="chevron" /></a>@endforeach
                @if($actionable->isEmpty() && $paymentsToConfirm->isEmpty() && $handoversToVerify->isEmpty())<div class="empty-state"><span class="empty-symbol"><x-icon name="check" /></span><strong>You are all caught up</strong><p>No approvals, payments or handovers are waiting on you right now.</p></div>@endif
            </section>
            <section class="panel">
                <div class="panel-header"><div><h3>Recent requisitions</h3><p>The latest activity in your workspace.</p></div><a href="{{ route('requisitions.index') }}" class="text-link">View all <x-icon name="arrow" /></a></div>
                <div class="table-scroll"><table><thead><tr><th>Requisition</th><th>Status</th><th>Parts</th><th>Raised</th><th><span class="sr-only">Open requisition</span></th></tr></thead><tbody>
                    @forelse($recentPos as $po)<tr><td><a class="reference-link" href="{{ route('requisitions.show', $po) }}">{{ $po->reference }}</a><span class="reference-description">{{ $po->lines->first()?->description ?? 'Part requirement' }}</span></td><td><x-status-badge :status="$po->status" /></td><td>{{ $po->lines->count() }}</td><td class="whitespace-nowrap">{{ $po->created_at->format('d M Y') }}</td><td><a href="{{ route('requisitions.show', $po) }}" aria-label="Open {{ $po->reference }}"><x-icon name="chevron" class="h-3 w-3 text-gray-400" /></a></td></tr>
                    @empty<tr><td colspan="5"><div class="empty-state"><span class="empty-symbol"><x-icon name="clipboard" /></span><strong>No requisitions yet</strong><p>New part requirements will appear here as they are raised.</p>@can('create', \App\Models\PurchaseOrder::class)<a class="text-link mt-4" href="{{ route('requisitions.create') }}">Raise your first requisition <x-icon name="arrow" /></a>@endcan</div></td></tr>@endforelse
                </tbody></table></div>
            </section>
        </div>
        <div class="dashboard-column">
            <section class="panel"><div class="panel-header"><div><h3>Procurement pipeline</h3><p>{{ number_format($overview['active']) }} active {{ \Illuminate\Support\Str::plural('requisition', $overview['active']) }}</p></div><x-icon name="chart" class="text-gray-400" /></div>
                <div class="workflow-summary">@foreach($pipeline as $label => $count)<div class="workflow-row"><div class="workflow-label"><span>{{ $label }}</span><strong>{{ $count }}</strong></div><div class="workflow-track" role="img" aria-label="{{ $label }}: {{ $count }} of {{ $overview['active'] }} active requisitions"><span class="workflow-fill workflow-fill-{{ $loop->index }}" style="width: {{ round($count / max($overview['active'], 1) * 100, 1) }}%"></span></div></div>@endforeach</div>
            </section>
            <section class="panel"><div class="panel-header"><div><h3>Questions for you <span class="count-pill">{{ $openQueries->count() }}</span></h3><p>Keep decisions moving with a reply.</p></div><x-icon name="message" class="text-gray-400" /></div>
                @forelse($openQueries as $query)<a href="{{ route('requisitions.show', $query->approval->order()->id) }}" class="queue-item"><div><strong>{{ $query->asker->name }}</strong><small>{{ $query->approval->order()->reference }} · {{ $query->question }}</small></div><x-icon name="chevron" /></a>@empty<div class="empty-state"><strong>No open questions</strong><p>Queries directed to you will appear here.</p></div>@endforelse
            </section>
            <aside class="boundary-note"><x-icon name="shield" /><div><h3>DigiMaint remains your stock authority</h3><p>Record manual stock checks, receipts and issues with evidence. A separate supervisor verifies each handover.</p></div></aside>
        </div>
    </div>
</x-app-layout>

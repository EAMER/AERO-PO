<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reports & insights</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="stats-grid report-stats">
                <article class="stat-card"><div class="stat-top"><span class="stat-label">Total requisitions</span><span class="stat-icon"><x-icon name="clipboard" /></span></div><p class="stat-value">{{ number_format($total) }}</p><p class="stat-note">All requests in this company</p></article>
                <article class="stat-card"><div class="stat-top"><span class="stat-label">Closed orders</span><span class="stat-icon"><x-icon name="check" /></span></div><p class="stat-value">{{ number_format($counts['closed'] ?? 0) }}</p><p class="stat-note">Verified and completed</p></article>
                <article class="stat-card"><div class="stat-top"><span class="stat-label">Average cycle time</span><span class="stat-icon"><x-icon name="clock" /></span></div><p class="stat-value">{{ $averageCycleDays !== null ? $averageCycleDays : '—' }}@if($averageCycleDays !== null)<span class="text-sm font-medium tracking-normal text-gray-400"> days</span>@endif</p><p class="stat-note">From request to closure</p></article>
            </div>

            {{-- Longest waiting --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Waiting longest in their current stage</h3>
                @forelse ($longestWaiting as $row)
                    <a href="{{ route('requisitions.show', $row['po']) }}"
                       class="flex items-center justify-between py-2 border-b last:border-0 hover:bg-gray-50 -mx-2 px-2 rounded">
                        <span class="text-sm">
                            <span class="font-medium">{{ $row['po']->reference }}</span>
                            <x-status-badge :status="$row['po']->status" class="ml-2" />
                        </span>
                        <span class="text-sm text-gray-500">{{ $row['days'] }} days</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No open requisitions.</p>
                @endforelse
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Count by status --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Requisitions by status</h3>
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($counts as $status => $count)
                                <tr>
                                    <td class="py-2"><x-status-badge :status="$status" /></td>
                                    <td class="py-2 text-right font-medium">{{ $count }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-2 text-gray-400">No requisitions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Average time per stage --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Average time spent in each stage</h3>
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($stages as $row)
                                <tr>
                                    <td class="py-2"><x-status-badge :status="$row['status']" /></td>
                                    <td class="py-2 text-right font-medium">{{ $row['avg_days'] }} days</td>
                                    <td class="py-2 text-right text-xs text-gray-400">{{ $row['samples'] }} {{ \Illuminate\Support\Str::plural('sample', $row['samples']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-2 text-gray-400">No completed stages yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <section class="bg-white p-6 rounded shadow-sm space-y-3"><h3 class="font-semibold">Tenant-defined KPIs</h3>@forelse($kpis as $row)<p>{{ $row['definition']->name }} · Actual {{ $row['actual'] ?? 'No completed data' }} · {{ str_replace('_', ' ', $row['definition']->direction) }} {{ $row['definition']->target }} · {{ $row['met'] === null ? 'Unmeasured' : ($row['met'] ? 'Target met' : 'Outside target') }}</p>@empty<p>The TLM has not defined KPI targets.</p>@endforelse</section>
            <section class="bg-white p-6 rounded shadow-sm space-y-3"><h3 class="font-semibold">Current-stage SLA timers</h3>@forelse($stageTimers as $row)<p class="{{ $row['overdue'] ? 'text-red-700' : 'text-gray-700' }}"><a class="underline" href="{{ route('requisitions.show', $row['po']) }}">{{ $row['po']->reference }}</a> · {{ $row['po']->status->value }} · {{ $row['hours'] }} hours · Target {{ $row['target_hours'] ?: 'undefined' }} {{ $row['overdue'] ? '— overdue' : '' }}</p>@empty<p>No open orders.</p>@endforelse</section>
            <section class="bg-white p-6 rounded shadow-sm space-y-3"><h3 class="font-semibold">Open approval-step timers</h3>@forelse($approvalTimers as $row)<p class="{{ $row['overdue'] ? 'text-red-700' : 'text-gray-700' }}"><a class="underline" href="{{ route('requisitions.show', $row['approval']->order()->id) }}">{{ $row['approval']->order()->reference }}</a> · {{ $row['approval']->stage }} · {{ $row['approval']->role->value }} · {{ $row['hours'] }} hours · Due {{ $row['approval']->due_at?->format('d M Y H:i T') ?? 'not defined' }}</p>@empty<p>No open approval steps.</p>@endforelse</section>
            <section class="bg-white p-6 rounded shadow-sm space-y-3"><h3 class="font-semibold">Manual DigiMaint entries awaiting resolution</h3>@forelse($pendingHandovers as $handover)<p><a class="underline" href="{{ route('requisitions.show', $handover->purchaseOrder) }}">{{ $handover->purchaseOrder->reference }}</a> · {{ $handover->activity }} · {{ $handover->transaction_reference }} · {{ $handover->status }}</p>@empty<p>No unresolved handover entries.</p>@endforelse</section>
        </div>
    </div>
</x-app-layout>

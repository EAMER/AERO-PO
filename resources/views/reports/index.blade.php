<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reports</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Headline numbers --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <p class="text-xs uppercase text-gray-500">Total requisitions</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $total }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <p class="text-xs uppercase text-gray-500">Closed</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $counts['closed'] ?? 0 }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <p class="text-xs uppercase text-gray-500">Average cycle time</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">
                        {{ $averageCycleDays !== null ? $averageCycleDays.' days' : '—' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400">Raised to closed. Needs at least one closed PO.</p>
                </div>
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

            <p class="text-xs text-gray-400">
                These figures report what happened. They aren't measured against targets because KPIs haven't been defined yet.
            </p>
        </div>
    </div>
</x-app-layout>

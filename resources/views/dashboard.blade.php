<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">My queue</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            {{-- Awaiting your approval action --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">
                    Awaiting your action
                    @if ($actionable->isNotEmpty())
                        <span class="ml-1 inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-indigo-600 rounded-full">{{ $actionable->count() }}</span>
                    @endif
                </h3>

                @forelse ($actionable as $approval)
                    <a href="{{ route('requisitions.show', $approval->approvable_id) }}"
                       class="flex items-center justify-between py-2 border-b last:border-0 hover:bg-gray-50 -mx-2 px-2 rounded">
                        <span class="text-sm">
                            <span class="font-medium">{{ $approval->approvable->reference ?? '#'.$approval->approvable_id }}</span>
                            <span class="text-gray-400">— {{ ucwords($approval->stage) }} step #{{ $approval->step_order }}</span>
                        </span>
                        <x-status-badge :status="$approval->approvable->status ?? 'pending_approval'" />
                    </a>
                @empty
                    <p class="text-sm text-gray-400">Nothing waiting on you right now.</p>
                @endforelse
            </div>

            {{-- Open queries directed at you --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">
                    Questions for you
                    @if ($openQueries->isNotEmpty())
                        <span class="ml-1 inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-yellow-500 rounded-full">{{ $openQueries->count() }}</span>
                    @endif
                </h3>

                @forelse ($openQueries as $query)
                    <a href="{{ route('requisitions.show', $query->approval->approvable_id) }}"
                       class="block py-2 border-b last:border-0 hover:bg-gray-50 -mx-2 px-2 rounded">
                        <p class="text-sm">
                            <span class="font-medium">{{ $query->asker->name }}</span> asked about
                            <span class="font-medium">{{ $query->approval->approvable->reference ?? '#'.$query->approval->approvable_id }}</span>:
                        </p>
                        <p class="text-sm text-gray-500">{{ $query->question }}</p>
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No open questions for you.</p>
                @endforelse
            </div>

            {{-- Recent requisitions --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700">Recent requisitions</h3>
                    <a href="{{ route('requisitions.index') }}" class="text-xs text-indigo-600 hover:underline">View all &rarr;</a>
                </div>

                @forelse ($recentPos as $po)
                    <a href="{{ route('requisitions.show', $po) }}"
                       class="flex items-center justify-between py-2 border-b last:border-0 hover:bg-gray-50 -mx-2 px-2 rounded">
                        <span class="text-sm font-medium">{{ $po->reference }}</span>
                        <x-status-badge :status="$po->status" />
                    </a>
                @empty
                    <p class="text-sm text-gray-400">
                        No requisitions yet. <a href="{{ route('requisitions.create') }}" class="text-indigo-600 underline">Raise one</a>.
                    </p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $po->reference }}
                <x-status-badge :status="$po->status" class="ml-2" />
            </h2>
            <a href="{{ route('requisitions.index') }}" class="text-sm text-gray-500 hover:underline">&larr; All requisitions</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    @foreach ($errors->all() as $error) <p>{{ $error }}</p> @endforeach
                </div>
            @endif

            {{-- Actions --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Actions</h3>
                <div class="flex flex-wrap gap-2">
                    @if ($po->status->value === 'requisition_raised')
                        <form method="POST" action="{{ route('requisitions.check-stock', $po) }}">
                            @csrf
                            <button class="px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-500">
                                Check stock (DigiMaint)
                            </button>
                        </form>
                    @endif

                    @if ($po->status->value === 'stock_checked')
                        <form method="POST" action="{{ route('requisitions.mark-rfq-sent', $po) }}">
                            @csrf
                            <button class="px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-500">
                                Mark RFQs sent to vendors
                            </button>
                        </form>
                    @endif

                    @if ($po->status->value === 'rfq_sent')
                        <form method="POST" action="{{ route('requisitions.mark-quotes-in', $po) }}">
                            @csrf
                            <button class="px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-500">
                                Mark quotations received
                            </button>
                        </form>
                    @endif

                    @if ($po->status->value === 'quotes_in')
                        <form method="POST" action="{{ route('requisitions.submit', $po) }}">
                            @csrf
                            <button class="px-3 py-2 bg-green-600 text-white text-xs font-semibold rounded-md hover:bg-green-500">
                                Submit for approval
                            </button>
                        </form>
                    @endif

                    @if (in_array($po->status->value, ['fulfilled_from_stock', 'pending_approval', 'pending_cfo', 'po_sent', 'closed', 'rejected', 'cancelled']))
                        <span class="text-xs text-gray-400 self-center">No further action here for this stage.</span>
                    @endif
                </div>
            </div>

            {{-- Part lines --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Part lines</h3>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase">
                            <th class="pb-2">Part number</th>
                            <th class="pb-2">Description</th>
                            <th class="pb-2">Qty</th>
                            <th class="pb-2">Condition</th>
                            <th class="pb-2">Stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($po->lines as $line)
                            <tr>
                                <td class="py-2">{{ $line->part_number }}</td>
                                <td class="py-2">{{ $line->description }}</td>
                                <td class="py-2">{{ $line->quantity }}</td>
                                <td class="py-2">{{ $line->condition ?? '—' }}</td>
                                <td class="py-2">
                                    @if (is_null($line->stock_available))
                                        <span class="text-gray-400">Not checked</span>
                                    @elseif ($line->stock_available)
                                        <span class="text-green-600">In stock</span>
                                    @else
                                        <span class="text-red-600">Short</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Documents / evaluation pack checklist --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Evaluation pack checklist (manual 7.1.2)</h3>
                <ul class="mb-4 space-y-1 text-sm">
                    @foreach ($checklist as $row)
                        <li class="flex items-center gap-2">
                            <span class="{{ $row['met'] ? 'text-green-600' : 'text-red-500' }}">
                                {{ $row['met'] ? '✓' : '✗' }}
                            </span>
                            {{ ucwords(str_replace('_', ' ', $row['type'])) }}
                            <span class="text-gray-400">({{ $row['have'] }}/{{ $row['required'] }})</span>
                        </li>
                    @endforeach
                </ul>

                <form method="POST" action="{{ route('documents.store', $po) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2 mb-4 border-t pt-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Document type</label>
                        <select name="type" class="mt-1 rounded-md border-gray-300 shadow-sm text-sm">
                            @foreach (\App\Enums\DocumentType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">File</label>
                        <input type="file" name="file" required class="mt-1 text-sm">
                    </div>
                    <button class="px-3 py-2 bg-gray-800 text-white text-xs font-semibold rounded-md hover:bg-gray-700">Upload</button>
                </form>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase">
                            <th class="pb-2">File</th>
                            <th class="pb-2">Type</th>
                            <th class="pb-2">Uploaded by</th>
                            <th class="pb-2">Date</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($po->documents as $doc)
                            <tr>
                                <td class="py-2">{{ $doc->original_filename }}</td>
                                <td class="py-2">{{ $doc->type->label() }}</td>
                                <td class="py-2">{{ $doc->uploader->name ?? '—' }}</td>
                                <td class="py-2">{{ $doc->created_at->format('d M Y H:i') }}</td>
                                <td class="py-2">
                                    <a href="{{ route('documents.download', $doc) }}" class="text-indigo-600 hover:underline">Download</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-gray-400">No documents uploaded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Approvals --}}
            @if ($po->approvals->isNotEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Approvals</h3>
                    <div class="space-y-4">
                        @foreach ($po->approvals->sortBy(['stage', 'step_order']) as $approval)
                            <div class="border rounded-md p-4 {{ ($actionable[$approval->id] ?? false) ? 'border-indigo-300 bg-indigo-50/30' : 'border-gray-200' }}">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-gray-400">#{{ $approval->step_order }}</span>
                                    <span class="font-medium">{{ ucwords(str_replace('_', ' ', $approval->role->value)) }}</span>
                                    <span class="text-gray-400">({{ $approval->stage }})</span>
                                    <x-status-badge :status="$approval->decision->value" />
                                    @if ($approval->decidedBy)
                                        <span class="text-gray-400">— {{ $approval->decidedBy->name }}</span>
                                    @endif
                                </div>

                                {{-- Past queries on this step --}}
                                @foreach ($approval->queries as $q)
                                    <div class="ml-4 mb-2 text-xs bg-gray-50 border border-gray-200 rounded p-2">
                                        <p><span class="font-medium">{{ $q->asker->name }}</span> asked <span class="font-medium">{{ $q->target->name }}</span>: {{ $q->question }}</p>
                                        @if ($q->answer)
                                            <p class="mt-1 text-gray-600"><span class="font-medium">{{ $q->target->name }}</span> answered: {{ $q->answer }}</p>
                                        @elseif ($q->directed_to === auth()->id())
                                            <form method="POST" action="{{ route('approvals.queries.answer', $q) }}" class="mt-2 flex gap-2">
                                                @csrf
                                                <input type="text" name="answer" required placeholder="Your answer..."
                                                       class="flex-1 rounded-md border-gray-300 text-xs shadow-sm">
                                                <button class="px-2 py-1 bg-gray-800 text-white text-xs rounded-md hover:bg-gray-700">Answer</button>
                                            </form>
                                        @else
                                            <p class="mt-1 text-gray-400 italic">Awaiting {{ $q->target->name }}'s answer.</p>
                                        @endif
                                    </div>
                                @endforeach

                                {{-- Actions: approve / reject / query --}}
                                @if ($actionable[$approval->id] ?? false)
                                    <div class="ml-4 flex flex-wrap gap-2 mt-2" x-data="{ open: null }">
                                        <form method="POST" action="{{ route('approvals.approve', $approval) }}">
                                            @csrf
                                            <button class="px-3 py-1.5 bg-green-600 text-white text-xs font-semibold rounded-md hover:bg-green-500">Approve</button>
                                        </form>

                                        <button type="button" @click="open = (open === 'reject' ? null : 'reject')"
                                                class="px-3 py-1.5 bg-red-600 text-white text-xs font-semibold rounded-md hover:bg-red-500">
                                            Reject
                                        </button>
                                        <button type="button" @click="open = (open === 'query' ? null : 'query')"
                                                class="px-3 py-1.5 bg-yellow-500 text-white text-xs font-semibold rounded-md hover:bg-yellow-400">
                                            Query
                                        </button>

                                        <div x-show="open === 'reject'" class="w-full mt-2">
                                            <form method="POST" action="{{ route('approvals.reject', $approval) }}" class="flex gap-2">
                                                @csrf
                                                <input type="text" name="reason" required placeholder="Reason for rejecting (required)"
                                                       class="flex-1 rounded-md border-gray-300 text-xs shadow-sm">
                                                <button class="px-3 py-1.5 bg-red-600 text-white text-xs rounded-md hover:bg-red-500">Confirm reject</button>
                                            </form>
                                        </div>

                                        <div x-show="open === 'query'" class="w-full mt-2">
                                            <form method="POST" action="{{ route('approvals.query', $approval) }}" class="flex flex-wrap gap-2">
                                                @csrf
                                                <select name="target_user_id" required class="rounded-md border-gray-300 text-xs shadow-sm">
                                                    <option value="">Direct to...</option>
                                                    @foreach ($targetsByApproval[$approval->id] ?? [] as $u)
                                                        <option value="{{ $u->id }}">{{ $u->name }} ({{ ucwords(str_replace('_', ' ', $u->role->value)) }})</option>
                                                    @endforeach
                                                </select>
                                                <input type="text" name="question" required placeholder="Your question..."
                                                       class="flex-1 rounded-md border-gray-300 text-xs shadow-sm">
                                                <button class="px-3 py-1.5 bg-yellow-500 text-white text-xs rounded-md hover:bg-yellow-400">Send query</button>
                                            </form>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Status timeline --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Status history</h3>
                <ol class="space-y-2 text-sm">
                    @foreach ($po->logs->sortByDesc('created_at') as $log)
                        <li class="flex items-center gap-2">
                            <span class="text-gray-400">{{ $log->created_at->format('d M Y H:i') }}</span>
                            <x-status-badge :status="$log->from_status->value" />
                            <span>&rarr;</span>
                            <x-status-badge :status="$log->to_status->value" />
                            <span class="text-gray-400">{{ $log->actor->name ?? 'system' }}</span>
                            @if ($log->note)
                                <span class="text-gray-400">— {{ $log->note }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>

        </div>
    </div>
</x-app-layout>

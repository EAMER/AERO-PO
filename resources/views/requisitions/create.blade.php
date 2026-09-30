<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Raise a requisition</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('requisitions.store') }}" x-data="requisitionForm()">
                    @csrf

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">Reference</label>
                        <input type="text" name="reference" value="{{ old('reference') }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                               placeholder="e.g. REQ-2026-0142">
                    </div>

                    <div class="mb-6">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="is_amo_request" value="1" @checked(old('is_amo_request'))
                                   class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <span class="ml-2 text-sm text-gray-700">This is an AMO purchase request (adds a HAMO approval step)</span>
                        </label>
                    </div>

                    <div class="mb-2 flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700">Part lines</label>
                        <button type="button" @click="addLine()" class="text-sm text-indigo-600 hover:underline">+ Add line</button>
                    </div>

                    <template x-for="(line, i) in lines" :key="i">
                        <div class="grid grid-cols-12 gap-2 mb-2 items-start">
                            <input type="text" :name="`lines[${i}][part_number]`" x-model="line.part_number"
                                   placeholder="Part number" required
                                   class="col-span-3 rounded-md border-gray-300 shadow-sm text-sm">
                            <input type="text" :name="`lines[${i}][description]`" x-model="line.description"
                                   placeholder="Description" required
                                   class="col-span-4 rounded-md border-gray-300 shadow-sm text-sm">
                            <input type="number" min="1" :name="`lines[${i}][quantity]`" x-model="line.quantity"
                                   placeholder="Qty" required
                                   class="col-span-2 rounded-md border-gray-300 shadow-sm text-sm">
                            <input type="text" :name="`lines[${i}][condition]`" x-model="line.condition"
                                   placeholder="Condition (optional)"
                                   class="col-span-2 rounded-md border-gray-300 shadow-sm text-sm">
                            <button type="button" @click="removeLine(i)" x-show="lines.length > 1"
                                    class="col-span-1 text-red-500 hover:text-red-700 text-sm">&times;</button>
                        </div>
                    </template>

                    <div class="mt-6 flex justify-end">
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Raise requisition
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function requisitionForm() {
            return {
                lines: [{ part_number: '', description: '', quantity: 1, condition: '' }],
                addLine() { this.lines.push({ part_number: '', description: '', quantity: 1, condition: '' }); },
                removeLine(i) { this.lines.splice(i, 1); },
            };
        }
    </script>
</x-app-layout>

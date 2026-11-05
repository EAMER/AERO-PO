<x-app-layout>
    <x-slot name="header"><div class="flex items-center justify-between gap-4"><h2>Raise a requisition</h2><a href="{{ route('requisitions.index') }}" class="btn-secondary">All requisitions</a></div></x-slot>
    <p class="list-intro">Tell Stores what you need. Your request starts with a verified DigiMaint stock check.</p>
    <section class="panel">
        <form method="POST" action="{{ route('requisitions.store') }}" class="request-form" x-data="requisitionForm(@js(old('lines', [['part_number' => '', 'description' => '', 'quantity' => 1, 'condition' => '']])))">
            @csrf
            @if($errors->any())<div role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><ul class="list-disc list-inside">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="form-section-title"><h3>01 <span class="ml-2">Request details</span></h3><p>A clear reference makes the requirement easy to follow through every stage.</p></div>
            <div class="grid gap-6 md:grid-cols-2 mb-8">
                <label for="reference">Requisition reference <span class="text-red-600">*</span><input id="reference" class="field" type="text" name="reference" value="{{ old('reference') }}" required maxlength="100" placeholder="e.g. REQ-2026-0142"><span class="block mt-2 text-xs text-gray-400 font-normal">Use your company's unique request reference.</span></label>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4"><label for="is_amo_request" class="flex items-start gap-3"><input id="is_amo_request" type="checkbox" name="is_amo_request" value="1" class="brand-checkbox mt-1" @checked(old('is_amo_request'))><span>AMO purchase request<span class="block mt-1 text-xs text-gray-500 font-normal">Includes HAMO in the evaluation approval chain.</span></span></label></div>
            </div>
            <div class="form-section-title flex items-center justify-between gap-4"><div><h3>02 <span class="ml-2">Required parts</span></h3><p>Add the part number, description and quantity for each line.</p></div><button type="button" @click="addLine()" :disabled="lines.length >= 100" class="btn-secondary"><x-icon name="plus" /> Add line</button></div>
            <template x-for="(line, i) in lines" :key="line._key">
                <div class="part-line">
                    <div class="part-line-header"><span>PART LINE <span x-text="String(i + 1).padStart(2, '0')"></span></span><button type="button" @click="removeLine(i)" x-show="lines.length > 1" :aria-label="'Remove part line ' + (i + 1)" class="text-red-700 p-1"><x-icon name="close" class="h-4 w-4" /></button></div>
                    <div class="part-line-fields">
                        <label :for="'part-' + line._key">Part number <span class="text-red-600">*</span><input :id="'part-' + line._key" :name="'lines[' + i + '][part_number]'" class="field" x-model="line.part_number" type="text" maxlength="100" placeholder="Part number" required></label>
                        <label :for="'description-' + line._key">Description <span class="text-red-600">*</span><input :id="'description-' + line._key" :name="'lines[' + i + '][description]'" class="field" x-model="line.description" type="text" maxlength="255" placeholder="What is the part?" required></label>
                        <label :for="'quantity-' + line._key">Quantity <span class="text-red-600">*</span><input :id="'quantity-' + line._key" :name="'lines[' + i + '][quantity]'" class="field" x-model="line.quantity" type="number" min="1" max="1000000" required></label>
                        <label :for="'condition-' + line._key">Condition<input :id="'condition-' + line._key" :name="'lines[' + i + '][condition]'" class="field" x-model="line.condition" type="text" maxlength="50" placeholder="e.g. New"></label>
                    </div>
                </div>
            </template>
            <p class="mt-4 text-xs text-gray-500"><span x-text="lines.length"></span> of 100 part lines. Stock availability will be confirmed by Stores.</p>
            <div class="form-actions"><a href="{{ route('requisitions.index') }}" class="btn-secondary">Cancel</a><x-primary-button>Raise requisition <x-icon name="arrow" /></x-primary-button></div>
        </form>
    </section>
    <script>
        function requisitionForm(initialLines) {
            return {
                lines: initialLines.map((line, index) => ({ ...line, _key: index })),
                nextKey: initialLines.length,
                addLine() {
                    if (this.lines.length < 100) {
                        this.lines.push({ _key: this.nextKey++, part_number: '', description: '', quantity: 1, condition: '' });
                    }
                },
                removeLine(index) {
                    if (this.lines.length > 1) {
                        this.lines.splice(index, 1);
                    }
                },
            };
        }
    </script>
</x-app-layout>

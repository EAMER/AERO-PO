<x-app-layout>
    <x-slot name="header"><h2>Vendors & carriers</h2></x-slot>
    <p class="list-intro">Your company directory of parts suppliers, freight forwarders and courier accounts.</p>
    @if(session('status'))<div role="status" class="mb-5 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="mb-5 rounded-lg bg-red-50 p-4 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="space-y-6">
        @can('create', \App\Models\Vendor::class)
        <details class="panel vendor-create" @if($errors->any()) open @endif>
            <summary class="panel-header cursor-pointer"><span><span class="flex items-center gap-3 font-semibold text-sm text-gray-800"><x-icon name="plus" /> Add a supplier or carrier</span><span class="block mt-2 text-xs text-gray-500">New directory entries require approval before RFQs can be sent.</span></span><span class="text-xs text-indigo-700">Enter details</span></summary>
            <form method="POST" action="{{ route('vendors.store') }}" class="grid gap-5 p-6 sm:grid-cols-2 lg:grid-cols-3">@csrf
                <label>Name<input class="field" name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Company or account name"></label>
                <label>Email<input class="field" name="email" type="email" value="{{ old('email') }}" required maxlength="255" placeholder="quotes@supplier.com"></label>
                <label>Directory type<select class="field" name="kind">@foreach(['supplier' => 'Parts supplier', 'freight_forwarder' => 'Freight forwarder', 'courier' => 'Courier account'] as $value => $label)<option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>@endforeach</select></label>
                <label>Payment terms<select class="field" name="payment_terms"><option value="net" @selected(old('payment_terms') === 'net')>Net terms</option><option value="cia" @selected(old('payment_terms') === 'cia')>Cash in advance</option></select></label>
                <label>Account reference<input class="field" name="account_reference" value="{{ old('account_reference') }}" maxlength="100" placeholder="Optional account number"></label>
                <label>Address<textarea class="field" name="address" maxlength="2000" rows="2" placeholder="Company address">{{ old('address') }}</textarea></label>
                <div class="sm:col-span-2 lg:col-span-3"><x-primary-button>Add for approval <x-icon name="arrow" /></x-primary-button></div>
            </form>
        </details>
        @endcan
        <section class="panel">
            <div class="panel-header"><div><h3>Company directory <span class="count-pill">{{ $vendors->total() }}</span></h3><p>Approved entries are available to the sourcing and shipping teams.</p></div><x-icon name="vendors" class="text-gray-400" /></div>
            <div class="divide-y divide-gray-100">
                @forelse($vendors as $vendor)
                <article class="vendor-entry">
                    <div class="vendor-overview">
                        <span class="vendor-symbol"><x-icon :name="$vendor->kind === 'supplier' ? 'vendors' : 'truck'" /></span>
                        <div class="vendor-identity"><h4>{{ $vendor->name }}</h4><p>{{ $vendor->email }}</p><span>{{ match($vendor->kind) { 'freight_forwarder' => 'Freight forwarder', 'courier' => 'Courier account', default => 'Parts supplier' } }}<span class="mx-2 text-gray-300">/</span>{{ $vendor->payment_terms === 'cia' ? 'Cash in advance' : 'Net terms' }}@if($vendor->account_reference)<span class="mx-2 text-gray-300">/</span>{{ $vendor->account_reference }}@endif</span></div>
                        <div class="vendor-state"><span class="status-badge {{ !$vendor->is_active ? 'status-danger' : ($vendor->is_approved ? 'status-success' : 'status-warning') }}">{{ !$vendor->is_active ? 'Inactive' : ($vendor->is_approved ? 'Approved' : 'Awaiting approval') }}</span>
                            @can('approve', $vendor)<form method="POST" action="{{ route('vendors.approve', $vendor) }}">@csrf<input type="hidden" name="is_approved" value="{{ $vendor->is_approved ? '0' : '1' }}"><button type="submit" class="{{ $vendor->is_approved ? 'btn-secondary' : 'btn-primary' }}">{{ $vendor->is_approved ? 'Withdraw approval' : 'Approve vendor' }}</button></form>@endcan
                        </div>
                    </div>
                    @can('update', $vendor)
                    <details class="vendor-edit"><summary>Edit details <span class="font-normal text-gray-400">— changes require renewed approval</span></summary>
                        <form method="POST" action="{{ route('vendors.update', $vendor) }}" class="grid gap-4 mt-5 sm:grid-cols-2 lg:grid-cols-3">@csrf @method('PATCH')
                            <label>Name<input class="field" name="name" value="{{ $vendor->name }}" required maxlength="255"></label>
                            <label>Email<input class="field" name="email" type="email" value="{{ $vendor->email }}" required maxlength="255"></label>
                            <label>Directory type<select class="field" name="kind">@foreach(['supplier' => 'Parts supplier', 'freight_forwarder' => 'Freight forwarder', 'courier' => 'Courier account'] as $kind => $label)<option value="{{ $kind }}" @selected($vendor->kind === $kind)>{{ $label }}</option>@endforeach</select></label>
                            <label>Payment terms<select class="field" name="payment_terms"><option value="net" @selected($vendor->payment_terms === 'net')>Net terms</option><option value="cia" @selected($vendor->payment_terms === 'cia')>Cash in advance</option></select></label>
                            <label>Account reference<input class="field" name="account_reference" value="{{ $vendor->account_reference }}" maxlength="100"></label>
                            <label>Address<textarea class="field" name="address" maxlength="2000" rows="2">{{ $vendor->address }}</textarea></label>
                            <label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="brand-checkbox" name="is_active" value="1" @checked($vendor->is_active)> Active directory entry</label>
                            <div class="sm:col-span-2"><x-primary-button>Save revised details</x-primary-button></div>
                        </form>
                    </details>@endcan
                </article>
                @empty<div class="empty-state"><span class="empty-symbol"><x-icon name="vendors" /></span><strong>No vendors registered yet</strong><p>Add suppliers and carrier accounts to begin building your approved directory.</p></div>@endforelse
            </div>
            @if($vendors->hasPages())<div class="table-footer">{{ $vendors->links() }}</div>@endif
        </section>
    </div>
</x-app-layout>

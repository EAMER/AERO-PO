<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\SourcingService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Vendor::class);

        return view('vendors.index', ['vendors' => Vendor::orderBy('name')->paginate(20)]);
    }

    public function store(Request $request, SourcingService $service): RedirectResponse
    {
        $this->authorize('create', Vendor::class);
        $data = $this->validated($request);

        return $this->complete(fn () => $service->saveVendor($request->user(), $data));
    }

    public function update(Request $request, Vendor $vendor, SourcingService $service): RedirectResponse
    {
        $this->authorize('update', $vendor);
        $data = $this->validated($request, $vendor);

        return $this->complete(fn () => $service->saveVendor($request->user(), $data, $vendor));
    }

    public function approve(Request $request, Vendor $vendor, SourcingService $service): RedirectResponse
    {
        $this->authorize('approve', $vendor);
        $data = $request->validate(['is_approved' => 'required|boolean']);

        return $this->complete(fn () => $service->approveVendor($vendor, $request->user(), (bool) $data['is_approved']));
    }

    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('vendors')->where('tenant_id', TenantContext::id())->where('kind', $request->input('kind'))->ignore($vendor?->id)],
            'kind' => 'required|in:supplier,freight_forwarder,courier', 'payment_terms' => 'required|in:net,cia',
            'address' => 'nullable|string|max:2000', 'account_reference' => 'nullable|string|max:100', 'is_active' => 'sometimes|boolean',
        ]);
    }
}

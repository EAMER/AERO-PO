<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\ShippingService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShippingController extends Controller
{
    public function __construct(private ShippingService $shipping) {}

    public function recordDetails(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        $data = $request->validate(['total_weight_kg' => 'required|numeric|min:0.01|max:99999999.99', 'contains_dg' => 'sometimes|boolean', 'carrier_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('tenant_id', TenantContext::id())]]);

        return $this->complete(fn () => $this->shipping->recordShipmentDetails($purchaseOrder, $request->user(), (float) $data['total_weight_kg'], (bool) ($data['contains_dg'] ?? false), isset($data['carrier_id']) ? (int) $data['carrier_id'] : null));
    }

    public function markCustomsDutyPaid(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->shipping->markCustomsDutyPaid($purchaseOrder, $request->user()));
    }

    public function dispatch(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        $data = $request->validate(['awb_number' => 'required|string|max:100']);

        return $this->complete(fn () => $this->shipping->dispatch($purchaseOrder, $request->user(), $data['awb_number']));
    }

    public function markDelivered(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->shipping->markDelivered($purchaseOrder, $request->user()));
    }

    public function confirmReceipt(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->shipping->confirmReceipt($purchaseOrder, $request->user()));
    }

    public function issueGrn(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        $data = $request->validate(['grn_number' => 'required|string|max:100']);

        return $this->complete(fn () => $this->shipping->issueGrn($purchaseOrder, $request->user(), $data['grn_number']));
    }

    public function markServiceableLabelApplied(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->shipping->markServiceableLabelApplied($purchaseOrder, $request->user()));
    }

    public function markCardexSent(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->shipping->markCardexSent($purchaseOrder, $request->user()));
    }

    public function closeOut(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->shipping->closeOut($purchaseOrder, $request->user()));
    }

    public function closeFulfilledFromStock(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->shipping->closeFulfilledFromStock($purchaseOrder, $request->user()));
    }
}

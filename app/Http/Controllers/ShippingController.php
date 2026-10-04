<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Services\ShippingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

class ShippingController extends Controller
{
    public function __construct(private ShippingService $shipping) {}

    public function recordDetails(PurchaseOrder $purchaseOrder, Request $request)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $data = $request->validate([
            'total_weight_kg' => 'required|numeric|min:0.01',
            'contains_dg' => 'sometimes|boolean',
        ]);

        try {
            $this->shipping->recordShipmentDetails($purchaseOrder, Auth::user(), (float) $data['total_weight_kg'], $data['contains_dg'] ?? false);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['shipping' => $e->getMessage()]);
        }

        return back()->with('status', 'Shipment details recorded.');
    }

    public function markCustomsDutyPaid(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $this->shipping->markCustomsDutyPaid($purchaseOrder, Auth::user());

        return back()->with('status', 'Customs duty marked as paid.');
    }

    public function dispatch(PurchaseOrder $purchaseOrder, Request $request)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $data = $request->validate(['awb_number' => 'required|string|max:100']);

        try {
            $this->shipping->dispatch($purchaseOrder, Auth::user(), $data['awb_number']);
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['shipping' => $e->getMessage()]);
        }

        return back()->with('status', 'Shipment dispatched.');
    }

    public function markDelivered(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $this->shipping->markDelivered($purchaseOrder, Auth::user());

        return back()->with('status', 'Marked as delivered.');
    }

    public function confirmReceipt(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::StoreStaff, Role::StoreSupervisor]);

        $this->shipping->confirmReceipt($purchaseOrder, Auth::user());

        return back()->with('status', 'Receipt confirmed.');
    }

    public function issueGrn(PurchaseOrder $purchaseOrder, Request $request)
    {
        $this->authorizeRole([Role::StoreStaff, Role::StoreSupervisor, Role::LogisticsOfficer]);

        $data = $request->validate(['grn_number' => 'required|string|max:100']);

        try {
            $this->shipping->issueGrn($purchaseOrder, Auth::user(), $data['grn_number']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['shipping' => $e->getMessage()]);
        }

        return back()->with('status', 'GRN issued.');
    }

    public function markServiceableLabelApplied(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::StoreStaff, Role::StoreSupervisor]);

        $this->shipping->markServiceableLabelApplied($purchaseOrder, Auth::user());

        return back()->with('status', 'Serviceable label recorded.');
    }

    public function markCardexSent(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $this->shipping->markCardexSent($purchaseOrder, Auth::user());

        return back()->with('status', 'Cardex marked as sent.');
    }

    public function closeOut(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        try {
            $this->shipping->closeOut($purchaseOrder, Auth::user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['shipping' => $e->getMessage()]);
        }

        return back()->with('status', 'Closed and handed back to DigiMaint.');
    }

    public function closeFulfilledFromStock(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $this->shipping->closeFulfilledFromStock($purchaseOrder, Auth::user());

        return back()->with('status', 'Closed and handed back to DigiMaint.');
    }

    /** @param list<Role> $roles */
    private function authorizeRole(array $roles): void
    {
        $user = Auth::user();
        abort_unless($user->role->isAdmin() || in_array($user->role, $roles, true), 403);
    }
}

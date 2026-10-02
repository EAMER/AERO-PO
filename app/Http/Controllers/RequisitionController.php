<?php

namespace App\Http\Controllers;

use App\Contracts\StockChecker;
use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Services\DocumentChecklistService;
use App\Services\RequisitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequisitionController extends Controller
{
    public function __construct(
        private RequisitionService $requisitions,
        private DocumentChecklistService $checklist,
    ) {}

    public function index()
    {
        $pos = PurchaseOrder::with('lines')
            ->latest()
            ->paginate(15);

        return view('requisitions.index', ['pos' => $pos]);
    }

    public function create()
    {
        $this->authorizeRole([Role::Requester, Role::LogisticsOfficer]);

        return view('requisitions.create');
    }

    public function store(Request $request)
    {
        $this->authorizeRole([Role::Requester, Role::LogisticsOfficer]);

        $data = $request->validate([
            'reference' => 'required|string|max:100',
            'is_amo_request' => 'sometimes|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.part_number' => 'required|string|max:100',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|integer|min:1',
            'lines.*.condition' => 'nullable|string|max:50',
        ]);

        $po = $this->requisitions->raise(
            $data['reference'],
            Auth::user(),
            $data['lines'],
            $data['is_amo_request'] ?? false,
        );

        return redirect()->route('requisitions.show', $po)->with('status', 'Requisition raised.');
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load([
            'lines', 'documents.uploader', 'logs.actor',
            'approvals.decidedBy', 'approvals.queries.asker', 'approvals.queries.target',
        ]);

        $checklist = $this->checklist->statusFor($purchaseOrder, 'evaluation_pack');
        $user = Auth::user();

        // Which approvals can the current user act on right now (their turn, their role)?
        $actionable = [];
        $targetsByApproval = [];
        foreach ($purchaseOrder->approvals->groupBy('stage') as $stageApprovals) {
            $earlierPending = false;
            foreach ($stageApprovals->sortBy('step_order') as $approval) {
                $isPending = $approval->decision === \App\Enums\ApprovalDecision::Pending;

                $actionable[$approval->id] = $isPending
                    && ! $earlierPending
                    && ($user->role->isAdmin() || $user->role === $approval->role)
                    && ! $approval->hasOpenQuery();

                if ($actionable[$approval->id]) {
                    $chainRoles = $stageApprovals->pluck('role')->unique();
                    $targetsByApproval[$approval->id] = User::all()
                        ->filter(fn ($u) => $chainRoles->contains($u->role) && $u->id !== $user->id)
                        ->values();
                }

                if ($isPending) {
                    $earlierPending = true;
                }
            }
        }

        return view('requisitions.show', [
            'po' => $purchaseOrder,
            'checklist' => $checklist,
            'actionable' => $actionable,
            'targetsByApproval' => $targetsByApproval,
        ]);
    }

    public function checkStock(PurchaseOrder $purchaseOrder, StockChecker $checker)
    {
        $this->authorizeRole([Role::StoreStaff, Role::StoreSupervisor]);

        $this->requisitions->checkStock($purchaseOrder, Auth::user(), $checker);

        return back()->with('status', 'Stock checked.');
    }

    public function markRfqSent(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $this->requisitions->markRfqSent($purchaseOrder, Auth::user());

        return back()->with('status', 'Marked as RFQs sent.');
    }

    public function markQuotesIn(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        $this->requisitions->markQuotesIn($purchaseOrder, Auth::user());

        return back()->with('status', 'Marked as quotations received.');
    }

    public function submit(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeRole([Role::LogisticsOfficer]);

        try {
            $this->requisitions->submitForApproval($purchaseOrder, Auth::user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['checklist' => $e->getMessage()]);
        }

        return back()->with('status', 'Submitted for approval.');
    }

    /** @param list<Role> $roles */
    private function authorizeRole(array $roles): void
    {
        $user = Auth::user();
        abort_unless($user->role->isAdmin() || in_array($user->role, $roles, true), 403);
    }
}

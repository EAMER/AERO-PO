<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus as Status;
use App\Enums\Role;
use App\Models\DigimaintHandover;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function index(): View
    {
        $user = Auth::user();

        $actionable = $this->approvals->actionableFor($user);
        $openQueries = $this->approvals->openQueriesFor($user);

        // Requesters see their own POs; everyone coordinating the process sees everything.
        $orders = PurchaseOrder::query()
            ->when(
                $user->role === Role::Requester,
                fn ($q) => $q->where('requested_by', $user->id)
            );
        $counts = (clone $orders)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $counts->sum();
        $closed = (int) ($counts[Status::Closed->value] ?? 0);
        $terminal = collect(Status::cases())->filter(fn (Status $status) => $status->isTerminal())->sum(fn (Status $status) => (int) ($counts[$status->value] ?? 0));
        $pipeline = collect([
            'Stores & stock check' => [Status::RequisitionRaised, Status::StockChecked, Status::FulfilledFromStock],
            'Sourcing & evaluation' => [Status::RfqSent, Status::QuotesIn, Status::ReturnedEvaluation, Status::ReturnedCfo],
            'Approvals & payment' => [Status::PendingApproval, Status::Approved, Status::PendingCfo, Status::PoApproved, Status::PoSent, Status::Acknowledged, Status::AwaitingPayment],
            'Shipping & delivery' => [Status::ReadyToShip, Status::InTransit, Status::Delivered],
            'Receipt & close-out' => [Status::Received, Status::GrnIssued],
        ])->map(fn (array $statuses) => collect($statuses)->sum(fn (Status $status) => (int) ($counts[$status->value] ?? 0)));
        $recentPos = (clone $orders)->with(['lines', 'requester'])->latest()
            ->limit(10)
            ->get();

        return view('dashboard', [
            'actionable' => $actionable,
            'openQueries' => $openQueries,
            'recentPos' => $recentPos,
            'overview' => ['total' => $total, 'active' => $total - $terminal, 'in_transit' => (int) ($counts[Status::InTransit->value] ?? 0), 'closed' => $closed],
            'pipeline' => $pipeline,
            'paymentsToConfirm' => ($user->role->isAdmin() || $user->role === Role::Treasury) ? Invoice::with('purchaseOrder')->where('status', 'approved')->latest()->get() : collect(),
            'handoversToVerify' => ($user->role->isAdmin() || $user->role === Role::StoreSupervisor) ? DigimaintHandover::with('purchaseOrder')->where('status', 'recorded')->where('recorded_by', '!=', $user->id)->latest()->get() : collect(),
        ]);
    }
}

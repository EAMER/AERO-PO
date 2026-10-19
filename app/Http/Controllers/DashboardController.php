<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function index()
    {
        $user = Auth::user();

        $actionable = $this->approvals->actionableFor($user);
        $openQueries = $this->approvals->openQueriesFor($user);

        // Requesters see their own POs; everyone coordinating the process sees everything.
        $recentPos = PurchaseOrder::query()
            ->when(
                $user->role === Role::Requester,
                fn ($q) => $q->where('requested_by', $user->id)
            )
            ->latest()
            ->limit(10)
            ->get();

        return view('dashboard', [
            'actionable' => $actionable,
            'openQueries' => $openQueries,
            'recentPos' => $recentPos,
        ]);
    }
}

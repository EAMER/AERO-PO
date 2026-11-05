<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\DigimaintHandover;
use App\Services\ReportService;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index()
    {
        // Requesters and Stores work individual requisitions; process-level metrics are for everyone else.
        abort_if(in_array(Auth::user()->role, [Role::Requester, Role::StoreStaff, Role::StoreSupervisor], true), 403);

        $counts = $this->reports->statusCounts();

        return view('reports.index', [
            'counts' => $counts,
            'total' => array_sum($counts),
            'averageCycleDays' => $this->reports->averageCycleDays(),
            'stages' => $this->reports->averageDaysPerStage(),
            'longestWaiting' => $this->reports->longestWaiting(),
            'stageTimers' => $this->reports->stageTimers(),
            'approvalTimers' => $this->reports->approvalTimers(),
            'kpis' => $this->reports->kpis(),
            'pendingHandovers' => DigimaintHandover::with('purchaseOrder')->whereIn('status', ['recorded', 'exception'])->latest()->limit(50)->get(),
        ]);
    }
}

<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus as S;
use App\Models\PoStatusLog;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * Process metrics derived purely from data already captured (PO creation
 * time and the po_status_logs audit trail). No targets or thresholds - the
 * TLM hasn't defined KPIs yet, so this reports what happened, not whether
 * it was good. Computed in PHP, which is fine at modest volume; if POs reach
 * the tens of thousands, move these into SQL aggregates.
 */
class ReportService
{
    /** @return array<string, int> status value => number of POs */
    public function statusCounts(): array
    {
        return PurchaseOrder::all()
            ->groupBy(fn (PurchaseOrder $po) => $po->status->value)
            ->map->count()
            ->all();
    }

    /** Average days from raised to closed, across closed POs. Null if none closed yet. */
    public function averageCycleDays(): ?float
    {
        $closed = PurchaseOrder::where('status', S::Closed)
            ->with(['logs' => fn ($q) => $q->where('to_status', S::Closed->value)])
            ->get();

        $days = $closed->map(function (PurchaseOrder $po) {
            $closeLog = $po->logs->sortByDesc('id')->first();

            return $closeLog
                ? ($closeLog->created_at->getTimestamp() - $po->created_at->getTimestamp()) / 86400
                : null;
        })->filter(fn ($d) => $d !== null);

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }

    /**
     * Average days a PO spent in each status before moving on, in process order.
     *
     * @return list<array{status: S, avg_days: float, samples: int}>
     */
    public function averageDaysPerStage(): array
    {
        $pos = PurchaseOrder::all()->keyBy('id');
        $logsByPo = PoStatusLog::orderBy('purchase_order_id')->orderBy('id')->get()->groupBy('purchase_order_id');

        $secondsByStage = [];

        foreach ($logsByPo as $poId => $logs) {
            if (! isset($pos[$poId])) {
                continue;
            }

            $previous = $pos[$poId]->created_at->getTimestamp();

            foreach ($logs as $log) {
                $now = $log->created_at->getTimestamp();
                $secondsByStage[$log->from_status->value][] = max(0, $now - $previous);
                $previous = $now;
            }
        }

        $result = [];
        foreach (S::cases() as $status) {
            $samples = $secondsByStage[$status->value] ?? [];
            if ($samples === []) {
                continue;
            }

            $result[] = [
                'status' => $status,
                'avg_days' => round(array_sum($samples) / count($samples) / 86400, 1),
                'samples' => count($samples),
            ];
        }

        return $result;
    }

    /**
     * Open (non-terminal) POs that have been in their current status the longest.
     *
     * @return Collection<int, array{po: PurchaseOrder, days: float}>
     */
    public function longestWaiting(int $limit = 5): Collection
    {
        return PurchaseOrder::with('logs')->get()
            ->reject(fn (PurchaseOrder $po) => $po->status->isTerminal())
            ->map(function (PurchaseOrder $po) {
                $since = $po->logs->sortByDesc('id')->first()?->created_at ?? $po->created_at;

                return [
                    'po' => $po,
                    'days' => round((now()->getTimestamp() - $since->getTimestamp()) / 86400, 1),
                ];
            })
            ->sortByDesc('days')
            ->take($limit)
            ->values();
    }
}

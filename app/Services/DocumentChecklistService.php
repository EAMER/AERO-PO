<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Support\RequiredDocuments;

class DocumentChecklistService
{
    /** @return array<int, array{type: string, required: int, have: int, met: bool}> */
    public function statusFor(PurchaseOrder $po, string $stage): array
    {
        $required = RequiredDocuments::forStage($stage);
        $counts = $po->documents()
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type');

        $rows = [];
        foreach ($required as $type => $min) {
            $have = (int) ($counts[$type] ?? 0);
            $rows[] = ['type' => $type, 'required' => $min, 'have' => $have, 'met' => $have >= $min];
        }

        return $rows;
    }

    public function isComplete(PurchaseOrder $po, string $stage): bool
    {
        return collect($this->statusFor($po, $stage))->every(fn ($row) => $row['met']);
    }

    /** @return list<string> DocumentType values still missing or short */
    public function missing(PurchaseOrder $po, string $stage): array
    {
        return collect($this->statusFor($po, $stage))
            ->reject(fn ($row) => $row['met'])
            ->map(fn ($row) => "{$row['type']} ({$row['have']}/{$row['required']})")
            ->values()->all();
    }
}

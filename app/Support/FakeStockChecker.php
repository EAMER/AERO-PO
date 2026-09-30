<?php

namespace App\Support;

use App\Contracts\StockChecker;
use App\Models\PoLine;

/**
 * Deterministic stand-in for DigiMaint. A part number containing "INSTOCK"
 * (case-insensitive) is treated as available; everything else is short.
 * Swap the App\Contracts\StockChecker binding in AppServiceProvider once the
 * real DigiMaint integration exists.
 */
class FakeStockChecker implements StockChecker
{
    public function isAvailable(PoLine $line): bool
    {
        return str_contains(strtoupper($line->part_number), 'INSTOCK');
    }
}

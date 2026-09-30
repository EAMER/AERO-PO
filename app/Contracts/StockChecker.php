<?php

namespace App\Contracts;

use App\Models\PoLine;

/**
 * Checks DigiMaint for stock availability (manual 7.1.1). The real
 * implementation calls DigiMaint (API, DB, or export - TBD); FakeStockChecker
 * stands in until that integration is built.
 */
interface StockChecker
{
    public function isAvailable(PoLine $line): bool;
}

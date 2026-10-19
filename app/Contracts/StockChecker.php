<?php

namespace App\Contracts;

use App\Models\PoLine;

/**
 * Checks DigiMaint for stock availability (manual 7.1.1).
 *
 * NOT CURRENTLY USED: the stock check is done by a human reading DigiMaint
 * and ticking a box in the app (see RequisitionController::checkStock and
 * RequisitionService::checkStock). This interface and FakeStockChecker are
 * left in place in case a real API/automated integration gets built later -
 * at that point, wire an implementation of this interface back into the
 * controller instead of the manual checkbox form.
 */
interface StockChecker
{
    public function isAvailable(PoLine $line): bool;
}

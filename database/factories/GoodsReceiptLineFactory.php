<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PoLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsReceiptLine> */
class GoodsReceiptLineFactory extends Factory
{
    protected $model = GoodsReceiptLine::class;

    public function definition(): array
    {
        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'po_line_id' => fn (array $a) => PoLine::factory()->create(['purchase_order_id' => GoodsReceipt::findOrFail($a['goods_receipt_id'])->purchase_order_id])->id,
            'quantity' => 2,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\PoLine;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuoteLine> */
class QuoteLineFactory extends Factory
{
    protected $model = QuoteLine::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'po_line_id' => fn (array $a) => PoLine::factory()->create(['purchase_order_id' => Quote::findOrFail($a['quote_id'])->purchase_order_id])->id,
            'unit_price_minor' => 1000,
            'condition' => 'new',
            'warranty' => '12 months',
        ];
    }
}

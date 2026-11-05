<?php

namespace Database\Factories;

use App\Models\PoLine;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PoLine> */
class PoLineFactory extends Factory
{
    protected $model = PoLine::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'part_number' => fake()->bothify('PN-####'),
            'description' => fake()->words(3, true),
            'quantity' => 2,
            'condition' => 'new',
            'unit_price_minor' => 1000,
            'warranty' => '12 months',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rfq> */
class RfqFactory extends Factory
{
    protected $model = Rfq::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'vendor_id' => Vendor::factory()->approved(),
            'reference' => fake()->unique()->bothify('RFQ-####-????'),
            'kind' => 'parts',
            'status' => 'queued',
            'revision' => 1,
        ];
    }
}

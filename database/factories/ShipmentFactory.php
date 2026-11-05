<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shipment> */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'route' => 'courier',
            'weight_kg' => 10,
            'contains_dg' => false,
            'status' => 'draft',
            'revision' => 1,
        ];
    }
}

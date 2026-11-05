<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsReceipt> */
class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'grn_number' => fake()->unique()->bothify('GRN-####-????'),
            'received_by' => User::factory()->state(['role' => Role::StoreStaff]),
            'received_at' => now(),
        ];
    }
}

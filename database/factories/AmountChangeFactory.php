<?php

namespace Database\Factories;

use App\Models\AmountChange;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AmountChange> */
class AmountChangeFactory extends Factory
{
    protected $model = AmountChange::class;

    public function definition(): array
    {
        return [
            'subject_type' => PurchaseOrder::class,
            'subject_id' => PurchaseOrder::factory(),
            'purchase_order_id' => fn (array $a) => $a['subject_id'],
            'actor_id' => User::factory(),
            'action' => 'po.commercial_terms',
            'before' => ['total_minor' => 1000],
            'after' => ['total_minor' => 2000],
            'reason' => 'Vendor revised price',
        ];
    }
}

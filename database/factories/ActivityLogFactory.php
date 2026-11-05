<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActivityLog> */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'subject_type' => PurchaseOrder::class,
            'subject_id' => PurchaseOrder::factory(),
            'purchase_order_id' => fn (array $a) => $a['subject_id'],
            'actor_id' => User::factory(),
            'action' => 'requisition.raised',
            'before' => [],
            'after' => [],
        ];
    }
}

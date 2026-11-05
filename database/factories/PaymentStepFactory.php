<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Approval;
use App\Models\Invoice;
use App\Models\PaymentStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentStep> */
class PaymentStepFactory extends Factory
{
    protected $model = PaymentStep::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'approval_id' => fn (array $a) => Approval::create(['approvable_type' => Invoice::class, 'approvable_id' => $a['invoice_id'], 'stage' => 'payment_cia', 'step_order' => 1, 'round' => 1, 'role' => Role::CostControl, 'decision' => 'pending'])->id,
            'role' => Role::CostControl,
            'step_order' => 1,
            'round' => 1,
            'decision' => 'pending',
        ];
    }
}

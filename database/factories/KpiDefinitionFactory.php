<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KpiDefinition> */
class KpiDefinitionFactory extends Factory
{
    protected $model = KpiDefinition::class;

    public function definition(): array
    {
        return [
            'name' => 'Closed orders',
            'metric' => 'closed_orders',
            'target' => 5,
            'direction' => 'at_least',
            'defined_by' => User::factory()->state(['role' => Role::Tlm]),
        ];
    }
}

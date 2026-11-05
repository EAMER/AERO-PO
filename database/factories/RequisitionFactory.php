<?php

namespace Database\Factories;

use App\Models\Requisition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Requisition> */
class RequisitionFactory extends Factory
{
    protected $model = Requisition::class;

    public function definition(): array
    {
        return [
            'reference' => fake()->unique()->bothify('PR-####-????'),
            'requested_by' => User::factory(),
            'is_amo_request' => false,
        ];
    }
}

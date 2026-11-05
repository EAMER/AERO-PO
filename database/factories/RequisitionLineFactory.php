<?php

namespace Database\Factories;

use App\Models\Requisition;
use App\Models\RequisitionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequisitionLine> */
class RequisitionLineFactory extends Factory
{
    protected $model = RequisitionLine::class;

    public function definition(): array
    {
        return [
            'requisition_id' => Requisition::factory(),
            'part_number' => fake()->bothify('PN-####'),
            'description' => fake()->words(3, true),
            'quantity' => 2,
            'condition' => 'new',
        ];
    }
}

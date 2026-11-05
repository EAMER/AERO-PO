<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuoteEvaluation> */
class QuoteEvaluationFactory extends Factory
{
    protected $model = QuoteEvaluation::class;

    public function definition(): array
    {
        return [
            'selected_quote_id' => Quote::factory(),
            'purchase_order_id' => fn (array $a) => Quote::findOrFail($a['selected_quote_id'])->purchase_order_id,
            'revision' => 1,
            'justification' => 'Selected after commercial and technical comparison',
            'comparison_snapshot' => [],
            'status' => 'draft',
        ];
    }
}

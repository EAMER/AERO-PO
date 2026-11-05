<?php

namespace Database\Factories;

use App\Models\PoDocument;
use App\Models\Quote;
use App\Models\Rfq;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'rfq_id' => Rfq::factory(),
            'purchase_order_id' => fn (array $a) => Rfq::findOrFail($a['rfq_id'])->purchase_order_id,
            'vendor_id' => fn (array $a) => Rfq::findOrFail($a['rfq_id'])->vendor_id,
            'po_document_id' => fn (array $a) => PoDocument::factory()->create(['purchase_order_id' => $a['purchase_order_id']])->id,
            'reference' => fake()->unique()->bothify('Q-####-????'),
            'currency' => 'USD',
            'payment_terms' => 'net',
            'total_minor' => 2000,
        ];
    }
}

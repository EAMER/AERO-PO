<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PoDocument> */
class PoDocumentFactory extends Factory
{
    protected $model = PoDocument::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'type' => DocumentType::Quotation,
            'disk_path' => 'fixtures/'.fake()->uuid().'.pdf',
            'original_filename' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ];
    }
}

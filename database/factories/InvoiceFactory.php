<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Invoice;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'po_document_id' => fn (array $a) => PoDocument::factory()->create(['purchase_order_id' => $a['purchase_order_id'], 'type' => DocumentType::ProformaInvoice])->id,
            'reference' => fake()->unique()->bothify('INV-####-????'),
            'currency' => 'USD',
            'amount_minor' => 2000,
            'payment_terms' => 'cia',
            'status' => 'draft',
            'revision' => 1,
        ];
    }
}

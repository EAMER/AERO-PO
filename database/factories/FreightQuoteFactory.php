<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\FreightQuote;
use App\Models\PoDocument;
use App\Models\Rfq;
use App\Models\Shipment;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FreightQuote> */
class FreightQuoteFactory extends Factory
{
    protected $model = FreightQuote::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'vendor_id' => Vendor::factory()->approved()->state(['kind' => 'freight_forwarder']),
            'rfq_id' => fn (array $a) => Rfq::factory()->create(['shipment_id' => $a['shipment_id'], 'purchase_order_id' => Shipment::findOrFail($a['shipment_id'])->purchase_order_id, 'vendor_id' => $a['vendor_id'], 'kind' => 'freight'])->id,
            'po_document_id' => fn (array $a) => PoDocument::factory()->create(['purchase_order_id' => Shipment::findOrFail($a['shipment_id'])->purchase_order_id, 'type' => DocumentType::FreightQuotation])->id,
            'reference' => fake()->unique()->bothify('FQ-####-????'),
            'currency' => 'USD',
            'amount_minor' => 5000,
        ];
    }
}

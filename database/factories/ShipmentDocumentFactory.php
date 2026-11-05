<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\PoDocument;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShipmentDocument> */
class ShipmentDocumentFactory extends Factory
{
    protected $model = ShipmentDocument::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'po_document_id' => fn (array $a) => PoDocument::factory()->create(['purchase_order_id' => Shipment::findOrFail($a['shipment_id'])->purchase_order_id, 'type' => DocumentType::AirwayBill])->id,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\Role;
use App\Models\DigimaintHandover;
use App\Models\PoDocument;
use App\Models\PoLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DigimaintHandover> */
class DigimaintHandoverFactory extends Factory
{
    protected $model = DigimaintHandover::class;

    public function definition(): array
    {
        return [
            'po_line_id' => PoLine::factory(),
            'purchase_order_id' => fn (array $a) => PoLine::findOrFail($a['po_line_id'])->purchase_order_id,
            'activity' => 'stock_check',
            'transaction_reference' => fake()->uuid(),
            'po_document_id' => fn (array $a) => PoDocument::factory()->create(['purchase_order_id' => $a['purchase_order_id'], 'type' => DocumentType::DigimaintEvidence])->id,
            'quantity' => 0,
            'recorded_by' => User::factory()->state(['role' => Role::StoreStaff]),
            'performed_at' => now(),
            'status' => 'recorded',
        ];
    }
}

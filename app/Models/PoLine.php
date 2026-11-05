<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoLine extends Model
{
    use BelongsToTenant, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'tenant_id', 'purchase_order_id', 'part_number', 'description',
        'quantity', 'condition', 'stock_available', 'stock_checked_at',
        'requisition_line_id', 'unit_price_minor', 'warranty',
    ];

    protected $casts = [
        'stock_available' => 'boolean',
        'stock_checked_at' => 'datetime',
        'quantity' => 'integer',
        'unit_price_minor' => 'integer',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_order_id', 'part_number', 'description',
        'quantity', 'condition', 'stock_available', 'stock_checked_at',
    ];

    protected $casts = [
        'stock_available' => 'boolean',
        'stock_checked_at' => 'datetime',
        'quantity' => 'integer',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}

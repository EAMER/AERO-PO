<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Rfq extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'shipment_id', 'vendor_id', 'kind', 'revision', 'reference', 'status', 'sent_at', 'last_error'];

    protected $casts = ['revision' => 'integer', 'sent_at' => 'datetime'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function quote(): HasOne
    {
        return $this->hasOne(Quote::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Shipment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'route', 'weight_kg', 'contains_dg', 'carrier_id', 'status', 'revision', 'freight_minor', 'currency', 'awb_number', 'customs_duty_paid', 'selected_quote_id'];

    protected $casts = ['weight_kg' => 'decimal:2', 'contains_dg' => 'boolean', 'customs_duty_paid' => 'boolean', 'revision' => 'integer', 'freight_minor' => 'integer'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'carrier_id');
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(FreightQuote::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class);
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}

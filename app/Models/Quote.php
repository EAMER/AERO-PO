<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'rfq_id', 'vendor_id', 'po_document_id', 'reference', 'currency', 'payment_terms', 'total_minor', 'valid_until'];

    protected $casts = ['total_minor' => 'integer', 'valid_until' => 'date'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PoDocument::class, 'po_document_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }
}

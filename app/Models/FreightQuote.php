<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreightQuote extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'shipment_id', 'rfq_id', 'vendor_id', 'po_document_id', 'reference', 'currency', 'amount_minor'];

    protected $casts = ['amount_minor' => 'integer'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
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
}

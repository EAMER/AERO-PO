<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentDocument extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'shipment_id', 'po_document_id'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PoDocument::class, 'po_document_id');
    }
}

<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata for a file on the private disk; App\Services\DocumentService owns writes. */
class PoDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_order_id', 'type', 'disk_path',
        'original_filename', 'mime_type', 'size', 'uploaded_by',
    ];

    protected $casts = ['type' => DocumentType::class];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}

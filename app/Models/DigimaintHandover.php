<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigimaintHandover extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'goods_receipt_id', 'po_line_id', 'activity', 'transaction_reference', 'po_document_id', 'quantity', 'recorded_by', 'performed_at', 'status', 'verified_by', 'verified_at', 'note', 'supersedes_id', 'dedupe_key'];

    protected $casts = ['quantity' => 'integer', 'performed_at' => 'datetime', 'verified_at' => 'datetime'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PoDocument::class, 'po_document_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

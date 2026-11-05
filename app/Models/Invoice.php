<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'po_document_id', 'reference', 'currency', 'amount_minor', 'payment_terms', 'status', 'revision', 'paid_at', 'payment_reference', 'paid_by'];

    protected $casts = ['amount_minor' => 'integer', 'revision' => 'integer', 'paid_at' => 'datetime'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PoDocument::class, 'po_document_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(PaymentStep::class);
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}

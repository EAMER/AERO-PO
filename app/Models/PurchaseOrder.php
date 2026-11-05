<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Foundation-only shape. Person 2 extends it (lines, vendor, totals);
 * `status` is changed ONLY through WorkflowService::transition().
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'reference', 'requested_by', 'is_amo_request',
        'payment_terms', 'total_weight_kg', 'contains_dg',
        'awb_number', 'customs_duty_paid', 'grn_number',
        'serviceable_label_applied_at', 'cardex_sent_at', 'digimaint_synced_at',
        'requisition_id', 'vendor_id', 'revision', 'currency', 'total_minor',
        'commercial_snapshot', 'po_approved_at', 'pdf_path', 'dispatch_queued_at', 'dispatch_started_at', 'issued_at',
    ];

    protected $casts = [
        'status' => PurchaseOrderStatus::class,
        'is_amo_request' => 'boolean',
        'contains_dg' => 'boolean',
        'total_weight_kg' => 'decimal:2',
        'customs_duty_paid' => 'boolean',
        'serviceable_label_applied_at' => 'datetime',
        'cardex_sent_at' => 'datetime',
        'digimaint_synced_at' => 'datetime',
        'commercial_snapshot' => 'array', 'revision' => 'integer', 'total_minor' => 'integer',
        'po_approved_at' => 'datetime', 'dispatch_queued_at' => 'datetime', 'issued_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'requisition_raised'];

    public function logs(): HasMany
    {
        return $this->hasMany(PoStatusLog::class);
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PoLine::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PoDocument::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(QuoteEvaluation::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(DigimaintHandover::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}

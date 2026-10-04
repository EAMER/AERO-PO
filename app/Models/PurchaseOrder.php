<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Foundation-only shape. Person 2 extends it (lines, vendor, totals);
 * `status` is changed ONLY through WorkflowService::transition().
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'reference', 'requested_by', 'is_amo_request',
        'payment_terms', 'total_weight_kg', 'contains_dg',
        'awb_number', 'customs_duty_paid', 'grn_number',
        'serviceable_label_applied_at', 'cardex_sent_at', 'digimaint_synced_at',
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
    ];

    protected $attributes = ['status' => 'requisition_raised'];

    public function logs(): HasMany { return $this->hasMany(PoStatusLog::class); }

    public function approvals(): MorphMany { return $this->morphMany(Approval::class, 'approvable'); }

    public function lines(): HasMany { return $this->hasMany(PoLine::class); }

    public function documents(): HasMany { return $this->hasMany(PoDocument::class); }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
}

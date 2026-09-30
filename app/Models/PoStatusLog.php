<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable audit trail. */
class PoStatusLog extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'from_status', 'to_status', 'actor_id', 'note'];

    protected $casts = [
        'from_status' => PurchaseOrderStatus::class,
        'to_status' => PurchaseOrderStatus::class,
    ];

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('PoStatusLog is immutable.'));
        static::deleting(fn () => throw new \LogicException('PoStatusLog is immutable.'));
    }
}

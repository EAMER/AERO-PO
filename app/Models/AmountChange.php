<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmountChange extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'subject_type', 'subject_id', 'purchase_order_id', 'actor_id', 'action', 'before', 'after', 'reason'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('AmountChange records are immutable.'));
        static::deleting(fn () => throw new \LogicException('AmountChange records are immutable.'));
    }
}

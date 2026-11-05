<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionLine extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'requisition_id', 'part_number', 'description', 'quantity', 'condition'];

    protected $casts = ['quantity' => 'integer'];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }
}

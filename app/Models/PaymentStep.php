<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentStep extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'invoice_id', 'approval_id', 'role', 'step_order', 'round', 'decision'];

    protected $casts = ['role' => Role::class, 'decision' => ApprovalDecision::class, 'round' => 'integer'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A question an approver puts to another person in the chain, before deciding. */
class ApprovalQuery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'approval_id', 'asked_by', 'directed_to', 'question',
        'answer', 'answered_by', 'answered_at',
    ];

    protected $casts = ['answered_at' => 'datetime'];

    public function approval(): BelongsTo { return $this->belongsTo(Approval::class); }
    public function asker(): BelongsTo { return $this->belongsTo(User::class, 'asked_by'); }
    public function target(): BelongsTo { return $this->belongsTo(User::class, 'directed_to'); }

    public function isOpen(): bool { return $this->answered_at === null; }
}

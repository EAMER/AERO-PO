<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'approvable_type', 'approvable_id', 'stage', 'step_order',
        'role', 'decision', 'decided_by', 'decided_at', 'note',
    ];

    protected $casts = [
        'role' => Role::class,
        'decision' => ApprovalDecision::class,
        'decided_at' => 'datetime',
    ];

    public function approvable(): MorphTo { return $this->morphTo(); }

    public function decidedBy(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    public function queries(): HasMany { return $this->hasMany(ApprovalQuery::class); }

    public function hasOpenQuery(): bool
    {
        return $this->queries()->whereNull('answered_at')->exists();
    }
}

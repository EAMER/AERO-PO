<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteLine extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'quote_id', 'po_line_id', 'unit_price_minor', 'condition', 'warranty'];

    protected $casts = ['unit_price_minor' => 'integer'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'email', 'kind', 'payment_terms', 'address', 'account_reference', 'is_approved', 'is_active'];

    protected $casts = ['is_approved' => 'boolean', 'is_active' => 'boolean'];

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }
}

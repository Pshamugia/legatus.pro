<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceAttributionOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'converted_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(CommerceAttribution::class, 'commerce_attribution_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMerchandisingRule extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function isActive(): bool
    {
        return (! $this->starts_at || $this->starts_at->lte(now()))
            && (! $this->ends_at || $this->ends_at->gte(now()));
    }
}

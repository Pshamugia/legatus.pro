<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'decimal:2', 'metadata' => 'array', 'is_active' => 'boolean'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

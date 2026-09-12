<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReelDelivery extends Model
{
    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime'];

    public function reel(): BelongsTo
    {
        return $this->belongsTo(AiReel::class, 'ai_reel_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiReelSchedule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'categories' => 'array',
        'languages' => 'array',
        'providers' => 'array',
        'posting_times' => 'array',
        'paused_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function reels(): HasMany
    {
        return $this->hasMany(AiReel::class);
    }
}

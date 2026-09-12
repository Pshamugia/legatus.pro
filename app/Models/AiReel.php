<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiReel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'providers' => 'array',
        'scheduled_for' => 'datetime',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
        'credit_refunded_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AiReelSchedule::class, 'ai_reel_schedule_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AiReelDelivery::class);
    }

    public function publicVideoUrl(): ?string
    {
        return $this->video_path ? route('ai-reels.media', ['filename' => basename($this->video_path)]) : null;
    }
}

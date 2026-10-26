<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiReel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'providers' => 'array',
        'duration_seconds' => 'integer',
        'credit_cost' => 'integer',
        'video_duration_ms' => 'integer',
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

    public function visibleFailureReason(): string
    {
        $reason = trim((string) $this->last_error);
        if ($reason === '') {
            return 'The generation stopped without a detailed error. Please contact support with this Reel ID.';
        }

        if (str_contains(Str::lower($reason), 'you do not have enough credits to run this task')) {
            return 'Video generation was unavailable on our side. Buying more Reel credits will not resolve this.'
                . ($this->credit_refunded_at ? ' Your Reel credits were returned.' : ' If credits were charged, please contact support with this Reel ID.');
        }

        // Provider and HTTP exception messages can contain signed URLs or credentials.
        $reason = preg_replace('/https?:\/\/[^\s)]+/iu', '[URL hidden]', $reason);
        $reason = preg_replace('/\bBearer\s+[^\s,;]+/iu', 'Bearer [hidden]', $reason);
        $reason = preg_replace('/\b(?:sk|rk)-[A-Za-z0-9_-]{8,}\b/u', '[key hidden]', $reason);
        $reason = preg_replace('/\b(api[_-]?key|access[_-]?token|secret|password)\s*[:=]\s*[^\s,;]+/iu', '$1=[hidden]', $reason);

        return Str::limit($reason, 350);
    }
}

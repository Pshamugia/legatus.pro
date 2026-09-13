<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMediaPost extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'published_at' => 'datetime',
        'provider_updated_at' => 'datetime',
        'provider_deleted_at' => 'datetime',
        'story_published_at' => 'datetime',
        'ai_generation_attempted_at' => 'datetime',
        'ai_generated_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SocialMediaSchedule::class, 'social_media_schedule_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function facebookPermalink(): ?string
    {
        if ($this->provider !== 'facebook' || blank($this->provider_post_id)) {
            return null;
        }

        $id = trim((string) $this->provider_post_id);
        if (str_contains($id, '_')) {
            [$pageId, $postId] = explode('_', $id, 2);

            return 'https://www.facebook.com/'.rawurlencode($pageId).'/posts/'.rawurlencode($postId);
        }

        return 'https://www.facebook.com/'.rawurlencode($id);
    }
}

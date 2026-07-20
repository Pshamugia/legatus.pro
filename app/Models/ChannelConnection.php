<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'metadata' => 'array',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_webhook_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function channelMessages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }

    /**
     * Facebook Login based Instagram messaging is sent and subscribed through
     * the linked Facebook Page, while webhook events can identify either the
     * Page or the Instagram professional account.
     */
    public function graphAccountId(): string
    {
        if ($this->provider === 'instagram') {
            $pageId = data_get($this->metadata, 'facebook_page_id');
            if (is_string($pageId) && $pageId !== '') {
                return $pageId;
            }
        }

        return $this->external_account_id;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $guarded = [];

    protected $casts = ['context' => 'array', 'last_message_at' => 'datetime', 'resolved_at' => 'datetime', 'outcome_value' => 'decimal:2'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function channelConnection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function channelMessages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class);
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }
}

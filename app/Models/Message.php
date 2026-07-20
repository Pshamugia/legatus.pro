<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Message extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'confidence' => 'float'];

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            $message->public_id ??= (string) Str::uuid();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function channelMessage(): HasOne
    {
        return $this->hasOne(ChannelMessage::class);
    }
}

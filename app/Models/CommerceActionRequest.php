<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommerceActionRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload', 'idempotency_key'];

    protected $casts = [
        'summary' => 'array',
        'payload' => 'encrypted:array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'executed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CommerceActionRequest $request): void {
            $request->public_id ??= (string) Str::uuid();
            $request->idempotency_key ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function commerceConnection(): BelongsTo
    {
        return $this->belongsTo(CommerceConnection::class);
    }

    public function requestedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'requested_message_id');
    }
}

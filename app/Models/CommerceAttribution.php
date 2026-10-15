<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommerceAttribution extends Model
{
    protected $guarded = [];

    protected $hidden = ['order_reference_hash'];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'converted_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (CommerceAttribution $attribution) => $attribution->token ??= (string) Str::uuid());
    }

    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function commerceConnection(): BelongsTo { return $this->belongsTo(CommerceConnection::class); }
}

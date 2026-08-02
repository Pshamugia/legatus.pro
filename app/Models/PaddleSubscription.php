<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaddleSubscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'scheduled_change_at' => 'datetime',
        'paddle_occurred_at' => 'datetime',
        'items' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function grantsAccess(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }
}

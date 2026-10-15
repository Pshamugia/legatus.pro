<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'settings' => 'array',
        'last_sync_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function actionRequests(): HasMany
    {
        return $this->hasMany(CommerceActionRequest::class);
    }
}

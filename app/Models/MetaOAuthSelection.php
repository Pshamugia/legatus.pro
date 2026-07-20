<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaOAuthSelection extends Model
{
    protected $table = 'meta_oauth_selections';

    protected $guarded = [];

    protected $hidden = ['selector_hash', 'candidates'];

    protected $casts = [
        'candidates' => 'encrypted:array',
        'expires_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

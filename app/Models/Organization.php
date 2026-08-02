<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function paddleSubscriptions(): HasMany
    {
        return $this->hasMany(PaddleSubscription::class);
    }

    public function currentSubscription(): ?PaddleSubscription
    {
        return $this->paddleSubscriptions()->latest('paddle_occurred_at')->first();
    }

    public function hasBillingAccess(): bool
    {
        return $this->currentSubscription()?->grantsAccess() ?? false;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecommendationEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['query' => 'array', 'ranked_products' => 'array'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}

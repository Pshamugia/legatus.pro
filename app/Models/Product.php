<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'decimal:2', 'metadata' => 'array', 'is_active' => 'boolean'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function publicImageUrl(): ?string
    {
        $url = $this->image ?: data_get($this->metadata, 'image');

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                ? $url
                : null;
    }
}

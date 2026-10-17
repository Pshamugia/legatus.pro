<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformHealthIncident extends Model
{
    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}

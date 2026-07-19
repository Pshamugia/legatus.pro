<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];

    protected $casts = ['consent_at' => 'datetime', 'retention_until' => 'datetime'];
}

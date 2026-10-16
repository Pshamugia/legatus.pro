<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceInstallation extends Model
{
    protected $guarded = [];

    protected $hidden = ['credentials', 'token_hash'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'expires_at' => 'datetime',
    ];
}

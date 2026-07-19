<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $guarded = [];

    protected $casts = ['tools_used' => 'array'];
}

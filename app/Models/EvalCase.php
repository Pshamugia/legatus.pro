<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvalCase extends Model
{
    protected $guarded = [];

    protected $casts = ['expected_tools' => 'array', 'assertions' => 'array', 'expected_handoff' => 'boolean', 'active' => 'boolean'];
}

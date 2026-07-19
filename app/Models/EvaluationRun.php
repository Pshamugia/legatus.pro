<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationRun extends Model
{
    protected $guarded = [];

    protected $casts = ['results' => 'array'];
}

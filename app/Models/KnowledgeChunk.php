<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeChunk extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'embedding' => 'array'];
}
